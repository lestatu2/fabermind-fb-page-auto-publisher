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

    public function refresh_user_access_token(string $app_id, string $app_secret, string $user_token): array
    {
        $url = add_query_arg(
            [
                'grant_type' => 'fb_exchange_token',
                'client_id' => trim($app_id),
                'client_secret' => trim($app_secret),
                'fb_exchange_token' => trim($user_token),
            ],
            $this->graph_base . 'oauth/access_token'
        );

        return $this->request_url($url, 'oauth/access_token');
    }

    public function get_page_access_token(string $user_token, string $page_id): array
    {
        $url = add_query_arg(
            [
                'access_token' => trim($user_token),
            ],
            $this->graph_base . 'me/accounts'
        );

        $response = $this->request_url($url, 'me/accounts');

        if (! $response['success'] || ! is_array($response['body'])) {
            return $response;
        }

        $pages = $response['body']['data'] ?? null;

        if (! is_array($pages)) {
            return [
                'success' => false,
                'http_code' => (int) ($response['http_code'] ?? 0),
                'endpoint' => 'me/accounts',
                'body' => [
                    'error' => [
                        'message' => 'Meta did not return any page data for the connected user.',
                    ],
                ],
                'page_access_token' => '',
            ];
        }

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if ((string) ($page['id'] ?? '') !== trim($page_id)) {
                continue;
            }

            return [
                'success' => true,
                'http_code' => (int) ($response['http_code'] ?? 200),
                'endpoint' => 'me/accounts',
                'body' => $response['body'],
                'page_access_token' => (string) ($page['access_token'] ?? ''),
            ];
        }

        return [
            'success' => false,
            'http_code' => (int) ($response['http_code'] ?? 0),
            'endpoint' => 'me/accounts',
            'body' => [
                'error' => [
                    'message' => sprintf('The configured page ID %s was not returned by Meta for the connected user.', $page_id),
                ],
            ],
            'page_access_token' => '',
        ];
    }

    public function debug_token(string $app_id, string $app_secret, string $input_token): array
    {
        $app_token = trim($app_id) . '|' . trim($app_secret);
        $url = add_query_arg(
            [
                'input_token' => trim($input_token),
                'access_token' => $app_token,
            ],
            $this->graph_base . 'debug_token'
        );

        return $this->request_url($url, 'debug_token');
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

        return $this->normalize_response($response, $endpoint);
    }

    private function request_url(string $url, string $endpoint): array
    {
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
            ]
        );

        return $this->normalize_response($response, $endpoint);
    }

    private function normalize_response(mixed $response, string $endpoint): array
    {
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
