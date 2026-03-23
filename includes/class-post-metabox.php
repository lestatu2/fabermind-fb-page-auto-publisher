<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Post_Metabox
{
    public function hooks(): void
    {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_metabox']);
        add_action('current_screen', [$this, 'register_list_table_hooks']);
    }

    public function register_metabox(): void
    {
        foreach (get_supported_post_types() as $post_type) {
            add_meta_box(
                'fbap_metabox',
                __('Facebook Auto Publisher', 'fb-page-autopublisher'),
                [$this, 'render_metabox'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_metabox(\WP_Post $post): void
    {
        wp_nonce_field('fbap_save_metabox', 'fbap_nonce');

        $enabled = get_post_enabled($post->ID);
        $custom_message = (string) get_post_meta($post->ID, META_CUSTOM_MESSAGE, true);
        $status = (string) get_post_meta($post->ID, META_LAST_STATUS, true);
        $attempted_at = (string) get_post_meta($post->ID, META_LAST_ATTEMPT_AT, true);
        $facebook_post_id = (string) get_post_meta($post->ID, META_FACEBOOK_POST_ID, true);
        $last_endpoint = (string) get_post_meta($post->ID, META_LAST_ENDPOINT, true);
        $last_response_code = (string) get_post_meta($post->ID, META_LAST_RESPONSE_CODE, true);
        $last_response_body = (string) get_post_meta($post->ID, META_LAST_RESPONSE_BODY, true);
        $last_photo_response_code = (string) get_post_meta($post->ID, META_LAST_PHOTO_RESPONSE_CODE, true);
        $last_photo_response_body = (string) get_post_meta($post->ID, META_LAST_PHOTO_RESPONSE_BODY, true);
        $last_photo_image_url = (string) get_post_meta($post->ID, META_LAST_PHOTO_IMAGE_URL, true);
        $last_photo_debug = (string) get_post_meta($post->ID, META_LAST_PHOTO_DEBUG, true);

        echo '<p><label><input type="checkbox" name="fbap_enabled" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('Publish this post to Facebook', 'fb-page-autopublisher') . '</label></p>';
        echo '<p><label for="fbap_custom_message">' . esc_html__('Custom Facebook message', 'fb-page-autopublisher') . '</label>';
        echo '<textarea class="widefat" rows="4" id="fbap_custom_message" name="fbap_custom_message">' . esc_textarea($custom_message) . '</textarea>';
        echo '<small>' . esc_html__('Available placeholders: {title}, {excerpt}, {link}', 'fb-page-autopublisher') . '</small></p>';
        echo '<p><strong>' . esc_html__('Last publication status:', 'fb-page-autopublisher') . '</strong><br />';
        echo esc_html($status !== '' ? $status : __('never published', 'fb-page-autopublisher'));

        if ($attempted_at !== '') {
            echo '<br />' . esc_html($attempted_at);
        }

        echo '</p>';
        echo '<p><strong>' . esc_html__('Facebook Post ID:', 'fb-page-autopublisher') . '</strong><br />';
        echo esc_html($facebook_post_id !== '' ? $facebook_post_id : '-');
        echo '</p>';

        if ($last_photo_image_url !== '') {
            echo '<p><strong>' . esc_html__('Generated image URL:', 'fb-page-autopublisher') . '</strong><br />';
            echo '<code style="word-break:break-all;">' . esc_html($last_photo_image_url) . '</code></p>';

            if (is_local_url($last_photo_image_url)) {
                echo '<p style="color:#b32d2e;"><strong>' . esc_html__('Facebook cannot fetch local or non-public image URLs.', 'fb-page-autopublisher') . '</strong></p>';
            }
        }

        if ($last_endpoint !== '' || $last_response_code !== '' || $last_response_body !== '') {
            echo '<hr />';
            echo '<p><strong>' . esc_html__('Last API log', 'fb-page-autopublisher') . '</strong></p>';

            if ($last_endpoint !== '') {
                echo '<p><strong>' . esc_html__('Endpoint:', 'fb-page-autopublisher') . '</strong> ' . esc_html($last_endpoint) . '</p>';
            }

            if ($last_response_code !== '') {
                echo '<p><strong>' . esc_html__('HTTP code:', 'fb-page-autopublisher') . '</strong> ' . esc_html($last_response_code) . '</p>';
            }

            if ($last_response_body !== '') {
                echo '<p><strong>' . esc_html__('Response:', 'fb-page-autopublisher') . '</strong></p>';
                echo '<textarea readonly="readonly" class="widefat code" rows="10">' . esc_textarea(format_log_body_for_display($last_response_body)) . '</textarea>';
            }
        }

        if ($last_photo_response_code !== '' || $last_photo_response_body !== '' || $last_photo_debug !== '') {
            echo '<hr />';
            echo '<p><strong>' . esc_html__('Photo endpoint attempt', 'fb-page-autopublisher') . '</strong></p>';

            if ($last_photo_debug !== '') {
                echo '<p><strong>' . esc_html__('Details:', 'fb-page-autopublisher') . '</strong> ' . esc_html($last_photo_debug) . '</p>';
            }

            if ($last_photo_response_code !== '') {
                echo '<p><strong>' . esc_html__('HTTP code:', 'fb-page-autopublisher') . '</strong> ' . esc_html($last_photo_response_code) . '</p>';
            }

            if ($last_photo_response_body !== '') {
                echo '<p><strong>' . esc_html__('Response:', 'fb-page-autopublisher') . '</strong></p>';
                echo '<textarea readonly="readonly" class="widefat code" rows="10">' . esc_textarea(format_log_body_for_display($last_photo_response_body)) . '</textarea>';
            }
        }
    }

    public function save_metabox(int $post_id): void
    {
        if (! isset($_POST['fbap_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbap_nonce'])), 'fbap_save_metabox')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $post_type = get_post_type($post_id);

        if (! is_string($post_type) || ! is_supported_post_type($post_type)) {
            return;
        }

        update_post_meta($post_id, META_ENABLED, isset($_POST['fbap_enabled']) ? 1 : 0);

        $custom_message = isset($_POST['fbap_custom_message']) ? sanitize_textarea_field(wp_unslash($_POST['fbap_custom_message'])) : '';
        update_post_meta($post_id, META_CUSTOM_MESSAGE, $custom_message);
    }

    public function register_list_table_hooks(\WP_Screen $screen): void
    {
        if (! isset($screen->post_type) || ! is_string($screen->post_type) || ! is_supported_post_type($screen->post_type)) {
            return;
        }

        add_filter("manage_{$screen->post_type}_posts_columns", [$this, 'add_columns']);
        add_action("manage_{$screen->post_type}_posts_custom_column", [$this, 'render_columns'], 10, 2);
    }

    public function add_columns(array $columns): array
    {
        $columns['fbap_enabled'] = __('Facebook enabled', 'fb-page-autopublisher');
        $columns['fbap_status'] = __('Facebook status', 'fb-page-autopublisher');
        $columns['fbap_attempt'] = __('Last attempt', 'fb-page-autopublisher');

        return $columns;
    }

    public function render_columns(string $column, int $post_id): void
    {
        switch ($column) {
            case 'fbap_enabled':
                echo esc_html(get_post_enabled($post_id) ? __('Yes', 'fb-page-autopublisher') : __('No', 'fb-page-autopublisher'));
                break;

            case 'fbap_status':
                echo esc_html((string) get_post_meta($post_id, META_LAST_STATUS, true));
                break;

            case 'fbap_attempt':
                echo esc_html((string) get_post_meta($post_id, META_LAST_ATTEMPT_AT, true));
                break;
        }
    }
}
