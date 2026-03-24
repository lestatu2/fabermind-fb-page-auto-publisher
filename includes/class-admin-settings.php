<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Admin_Settings
{
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'render_notices']);
    }

    public function register_page(): void
    {
        add_options_page(
            __('Facebook Auto Publisher', 'fb-page-autopublisher'),
            __('Facebook Auto Publisher', 'fb-page-autopublisher'),
            'manage_options',
            'fb-page-autopublisher',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'fbap_settings_group',
            OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => get_default_settings(),
            ]
        );

        add_settings_section(
            'fbap_main_section',
            __('Facebook Page Settings', 'fb-page-autopublisher'),
            '__return_false',
            'fb-page-autopublisher'
        );

        $fields = [
            'page_id' => __('Facebook Page ID', 'fb-page-autopublisher'),
            'page_access_token' => __('Facebook Page Access Token', 'fb-page-autopublisher'),
            'app_id' => __('Meta App ID', 'fb-page-autopublisher'),
            'app_secret' => __('Meta App Secret', 'fb-page-autopublisher'),
            'user_access_token' => __('Long-lived User Access Token', 'fb-page-autopublisher'),
            'user_token_expires_at' => __('User Token Expires At (UTC)', 'fb-page-autopublisher'),
            'enabled' => __('Enable global auto publishing', 'fb-page-autopublisher'),
            'message_template' => __('Facebook message template', 'fb-page-autopublisher'),
            'first_publish_only' => __('Publish only on first publication', 'fb-page-autopublisher'),
            'force_facebook_image' => __('Force 1200x630 Facebook image generation', 'fb-page-autopublisher'),
            'logging_enabled' => __('Enable logging', 'fb-page-autopublisher'),
            'excerpt_length' => __('Maximum excerpt length in caption', 'fb-page-autopublisher'),
            'supported_post_types' => __('Supported post types', 'fb-page-autopublisher'),
        ];

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                [$this, 'render_field'],
                'fb-page-autopublisher',
                'fbap_main_section',
                ['field' => $field]
            );
        }
    }

    public function sanitize_settings(array $input): array
    {
        $defaults = get_default_settings();

        return [
            'page_id' => sanitize_text_field((string) ($input['page_id'] ?? $defaults['page_id'])),
            'page_access_token' => sanitize_textarea_field((string) ($input['page_access_token'] ?? $defaults['page_access_token'])),
            'app_id' => sanitize_text_field((string) ($input['app_id'] ?? $defaults['app_id'])),
            'app_secret' => sanitize_text_field((string) ($input['app_secret'] ?? $defaults['app_secret'])),
            'user_access_token' => sanitize_textarea_field((string) ($input['user_access_token'] ?? $defaults['user_access_token'])),
            'user_token_expires_at' => sanitize_text_field((string) ($input['user_token_expires_at'] ?? $defaults['user_token_expires_at'])),
            'last_token_refresh_at' => sanitize_text_field((string) ($input['last_token_refresh_at'] ?? $defaults['last_token_refresh_at'])),
            'last_token_refresh_status' => sanitize_key((string) ($input['last_token_refresh_status'] ?? $defaults['last_token_refresh_status'])),
            'last_token_refresh_message' => sanitize_textarea_field((string) ($input['last_token_refresh_message'] ?? $defaults['last_token_refresh_message'])),
            'last_token_debug_at' => sanitize_text_field((string) ($input['last_token_debug_at'] ?? $defaults['last_token_debug_at'])),
            'last_token_debug_status' => sanitize_key((string) ($input['last_token_debug_status'] ?? $defaults['last_token_debug_status'])),
            'last_token_debug_message' => sanitize_textarea_field((string) ($input['last_token_debug_message'] ?? $defaults['last_token_debug_message'])),
            'token_warning_sent_at' => sanitize_text_field((string) ($input['token_warning_sent_at'] ?? $defaults['token_warning_sent_at'])),
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'message_template' => sanitize_textarea_field((string) ($input['message_template'] ?? $defaults['message_template'])),
            'first_publish_only' => empty($input['first_publish_only']) ? 0 : 1,
            'force_facebook_image' => empty($input['force_facebook_image']) ? 0 : 1,
            'logging_enabled' => empty($input['logging_enabled']) ? 0 : 1,
            'excerpt_length' => max(0, (int) ($input['excerpt_length'] ?? $defaults['excerpt_length'])),
            'supported_post_types' => $this->sanitize_post_types($input['supported_post_types'] ?? $defaults['supported_post_types']),
        ];
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Facebook Auto Publisher', 'fb-page-autopublisher'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('fbap_settings_group');
                do_settings_sections('fb-page-autopublisher');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_field(array $args): void
    {
        $field = (string) ($args['field'] ?? '');
        $settings = get_settings();
        $value = $settings[$field] ?? '';

        switch ($field) {
            case 'page_id':
                printf(
                    '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_attr((string) $value)
                );
                break;

            case 'page_access_token':
                printf(
                    '<textarea class="large-text code" rows="5" name="%1$s[%2$s]">%3$s</textarea><p class="description">%4$s</p>',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_textarea((string) $value),
                    esc_html(sprintf(__('Stored token: %s', 'fb-page-autopublisher'), mask_token((string) $value)))
                );
                break;

            case 'app_id':
            case 'app_secret':
                printf(
                    '<input type="text" class="regular-text code" name="%1$s[%2$s]" value="%3$s" />',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_attr((string) $value)
                );
                break;

            case 'user_access_token':
                printf(
                    '<textarea class="large-text code" rows="5" name="%1$s[%2$s]">%3$s</textarea><p class="description">%4$s</p>',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_textarea((string) $value),
                    esc_html__('Used to refresh the page token automatically before expiry.', 'fb-page-autopublisher')
                );
                break;

            case 'user_token_expires_at':
                $days_remaining = get_token_days_remaining();
                $description = __('UTC datetime when the long-lived user token expires, for example 2026-05-22 14:00:00.', 'fb-page-autopublisher');

                if ($days_remaining !== null) {
                    $description .= ' ' . sprintf(__('Days remaining: %d.', 'fb-page-autopublisher'), $days_remaining);
                }

                printf(
                    '<input type="text" class="regular-text code" name="%1$s[%2$s]" value="%3$s" /><p class="description">%4$s</p>',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_attr((string) $value),
                    esc_html($description)
                );

                $refresh_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'page' => 'fb-page-autopublisher',
                            'fbap_refresh_token' => '1',
                        ],
                        admin_url('options-general.php')
                    ),
                    'fbap_refresh_token'
                );

                echo '<p><a class="button button-secondary" href="' . esc_url($refresh_url) . '">' . esc_html__('Refresh token now', 'fb-page-autopublisher') . '</a></p>';

                $last_refresh_at = (string) ($settings['last_token_refresh_at'] ?? '');
                $last_refresh_status = (string) ($settings['last_token_refresh_status'] ?? '');
                $last_refresh_message = (string) ($settings['last_token_refresh_message'] ?? '');
                $last_debug_at = (string) ($settings['last_token_debug_at'] ?? '');
                $last_debug_status = (string) ($settings['last_token_debug_status'] ?? '');
                $last_debug_message = (string) ($settings['last_token_debug_message'] ?? '');

                if ($last_refresh_at !== '' || $last_refresh_message !== '') {
                    echo '<p class="description">';
                    echo esc_html(sprintf(__('Last refresh: %1$s | Status: %2$s | Message: %3$s', 'fb-page-autopublisher'), $last_refresh_at !== '' ? $last_refresh_at : '-', $last_refresh_status !== '' ? $last_refresh_status : '-', $last_refresh_message !== '' ? $last_refresh_message : '-'));
                    echo '</p>';
                }

                if ($last_debug_at !== '' || $last_debug_message !== '') {
                    echo '<p class="description">';
                    echo esc_html(sprintf(__('Last token check: %1$s | Status: %2$s | Message: %3$s', 'fb-page-autopublisher'), $last_debug_at !== '' ? $last_debug_at : '-', $last_debug_status !== '' ? $last_debug_status : '-', $last_debug_message !== '' ? $last_debug_message : '-'));
                    echo '</p>';
                }
                break;

            case 'message_template':
                printf(
                    '<textarea class="large-text" rows="6" name="%1$s[%2$s]">%3$s</textarea><p class="description">%4$s</p>',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    esc_textarea((string) $value),
                    esc_html__('Available placeholders: {title}, {excerpt}, {link}', 'fb-page-autopublisher')
                );
                break;

            case 'excerpt_length':
                printf(
                    '<input type="number" min="0" step="1" name="%1$s[%2$s]" value="%3$d" />',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    (int) $value
                );
                break;

            case 'supported_post_types':
                $post_types = get_post_types(['public' => true], 'objects');
                unset($post_types['attachment']);

                foreach ($post_types as $post_type) {
                    printf(
                        '<label><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s /> %5$s</label><br />',
                        esc_attr(OPTION_KEY),
                        esc_attr($field),
                        esc_attr($post_type->name),
                        checked(in_array($post_type->name, (array) $value, true), true, false),
                        esc_html($post_type->labels->singular_name ?? $post_type->label)
                    );
                }

                echo '<p class="description">' . esc_html__('Select which post types should expose the feature. "post" is always supported.', 'fb-page-autopublisher') . '</p>';
                break;

            default:
                printf(
                    '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /></label>',
                    esc_attr(OPTION_KEY),
                    esc_attr($field),
                    checked((int) $value, 1, false)
                );
                break;
        }
    }

    public function render_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen || $screen->id !== 'settings_page_fb-page-autopublisher') {
            return;
        }

        $settings = get_settings();

        if ($settings['page_id'] === '' || $settings['page_access_token'] === '') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Facebook Page ID and Access Token are required for auto publishing.', 'fb-page-autopublisher') . '</p></div>';
        }

        if ($settings['app_id'] === '' || $settings['app_secret'] === '' || $settings['user_access_token'] === '' || $settings['user_token_expires_at'] === '') {
            echo '<div class="notice notice-info"><p>' . esc_html__('To enable automatic token refresh, configure Meta App ID, App Secret, long-lived user token, and its expiration date.', 'fb-page-autopublisher') . '</p></div>';
        }
    }

    private function sanitize_post_types(mixed $post_types): array
    {
        if (! is_array($post_types)) {
            return ['post'];
        }

        $post_types = array_map('sanitize_key', $post_types);
        $post_types = array_filter(
            $post_types,
            static fn (string $post_type): bool => post_type_exists($post_type) && post_type_supports($post_type, 'editor')
        );

        if (! in_array('post', $post_types, true)) {
            $post_types[] = 'post';
        }

        return array_values(array_unique($post_types));
    }
}
