<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Facebook_Client
{
    private string $graph_base = 'https://graph.facebook.com/v19.0/';

    public function publish_photo(string $page_id, string $token, string $image_url, string $caption): array
    {
        return $this->request(
            'photos',
            $page_id,
            [
                'url' => esc_url_raw($image_url),
                'caption' => $caption,
                'access_token' => $token,
            ]
        );
    }

    public function publish_feed(string $page_id, string $token, string $message, string $link): array
    {
        return $this->request(
            'feed',
            $page_id,
            [
                'message' => $message,
                'link' => esc_url_raw($link),
                'access_token' => $token,
            ]
        );
    }

    private function request(string $endpoint, string $page_id, array $body): array
    {
        $url = trailingslashit($this->graph_base . rawurlencode($page_id)) . $endpoint;
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 20,
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'http_code' => 0,
                'endpoint' => $endpoint,
                'body' => [
                    'transport_error' => true,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'error_data' => $response->get_error_data(),
                ],
                'facebook_post_id' => '',
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);
        $body_data = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw_body;
        $facebook_post_id = '';

        if (is_array($decoded)) {
            $facebook_post_id = (string) ($decoded['post_id'] ?? $decoded['id'] ?? '');
        }

        $success = $http_code >= 200 && $http_code < 300;

        if (is_array($decoded) && isset($decoded['error'])) {
            $success = false;
        }

        return [
            'success' => $success,
            'http_code' => $http_code,
            'endpoint' => $endpoint,
            'body' => $body_data,
            'facebook_post_id' => $facebook_post_id,
        ];
    }
}
