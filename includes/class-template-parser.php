<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Template_Parser
{
    public function parse(string $template, int $post_id): string
    {
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post) {
            return '';
        }

        $excerpt_length = (int) get_setting('excerpt_length', 220);
        $replacements = [
            '{title}' => get_the_title($post_id),
            '{excerpt}' => $this->get_excerpt($post_id, $excerpt_length),
            '{link}' => get_permalink($post_id),
        ];

        $message = strtr($template, $replacements);
        $message = strip_shortcodes($message);
        $message = wp_strip_all_tags($message);

        return normalize_text($message);
    }

    public function get_excerpt(int $post_id, int $max_length): string
    {
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post) {
            return '';
        }

        $excerpt = has_excerpt($post_id) ? (string) $post->post_excerpt : (string) $post->post_content;
        $excerpt = strip_shortcodes($excerpt);
        $excerpt = wp_strip_all_tags($excerpt);
        $excerpt = preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt;
        $excerpt = trim($excerpt);

        if ($max_length > 0 && function_exists('mb_strlen') && mb_strlen($excerpt) > $max_length) {
            $excerpt = mb_substr($excerpt, 0, max(0, $max_length - 1)) . '...';
        } elseif ($max_length > 0 && strlen($excerpt) > $max_length) {
            $excerpt = substr($excerpt, 0, max(0, $max_length - 1)) . '...';
        }

        return $excerpt;
    }
}
