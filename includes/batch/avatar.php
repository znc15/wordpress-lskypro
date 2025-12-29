<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('LskyProBatch_Avatar', false)) {
    trait LskyProBatch_Avatar {
        /**
         * 判断附件是否为用户头像。
         */
        private function is_avatar_attachment($attachment_id) {
            global $wpdb;

            $attachment_id = (int) $attachment_id;
            if ($attachment_id <= 0) {
                return false;
            }

            $file_path = function_exists('get_attached_file') ? (string) get_attached_file($attachment_id) : '';
            $file_basename = $file_path !== '' ? basename($file_path) : '';
            $guid = '';
            if (function_exists('get_post')) {
                $p = get_post($attachment_id);
                if ($p && isset($p->guid)) {
                    $guid = (string) $p->guid;
                }
            }

            // 一些常见头像插件/方案会用这些 key 存储附件ID或序列化结构
            $avatar_keys = array(
                'wp_user_avatar',
                'simple_local_avatar',
                'avatar',
                'user_avatar',
                'user_avatar_id',
                'profile_picture',
                'profile_picture_id',
            );

            $placeholders = implode(',', array_fill(0, count($avatar_keys), '%s'));

            // 覆盖：纯数字字符串、序列化 int、序列化 string
            $id_str = (string) $attachment_id;
            $id_len = strlen($id_str);
            $like_serialized_int = '%i:' . $attachment_id . ';%';
            $like_serialized_str = '%s:' . $id_len . ':"' . $id_str . '";%';
            $like_jsonish = '%"' . $id_str . '"%';

            $sql = "SELECT COUNT(umeta_id)
                    FROM {$wpdb->usermeta}
                    WHERE meta_key IN ($placeholders)
                      AND (
                            meta_value = %s
                         OR meta_value LIKE %s
                         OR meta_value LIKE %s
                         OR meta_value LIKE %s
                      )";

            $params = array_merge(
                $avatar_keys,
                array(
                    $id_str,
                    $like_serialized_int,
                    $like_serialized_str,
                    $like_jsonish,
                )
            );

            $count = (int) $wpdb->get_var($wpdb->prepare($sql, $params));

            if ($count > 0) {
                return true;
            }

            $needles = array();
            if ($file_basename !== '') {
                $needles[] = $file_basename;
            }
            if ($guid !== '') {
                $needles[] = $guid;
            }

            if (!empty($needles)) {
                $like_parts = array();
                $like_params = array();
                foreach ($needles as $needle) {
                    $like_parts[] = 'meta_value LIKE %s';
                    $like_params[] = '%' . $wpdb->esc_like((string) $needle) . '%';
                }

                $sql2 = "SELECT COUNT(umeta_id)
                         FROM {$wpdb->usermeta}
                         WHERE meta_key IN ($placeholders)
                           AND (" . implode(' OR ', $like_parts) . ")";

                $params2 = array_merge($avatar_keys, $like_params);
                $count2 = (int) $wpdb->get_var($wpdb->prepare($sql2, $params2));
                return $count2 > 0;
            }

            return false;
        }
    }
}
