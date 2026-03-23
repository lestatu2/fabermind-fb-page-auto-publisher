<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Image_Processor
{
    private string $last_error = '';

    public function get_or_generate_facebook_image(int $post_id): array|false
    {
        $this->last_error = '';

        if (! has_post_thumbnail($post_id)) {
            $this->last_error = 'No featured image is set.';
            return false;
        }

        if (! $this->is_regeneration_needed($post_id)) {
            $path = (string) get_post_meta($post_id, META_GENERATED_IMAGE_PATH, true);
            $url = (string) get_post_meta($post_id, META_GENERATED_IMAGE_URL, true);

            if ($path !== '' && $url !== '' && file_exists($path)) {
                return [
                    'path' => $path,
                    'url' => $url,
                    'mime' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 630,
                ];
            }
        }

        return $this->generate_facebook_image($post_id);
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    public function is_regeneration_needed(int $post_id): bool
    {
        $attachment_id = (int) get_post_thumbnail_id($post_id);
        $stored_source = (int) get_post_meta($post_id, META_GENERATED_IMAGE_ATTACHMENT_SOURCE, true);
        $stored_hash = (string) get_post_meta($post_id, META_GENERATED_IMAGE_HASH, true);
        $stored_path = (string) get_post_meta($post_id, META_GENERATED_IMAGE_PATH, true);
        $source_path = get_attached_file($attachment_id);

        if ($attachment_id <= 0 || ! is_string($source_path) || $source_path === '' || ! file_exists($source_path)) {
            return true;
        }

        $current_hash = md5_file($source_path);

        if ($stored_source !== $attachment_id || $stored_hash !== $current_hash) {
            return true;
        }

        return $stored_path === '' || ! file_exists($stored_path);
    }

    public function generate_facebook_image(int $post_id): array|false
    {
        $attachment_id = (int) get_post_thumbnail_id($post_id);
        $source_path = get_attached_file($attachment_id);

        if ($attachment_id <= 0 || ! is_string($source_path) || $source_path === '' || ! file_exists($source_path)) {
            $this->last_error = 'Featured image file is missing or unreadable.';
            return false;
        }

        $upload_dir = wp_get_upload_dir();

        if (! empty($upload_dir['error'])) {
            $this->last_error = 'Upload directory error: ' . (string) $upload_dir['error'];
            return false;
        }

        $target_dir = trailingslashit($upload_dir['basedir']) . 'fbap';
        $target_url_base = trailingslashit($upload_dir['baseurl']) . 'fbap';

        wp_mkdir_p($target_dir);

        $filename = sprintf('fb-%d-%d-1200x630.jpg', $post_id, $attachment_id);
        $target_path = trailingslashit($target_dir) . $filename;
        $target_url = trailingslashit($target_url_base) . $filename;

        $editor = wp_get_image_editor($source_path);

        if (is_wp_error($editor)) {
            $this->last_error = 'wp_get_image_editor failed: ' . $editor->get_error_message();
            return false;
        }

        $source_size = $editor->get_size();
        $target_width = 1200;
        $target_height = 630;

        $editor->set_quality(85);
        $result = $editor->resize($target_width, $target_height, true);

        if (is_wp_error($result)) {
            $fallback_editor = wp_get_image_editor($source_path);

            if (is_wp_error($fallback_editor)) {
                $this->last_error = 'Image resize failed: ' . $result->get_error_message() . '. Fallback editor failed: ' . $fallback_editor->get_error_message();
                return false;
            }

            $fallback_editor->set_quality(85);
            $saved_original = $fallback_editor->save($target_path, 'image/jpeg');

            if (is_wp_error($saved_original) || empty($saved_original['path'])) {
                $this->last_error = is_wp_error($saved_original)
                    ? 'Image resize failed: ' . $result->get_error_message() . '. Fallback save failed: ' . $saved_original->get_error_message()
                    : 'Image resize failed: ' . $result->get_error_message() . '. Fallback save failed without a target path.';
                return false;
            }

            $hash = md5_file($source_path);

            update_post_meta($post_id, META_GENERATED_IMAGE_URL, esc_url_raw($target_url));
            update_post_meta($post_id, META_GENERATED_IMAGE_PATH, (string) $saved_original['path']);
            update_post_meta($post_id, META_GENERATED_IMAGE_ATTACHMENT_SOURCE, $attachment_id);
            update_post_meta($post_id, META_GENERATED_IMAGE_HASH, is_string($hash) ? $hash : '');
            update_post_meta($post_id, META_GENERATED_IMAGE_MTIME, (string) filemtime((string) $saved_original['path']));

            $this->last_error = 'Image resize failed: ' . $result->get_error_message() . '. Original image JPG copy used as fallback.';

            return [
                'path' => (string) $saved_original['path'],
                'url' => esc_url_raw($target_url),
                'mime' => 'image/jpeg',
                'width' => (int) ($source_size['width'] ?? 0),
                'height' => (int) ($source_size['height'] ?? 0),
            ];
        }

        $saved = $editor->save($target_path, 'image/jpeg');

        if (is_wp_error($saved) || empty($saved['path'])) {
            $this->last_error = is_wp_error($saved)
                ? 'Image save failed: ' . $saved->get_error_message()
                : 'Image save failed without a target path.';
            return false;
        }

        $hash = md5_file($source_path);

        update_post_meta($post_id, META_GENERATED_IMAGE_URL, esc_url_raw($target_url));
        update_post_meta($post_id, META_GENERATED_IMAGE_PATH, (string) $saved['path']);
        update_post_meta($post_id, META_GENERATED_IMAGE_ATTACHMENT_SOURCE, $attachment_id);
        update_post_meta($post_id, META_GENERATED_IMAGE_HASH, is_string($hash) ? $hash : '');
        update_post_meta($post_id, META_GENERATED_IMAGE_MTIME, (string) filemtime((string) $saved['path']));

        return [
            'path' => (string) $saved['path'],
            'url' => esc_url_raw($target_url),
            'mime' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
        ];
    }

    public function invalidate_cache(int $post_id): void
    {
        $this->last_error = '';
        delete_post_meta($post_id, META_GENERATED_IMAGE_URL);
        delete_post_meta($post_id, META_GENERATED_IMAGE_PATH);
        delete_post_meta($post_id, META_GENERATED_IMAGE_ATTACHMENT_SOURCE);
        delete_post_meta($post_id, META_GENERATED_IMAGE_HASH);
        delete_post_meta($post_id, META_GENERATED_IMAGE_MTIME);
    }
}
