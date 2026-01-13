<?php

declare(strict_types=1);

namespace LskyPro\Module;

final class Media
{
    public function register(): void
    {
        \add_filter('manage_media_columns', [$this, 'media_columns']);
        \add_action('manage_media_custom_column', [$this, 'media_custom_column'], 10, 2);

        \add_filter('intermediate_image_sizes_advanced', [$this, 'disable_image_sizes']);
        \add_filter('big_image_size_threshold', [$this, 'disable_scaled_image_size']);

        \register_activation_hook(LSKY_PRO_PLUGIN_FILE, [$this, 'activate']);
        \register_deactivation_hook(LSKY_PRO_PLUGIN_FILE, [$this, 'deactivate']);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function media_columns(array $columns): array
    {
        $columns['lsky_url'] = '图床URL';
        $columns['lsky_status'] = '图床状态';
        return $columns;
    }

    public function media_custom_column(string $columnName, int $postId): void
    {
        if ($columnName === 'lsky_url') {
            $lskyUrl = \get_post_meta($postId, '_lsky_pro_url', true);
            if (!empty($lskyUrl)) {
                echo \esc_url((string) $lskyUrl);
            }
        }

        if ($columnName === 'lsky_status') {
            $attachmentUrl = \wp_get_attachment_url($postId);
            if (!\is_string($attachmentUrl) || $attachmentUrl === '') {
                return;
            }

            if (\strpos($attachmentUrl, \get_site_url()) === false) {
                echo '<span style="color:#46b450;">✓ 已上传到图床</span>';
            } else {
                echo '<span style="color:#dc3232;">✗ 本地存储</span>';
            }
        }
    }

    /**
     * @param array<string, mixed> $sizes
     * @return array<string, mixed>
     */
    public function disable_image_sizes(array $sizes): array
    {
        foreach (['thumbnail', 'medium', 'medium_large', 'large'] as $size) {
            unset($sizes[$size]);
        }

        return $sizes;
    }

    public function disable_scaled_image_size($default): bool
    {
        return false;
    }

    public function activate(): void
    {
        \update_option('thumbnail_size_w', 0);
        \update_option('thumbnail_size_h', 0);
        \update_option('medium_size_w', 0);
        \update_option('medium_size_h', 0);
        \update_option('medium_large_size_w', 0);
        \update_option('medium_large_size_h', 0);
        \update_option('large_size_w', 0);
        \update_option('large_size_h', 0);
    }

    public function deactivate(): void
    {
        \update_option('thumbnail_size_w', 150);
        \update_option('thumbnail_size_h', 150);
        \update_option('medium_size_w', 300);
        \update_option('medium_size_h', 300);
        \update_option('medium_large_size_w', 768);
        \update_option('medium_large_size_h', 0);
        \update_option('large_size_w', 1024);
        \update_option('large_size_h', 1024);
    }
}
