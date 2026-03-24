<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

const OPTION_KEY = 'fbap_settings';
const CRON_HOOK_TOKEN_MAINTENANCE = 'fbap_token_maintenance';
const META_ENABLED = '_fbap_enabled';
const META_CUSTOM_MESSAGE = '_fbap_custom_message';
const META_ALREADY_PUBLISHED = '_fbap_already_published';
const META_LAST_STATUS = '_fbap_last_status';
const META_LAST_ATTEMPT_AT = '_fbap_last_attempt_at';
const META_LAST_ENDPOINT = '_fbap_last_endpoint';
const META_LAST_RESPONSE_CODE = '_fbap_last_response_code';
const META_LAST_RESPONSE_BODY = '_fbap_last_response_body';
const META_LAST_PHOTO_RESPONSE_CODE = '_fbap_last_photo_response_code';
const META_LAST_PHOTO_RESPONSE_BODY = '_fbap_last_photo_response_body';
const META_LAST_PHOTO_IMAGE_URL = '_fbap_last_photo_image_url';
const META_LAST_PHOTO_DEBUG = '_fbap_last_photo_debug';
const META_FACEBOOK_POST_ID = '_fbap_facebook_post_id';
const META_LAST_SUCCESS_AT = '_fbap_last_success_at';
const META_GENERATED_IMAGE_URL = '_fbap_generated_image_url';
const META_GENERATED_IMAGE_PATH = '_fbap_generated_image_path';
const META_GENERATED_IMAGE_ATTACHMENT_SOURCE = '_fbap_generated_image_attachment_source';
const META_GENERATED_IMAGE_HASH = '_fbap_generated_image_hash';
const META_GENERATED_IMAGE_MTIME = '_fbap_generated_image_mtime';

function get_default_settings(): array
{
    return [
        'page_id' => '',
        'page_access_token' => '',
        'app_id' => '',
        'app_secret' => '',
        'user_access_token' => '',
        'user_token_expires_at' => '',
        'last_token_refresh_at' => '',
        'last_token_refresh_status' => '',
        'last_token_refresh_message' => '',
        'last_token_debug_at' => '',
        'last_token_debug_status' => '',
        'last_token_debug_message' => '',
        'token_warning_sent_at' => '',
        'enabled' => 1,
        'message_template' => "{title}\n\n{excerpt}\n\n{link}",
        'first_publish_only' => 1,
        'force_facebook_image' => 1,
        'logging_enabled' => 1,
        'excerpt_length' => 220,
        'supported_post_types' => ['post'],
    ];
}

function get_settings(): array
{
    $settings = get_option(OPTION_KEY, []);

    return wp_parse_args(is_array($settings) ? $settings : [], get_default_settings());
}

function get_setting(string $key, mixed $default = null): mixed
{
    $settings = get_settings();

    return $settings[$key] ?? $default;
}

function get_supported_post_types(): array
{
    $post_types = get_setting('supported_post_types', ['post']);

    if (! is_array($post_types)) {
        return ['post'];
    }

    $post_types = array_map('sanitize_key', $post_types);
    $post_types = array_filter($post_types, static fn (string $post_type): bool => post_type_exists($post_type));

    if (! in_array('post', $post_types, true)) {
        $post_types[] = 'post';
    }

    return array_values(array_unique($post_types));
}

function is_supported_post_type(string $post_type): bool
{
    return in_array($post_type, get_supported_post_types(), true);
}

function normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return trim($text);
}

function get_post_enabled(int $post_id): bool
{
    $value = get_post_meta($post_id, META_ENABLED, true);

    if ($value === '') {
        return true;
    }

    return (bool) absint((string) $value);
}

function get_token_expiration_timestamp(): int
{
    $value = (string) get_setting('user_token_expires_at', '');

    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);

    return $timestamp !== false ? $timestamp : 0;
}

function get_token_days_remaining(): ?int
{
    $timestamp = get_token_expiration_timestamp();

    if ($timestamp <= 0) {
        return null;
    }

    return (int) floor(($timestamp - time()) / DAY_IN_SECONDS);
}

function get_admin_email_address(): string
{
    $email = get_option('admin_email', '');

    return is_string($email) ? sanitize_email($email) : '';
}

function mask_token(string $token): string
{
    $length = strlen($token);

    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($token, 0, 4) . str_repeat('*', max(0, $length - 8)) . substr($token, -4);
}

function format_log_body_for_display(string $body): string
{
    if ($body === '') {
        return '';
    }

    $decoded = json_decode($body, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $pretty = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($pretty) ? $pretty : $body;
    }

    return $body;
}

function is_local_url(string $url): bool
{
    $host = wp_parse_url($url, PHP_URL_HOST);

    if (! is_string($host) || $host === '') {
        return true;
    }

    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return true;
    }

    return (bool) preg_match('/\.(test|local|localhost|invalid)$/i', $host);
}
