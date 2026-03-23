<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Publisher
{
    public function __construct(
        private readonly Template_Parser $template_parser,
        private readonly Image_Processor $image_processor,
        private readonly Facebook_Client $facebook_client,
        private readonly Logger $logger
    ) {
    }

    public function hooks(): void
    {
        add_action('transition_post_status', [$this, 'maybe_publish'], 10, 3);
        add_action('set_post_thumbnail', [$this, 'invalidate_image_cache']);
        add_action('deleted_post_meta', [$this, 'handle_thumbnail_meta_change'], 10, 4);
        add_action('updated_post_meta', [$this, 'handle_thumbnail_meta_change'], 10, 4);
    }

    public function maybe_publish(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }

        if (! is_supported_post_type($post->post_type)) {
            return;
        }

        $settings = get_settings();

        if (empty($settings['enabled']) || ! get_post_enabled($post->ID)) {
            return;
        }

        if (! empty($settings['first_publish_only']) && (int) get_post_meta($post->ID, META_ALREADY_PUBLISHED, true) === 1) {
            return;
        }

        $page_id = trim((string) $settings['page_id']);
        $token = trim((string) $settings['page_access_token']);

        if ($page_id === '' || $token === '') {
            $this->logger->log_attempt(
                $post->ID,
                [
                    'status' => 'error',
                    'endpoint' => '',
                    'http_code' => 0,
                    'response_body' => 'Missing Facebook Page ID or access token.',
                    'facebook_post_id' => '',
                ]
            );

            return;
        }

        $template = (string) get_post_meta($post->ID, META_CUSTOM_MESSAGE, true);

        if ($template === '') {
            $template = (string) $settings['message_template'];
        }

        $message = $this->template_parser->parse($template, $post->ID);
        $link = get_permalink($post->ID);
        $result = null;
        $image = false;
        $use_generated_image = ! empty($settings['force_facebook_image']);
        $photo_attempt = null;
        $photo_debug = '';

        if (has_post_thumbnail($post->ID)) {
            if ($use_generated_image) {
                $image = $this->image_processor->get_or_generate_facebook_image($post->ID);
                if (! is_array($image)) {
                    $processor_error = $this->image_processor->get_last_error();
                    $photo_debug = 'Featured image found, but Facebook image generation failed.';

                    if ($processor_error !== '') {
                        $photo_debug .= ' ' . $processor_error;
                    }
                }
            } else {
                $image_url = get_the_post_thumbnail_url($post->ID, 'full');

                if (is_string($image_url) && $image_url !== '') {
                    $image = [
                        'url' => $image_url,
                    ];
                } else {
                    $photo_debug = 'Featured image found, but no public image URL was resolved.';
                }
            }
        } else {
            $photo_debug = 'No featured image on the post.';
        }

        if (is_array($image) && ! empty($image['url'])) {
            $photo_attempt = $this->facebook_client->publish_photo($page_id, $token, (string) $image['url'], $message);
            $result = $photo_attempt;
            $photo_debug = 'Photo endpoint attempted.';

            if (! $result['success']) {
                $photo_debug = 'Photo endpoint failed, fallback to feed used.';
                $result = $this->facebook_client->publish_feed($page_id, $token, $message, $link);
            }
        } else {
            $result = $this->facebook_client->publish_feed($page_id, $token, $message, $link);
            if ($photo_debug === '') {
                $photo_debug = 'Photo endpoint skipped, fallback to feed used.';
            }
        }

        $this->logger->log_attempt(
            $post->ID,
            [
                'status' => ! empty($result['success']) ? 'success' : 'error',
                'endpoint' => (string) ($result['endpoint'] ?? ''),
                'http_code' => (int) ($result['http_code'] ?? 0),
                'response_body' => $result['body'] ?? '',
                'facebook_post_id' => (string) ($result['facebook_post_id'] ?? ''),
                'photo_response_code' => (int) ($photo_attempt['http_code'] ?? 0),
                'photo_response_body' => $photo_attempt['body'] ?? '',
                'photo_image_url' => is_array($image) ? (string) ($image['url'] ?? '') : '',
                'photo_debug' => $photo_debug,
            ]
        );
    }

    public function invalidate_image_cache(int $post_id): void
    {
        $this->image_processor->invalidate_cache($post_id);
    }

    public function handle_thumbnail_meta_change(mixed $meta_id, int $object_id, string $meta_key, mixed $meta_value): void
    {
        if ($meta_key === '_thumbnail_id') {
            $this->image_processor->invalidate_cache($object_id);
        }
    }
}
