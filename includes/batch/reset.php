<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('LskyProBatch_Reset', false)) {
    trait LskyProBatch_Reset {
        /**
         * 重置文章批处理进度（清理完成标记）
         */
        public function handle_reset_post_batch() {
            check_ajax_referer('lsky_pro_batch', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => '权限不足'));
            }

            global $wpdb;

            // 仅清理文章/页面的“批处理完成”标记；不会删除图床 URL，也不会改动媒体库附件。
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
                wp_send_json_error(array('message' => '重置失败'));
            }

            wp_send_json_success(array(
                'deleted' => (int) $deleted,
                'message' => sprintf('已重置文章批处理进度（清理 %d 条进度记录）', (int) $deleted),
            ));
        }

        /**
         * 重置媒体库批处理进度（清理图床 URL/PhotoId 记录）
         *
         * 注意：这会导致下次批处理重新上传这些图片，可能在图床产生重复图片。
         */
        public function handle_reset_media_batch() {
            check_ajax_referer('lsky_pro_batch', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => '权限不足'));
            }

            global $wpdb;

            $meta_keys = array('_lsky_pro_url', '_lsky_pro_photo_id');
            $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

            $sql = "DELETE pm
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE p.post_type = 'attachment'
                      AND p.post_mime_type LIKE 'image/%'
                      AND pm.meta_key IN ($placeholders)";

            $deleted = $wpdb->query($wpdb->prepare($sql, $meta_keys));

            if ($deleted === false) {
                wp_send_json_error(array('message' => '重置失败'));
            }

            wp_send_json_success(array(
                'deleted' => (int) $deleted,
                'message' => sprintf('已重置媒体库批处理进度（清理 %d 条图床记录）', (int) $deleted),
            ));
        }
    }
}
