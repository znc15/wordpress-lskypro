<?php

declare(strict_types=1);

namespace LskyPro\Module;

use LskyPro\Support\Options;

final class Media
{
    public function register(): void
    {
        \add_filter('manage_media_columns', [$this, 'media_columns']);
        \add_action('manage_media_custom_column', [$this, 'media_custom_column'], 10, 2);

        \add_filter('intermediate_image_sizes_advanced', [$this, 'disable_image_sizes']);
        \add_filter('big_image_size_threshold', [$this, 'disable_scaled_image_size']);
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
        if (!$this->shouldDisableWpImageSizes()) {
            return $sizes;
        }

        foreach (['thumbnail', 'medium', 'medium_large', 'large'] as $size) {
            unset($sizes[$size]);
        }

        return $sizes;
    }

    public function disable_scaled_image_size($default): bool
    {
        if (!$this->shouldDisableWpImageSizes()) {
            return (bool) $default;
        }

        return false;
    }

    /**
     * 是否禁用 WordPress 默认缩略图/中间尺寸生成。
     *
     * 兼容策略：
     * - 若站点尚未保存过插件设置（option 不存在），保持旧版本行为：默认禁用（返回 true）
     * - 若设置已存在且显式配置了 disable_wp_image_sizes，则按其值执行
     * - 也可通过 filter `lsky_pro_disable_wp_image_sizes` 覆盖
     */
    private function shouldDisableWpImageSizes(): bool
    {
        $raw = \get_option(Options::KEY);

        $enabled = null;
        if (\is_array($raw) && \array_key_exists('disable_wp_image_sizes', $raw)) {
            $enabled = !empty($raw['disable_wp_image_sizes']);
        } elseif ($raw === false) {
            // Backward compatible default (older versions always disabled sizes).
            $enabled = true;
        }

        if ($enabled === null) {
            $enabled = true;
        }

        if (\function_exists('apply_filters')) {
            $enabled = (bool) \apply_filters('lsky_pro_disable_wp_image_sizes', $enabled, $raw);
        }

        return $enabled;
    }
}
