<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Token_Manager
{
    private const WARNING_WINDOW_DAYS = 7;

    private Facebook_Client $facebook_client;

    public function __construct(Facebook_Client $facebook_client)
    {
        $this->facebook_client = $facebook_client;
    }

    public function hooks(): void
    {
        self::schedule_cron();
        add_action(CRON_HOOK_TOKEN_MAINTENANCE, [$this, 'run_maintenance']);
        add_action('admin_init', [$this, 'maybe_handle_manual_refresh']);
        add_action('admin_notices', [$this, 'render_admin_notice']);
    }

    public function run_maintenance(): void
    {
        $this->run_refresh_flow(false);
    }

    public function run_manual_refresh(): void
    {
        $this->run_refresh_flow(true);
    }

    private function run_refresh_flow(bool $force_refresh): void
    {
        $settings = get_settings();

        if (! $this->has_refresh_credentials($settings)) {
            $this->store_refresh_status('error', 'Automatic refresh is not configured yet. Fill in App ID, App Secret, long-lived user token, expiration date, and page ID.');
            return;
        }

        $days_remaining = get_token_days_remaining();

        if ($days_remaining === null) {
            $this->store_refresh_status('error', 'Facebook user token expiration is missing or invalid.');
            $this->send_warning_email_once(
                'Facebook Auto Publisher: token expiration missing',
                'The plugin could not determine the Facebook user token expiration date. Open Settings > Facebook Auto Publisher and reconnect the token.'
            );

            return;
        }

        if (! $force_refresh && $days_remaining > self::WARNING_WINDOW_DAYS) {
            $this->clear_warning_marker();
            return;
        }

        $refresh = $this->facebook_client->refresh_user_access_token(
            (string) $settings['app_id'],
            (string) $settings['app_secret'],
            (string) $settings['user_access_token']
        );

        if (! $refresh['success']) {
            $message = $this->extract_refresh_error_message($refresh['body'] ?? '');
            $this->store_refresh_status('error', $message);
            $this->send_warning_email_once(
                'Facebook Auto Publisher: token refresh failed',
                sprintf(
                    "The Facebook token refresh failed for page ID %s.\n\nDetails: %s\n\nOpen Settings > Facebook Auto Publisher and reconnect the account.",
                    (string) ($settings['page_id'] ?? ''),
                    $message
                )
            );

            return;
        }

        $user_token = (string) ($refresh['body']['access_token'] ?? '');
        $expires_in = (int) ($refresh['body']['expires_in'] ?? 0);

        if ($user_token === '') {
            $message = 'Refresh succeeded but the response did not include a valid user token.';
            $this->store_refresh_status('error', $message);
            $this->send_warning_email_once('Facebook Auto Publisher: invalid refresh response', $message);

            return;
        }

        $page_token = $this->facebook_client->get_page_access_token(
            $user_token,
            (string) $settings['page_id']
        );

        if (! $page_token['success']) {
            $message = $this->extract_refresh_error_message($page_token['body'] ?? '');
            $this->store_refresh_status('error', $message);
            $this->send_warning_email_once(
                'Facebook Auto Publisher: page token refresh failed',
                sprintf(
                    "The Facebook user token was refreshed, but the page token refresh failed for page ID %s.\n\nDetails: %s\n\nOpen Settings > Facebook Auto Publisher and reconnect the account.",
                    (string) ($settings['page_id'] ?? ''),
                    $message
                )
            );

            return;
        }

        $debug = $this->facebook_client->debug_token(
            (string) $settings['app_id'],
            (string) $settings['app_secret'],
            $user_token
        );

        $debug_status = 'warning';
        $debug_message = 'Token refreshed, but validity could not be confirmed.';
        $debug_expires_at = '';

        if (! $debug['success']) {
            $debug_message = 'Token refreshed, but Meta debug_token failed: ' . $this->extract_refresh_error_message($debug['body'] ?? '');
        } else {
            $debug_data = is_array($debug['body']) ? ($debug['body']['data'] ?? null) : null;

            if (is_array($debug_data) && ! empty($debug_data['is_valid'])) {
                $debug_status = 'success';
                $expires_at_unix = isset($debug_data['expires_at']) ? (int) $debug_data['expires_at'] : 0;

                if ($expires_at_unix > 0) {
                    $debug_expires_at = gmdate('Y-m-d H:i:s', $expires_at_unix);
                    $debug_message = sprintf('Token is valid according to Meta. Expires at %s UTC.', $debug_expires_at);
                } else {
                    $debug_message = 'Token is valid according to Meta, but Meta did not return an expiration timestamp.';
                }
            } else {
                $debug_status = 'error';
                $debug_message = 'Token refresh returned a token that Meta does not consider valid.';
            }
        }

        $existing_expires_at = (string) ($settings['user_token_expires_at'] ?? '');
        $expires_at = $debug_expires_at !== ''
            ? $debug_expires_at
            : ($expires_in > 0
            ? gmdate('Y-m-d H:i:s', time() + $expires_in)
            : $existing_expires_at);
        $updated_settings = $settings;
        $updated_settings['user_access_token'] = $user_token;
        $updated_settings['page_access_token'] = (string) ($page_token['page_access_token'] ?? '');
        $updated_settings['user_token_expires_at'] = $expires_at;
        $updated_settings['last_token_refresh_at'] = current_time('mysql');
        $updated_settings['last_token_refresh_status'] = 'success';
        $updated_settings['last_token_refresh_message'] = $expires_in > 0
            ? sprintf(
                $force_refresh
                    ? 'Token refreshed manually. New expiration: %s UTC.'
                    : 'Token refreshed automatically. New expiration: %s UTC.',
                $expires_at
            )
            : sprintf(
                $force_refresh
                    ? 'Token refreshed manually, but Meta did not return a new expiration. Keeping existing expiration: %s UTC.'
                    : 'Token refreshed automatically, but Meta did not return a new expiration. Keeping existing expiration: %s UTC.',
                $expires_at !== '' ? $expires_at : 'unknown'
            );
        $updated_settings['last_token_debug_at'] = current_time('mysql');
        $updated_settings['last_token_debug_status'] = $debug_status;
        $updated_settings['last_token_debug_message'] = $debug_message;
        $updated_settings['token_warning_sent_at'] = '';

        update_option(OPTION_KEY, $updated_settings);
    }

    public function maybe_handle_manual_refresh(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_GET['page']) || $_GET['page'] !== 'fb-page-autopublisher') {
            return;
        }

        if (! isset($_GET['fbap_refresh_token'])) {
            return;
        }

        check_admin_referer('fbap_refresh_token');
        $this->run_manual_refresh();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'fb-page-autopublisher',
                    'fbap_refresh_token' => null,
                    'fbap_refreshed' => '1',
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }

    public function render_admin_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || $screen->id !== 'settings_page_fb-page-autopublisher') {
            return;
        }

        $settings = get_settings();
        $days_remaining = get_token_days_remaining();
        $status = (string) ($settings['last_token_refresh_status'] ?? '');
        $message = trim((string) ($settings['last_token_refresh_message'] ?? ''));

        if (isset($_GET['fbap_refreshed']) && $_GET['fbap_refreshed'] === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Facebook token refresh attempted. See the status below.', 'fb-page-autopublisher') . '</p></div>';
        }

        if ($days_remaining === null) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Facebook user token expiration is not configured. Automatic refresh cannot be verified.', 'fb-page-autopublisher') . '</p></div>';
        } elseif ($days_remaining < 0) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Facebook user token appears expired. Reconnect it now from this settings page.', 'fb-page-autopublisher') . '</p></div>';
        } elseif ($days_remaining <= self::WARNING_WINDOW_DAYS) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(__('Facebook user token expires in %d day(s). Automatic refresh will be attempted daily and a warning email has been sent to the site administrator if needed.', 'fb-page-autopublisher'), $days_remaining))
            );
        }

        if ($status !== '' && $message !== '') {
            $notice_class = $status === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public static function schedule_cron(): void
    {
        if (! wp_next_scheduled(CRON_HOOK_TOKEN_MAINTENANCE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', CRON_HOOK_TOKEN_MAINTENANCE);
        }
    }

    public static function unschedule_cron(): void
    {
        $timestamp = wp_next_scheduled(CRON_HOOK_TOKEN_MAINTENANCE);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, CRON_HOOK_TOKEN_MAINTENANCE);
        }
    }

    private function has_refresh_credentials(array $settings): bool
    {
        return trim((string) ($settings['app_id'] ?? '')) !== ''
            && trim((string) ($settings['app_secret'] ?? '')) !== ''
            && trim((string) ($settings['user_access_token'] ?? '')) !== ''
            && trim((string) ($settings['page_id'] ?? '')) !== '';
    }

    private function extract_refresh_error_message(mixed $body): string
    {
        if (is_array($body) && isset($body['error']['message'])) {
            return sanitize_text_field((string) $body['error']['message']);
        }

        if (is_array($body) && isset($body['error_message'])) {
            return sanitize_text_field((string) $body['error_message']);
        }

        $text = is_string($body) ? $body : wp_json_encode($body);

        return sanitize_text_field((string) $text);
    }

    private function store_refresh_status(string $status, string $message): void
    {
        $settings = get_settings();
        $settings['last_token_refresh_at'] = current_time('mysql');
        $settings['last_token_refresh_status'] = $status;
        $settings['last_token_refresh_message'] = $message;

        update_option(OPTION_KEY, $settings);
    }

    private function send_warning_email_once(string $subject, string $message): void
    {
        $settings = get_settings();
        $last_sent_at = (string) ($settings['token_warning_sent_at'] ?? '');
        $last_sent_ts = $last_sent_at !== '' ? strtotime($last_sent_at) : false;

        if ($last_sent_ts !== false && (time() - $last_sent_ts) < DAY_IN_SECONDS) {
            return;
        }

        $recipient = get_admin_email_address();

        if ($recipient !== '') {
            wp_mail($recipient, $subject, $message);
        }

        $settings['token_warning_sent_at'] = current_time('mysql');
        update_option(OPTION_KEY, $settings);
    }

    private function clear_warning_marker(): void
    {
        $settings = get_settings();

        if ((string) ($settings['token_warning_sent_at'] ?? '') === '') {
            return;
        }

        $settings['token_warning_sent_at'] = '';
        update_option(OPTION_KEY, $settings);
    }
}
