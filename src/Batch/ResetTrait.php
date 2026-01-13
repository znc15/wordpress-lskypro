<?php

declare(strict_types=1);

namespace LskyPro\Batch;

trait ResetTrait
{
    public function handle_reset_post_batch(): void
    {
        \check_ajax_referer('lsky_pro_batch', 'nonce');

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => '权限不足']);
        }

        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE pm
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = %s
                   AND p.post_type IN ('post','page')",
                $this->post_done_meta_key
            )
        );

        if ($deleted === false) {
            \wp_send_json_error(['message' => '重置失败']);
        }

        \wp_send_json_success([
            'deleted' => (int) $deleted,
            'message' => \sprintf('已重置文章批处理进度（清理 %d 条进度记录）', (int) $deleted),
        ]);
    }

    public function handle_reset_media_batch(): void
    {
        \check_ajax_referer('lsky_pro_batch', 'nonce');

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => '权限不足']);
        }

        global $wpdb;

        $meta_keys = ['_lsky_pro_photo_id'];
        $placeholders = \implode(',', \array_fill(0, \count($meta_keys), '%s'));

        $sql = "DELETE pm
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type LIKE 'image/%'
                  AND pm.meta_key IN ($placeholders)";

        $deleted = $wpdb->query($wpdb->prepare($sql, $meta_keys));

        if ($deleted === false) {
            \wp_send_json_error(['message' => '重置失败']);
        }

        \wp_send_json_success([
            'deleted' => (int) $deleted,
            'message' => \sprintf('已重置媒体库批处理进度（清理 %d 条 PhotoId 记录）', (int) $deleted),
        ]);
    }
}
