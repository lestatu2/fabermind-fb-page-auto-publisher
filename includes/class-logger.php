<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Logger
{
    public function log_attempt(int $post_id, array $data): void
    {
        if (empty(get_setting('logging_enabled', 1))) {
            return;
        }

        $status = sanitize_text_field((string) ($data['status'] ?? 'error'));
        $attempted_at = current_time('mysql');
        $endpoint = sanitize_text_field((string) ($data['endpoint'] ?? ''));
        $http_code = isset($data['http_code']) ? (int) $data['http_code'] : 0;
        $response_body = $data['response_body'] ?? '';
        $facebook_post_id = sanitize_text_field((string) ($data['facebook_post_id'] ?? ''));
        $photo_response_code = isset($data['photo_response_code']) ? (int) $data['photo_response_code'] : 0;
        $photo_response_body = $data['photo_response_body'] ?? '';
        $photo_image_url = isset($data['photo_image_url']) ? esc_url_raw((string) $data['photo_image_url']) : '';
        $photo_debug = sanitize_textarea_field((string) ($data['photo_debug'] ?? ''));

        update_post_meta($post_id, META_LAST_STATUS, $status);
        update_post_meta($post_id, META_LAST_ATTEMPT_AT, $attempted_at);
        update_post_meta($post_id, META_LAST_ENDPOINT, $endpoint);
        update_post_meta($post_id, META_LAST_RESPONSE_CODE, $http_code);
        update_post_meta($post_id, META_LAST_RESPONSE_BODY, $this->prepare_response_body($response_body));
        update_post_meta($post_id, META_LAST_PHOTO_RESPONSE_CODE, $photo_response_code);
        update_post_meta($post_id, META_LAST_PHOTO_RESPONSE_BODY, $this->prepare_response_body($photo_response_body));
        update_post_meta($post_id, META_LAST_PHOTO_IMAGE_URL, $photo_image_url);
        update_post_meta($post_id, META_LAST_PHOTO_DEBUG, $photo_debug);

        if ($facebook_post_id !== '') {
            update_post_meta($post_id, META_FACEBOOK_POST_ID, $facebook_post_id);
        }

        if ($status === 'success') {
            update_post_meta($post_id, META_LAST_SUCCESS_AT, $attempted_at);
            update_post_meta($post_id, META_ALREADY_PUBLISHED, 1);
        }
    }

    private function prepare_response_body(mixed $body): string
    {
        if (is_array($body) || is_object($body)) {
            $encoded = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        return wp_strip_all_tags((string) $body);
    }
}
