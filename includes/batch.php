<?php
/**
 * 处理历史文章中的图片
 */
class LskyProBatch {
    private $uploader;
    private $processed = 0;
    private $success = 0;
    private $failed = 0;
    private $batch_size = 10; // 每批处理的文章数

    /**
     * 头像附件标记 meta key（1 表示为头像，批处理跳过）
     */
    private $avatar_meta_key = '_lsky_pro_is_avatar';

    /**
     * 通用：批处理跳过标记 meta key（1 表示跳过）
     */
    private $batch_skip_meta_key = '_lsky_pro_batch_skip';

    /**
     * 文件类型标记：0=非限制文件，1=限制文件（例如头像）
     */
    private $type_meta_key = '_lsky_pro_type';

    /**
     * 文章批处理完成标记 meta key（1 表示该文章已完成批处理）
     */
    private $post_done_meta_key = '_lsky_pro_post_batch_done';
    
    public function __construct() {
        $this->uploader = new LskyProUploader();
        
        // 注册 AJAX 处理器
        add_action('wp_ajax_lsky_pro_process_media_batch', array($this, 'handle_ajax'));
        add_action('wp_ajax_lsky_pro_process_post_batch', array($this, 'handle_ajax'));
        add_action('wp_ajax_lsky_pro_reset_post_batch', array($this, 'handle_reset_post_batch'));
        add_action('wp_ajax_lsky_pro_reset_media_batch', array($this, 'handle_reset_media_batch'));
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
     * 处理AJAX请求
     */
    public function handle_ajax() {
        check_ajax_referer('lsky_pro_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $action = $_POST['action'];
        
        // 重置计数器
        $this->processed = 0;
        $this->success = 0;
        $this->failed = 0;
        
        if ($action === 'lsky_pro_process_media_batch') {
            $result = $this->process_media_batch();
        } else if ($action === 'lsky_pro_process_post_batch') {
            $result = $this->process_batch();
        } else {
            wp_send_json_error(array('message' => '无效的操作类型'));
            return;
        }
        
        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => '处理失败'));
        }
    }
    
    /**
     * 处理媒体库图片
     */
    private function process_media_batch() {
        global $wpdb;

        // 总数：媒体库中所有图片附件
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
               AND post_mime_type LIKE 'image/%'"
        );

        // 仅选择“仍需处理”的附件：未上传到图床、未被标记为头像/限制/跳过
        $base_sql = "
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lsky_pro_url'
            LEFT JOIN {$wpdb->postmeta} av ON p.ID = av.post_id AND av.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} sk ON p.ID = sk.post_id AND sk.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} tp ON p.ID = tp.post_id AND tp.meta_key = %s
            WHERE p.post_type = 'attachment'
              AND p.post_mime_type LIKE 'image/%'
              AND (pm.meta_value IS NULL OR pm.meta_value = '')
              AND (av.meta_value IS NULL OR av.meta_value = '' OR av.meta_value = '0')
              AND (sk.meta_value IS NULL OR sk.meta_value = '' OR sk.meta_value = '0')
              AND (tp.meta_value IS NULL OR tp.meta_value = '' OR tp.meta_value = '0')
        ";

        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.guid, pm.meta_value as lsky_url, av.meta_value as is_avatar, sk.meta_value as batch_skip, tp.meta_value as lsky_type
                 {$base_sql}
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $this->avatar_meta_key,
                $this->batch_skip_meta_key,
                $this->type_meta_key,
                $this->batch_size
            )
        );
        
        $processed_items = array();
        foreach ($attachments as $attachment) {
            $this->processed++;

            // 已被标记为头像：直接跳过
            if (!empty($attachment->is_avatar) && (string) $attachment->is_avatar !== '0') {
                if (function_exists('update_post_meta')) {
                    update_post_meta($attachment->ID, $this->type_meta_key, 1);
                }
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => '',
                    'status' => 'avatar_skipped'
                );
                continue;
            }

            // 已被标记为限制文件：直接跳过（优先级高于自动识别）
            if (!empty($attachment->lsky_type) && (string) $attachment->lsky_type !== '0') {
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => '',
                    'status' => 'restricted_skipped'
                );
                continue;
            }

            // 已被标记为“批处理跳过”：直接跳过
            if (!empty($attachment->batch_skip) && (string) $attachment->batch_skip !== '0') {
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => '',
                    'status' => 'excluded_skipped'
                );
                continue;
            }

            // 自动识别头像附件：识别到就打标并跳过
            if ($this->is_avatar_attachment((int) $attachment->ID)) {
                update_post_meta($attachment->ID, $this->avatar_meta_key, 1);
                update_post_meta($attachment->ID, $this->batch_skip_meta_key, 1);
                update_post_meta($attachment->ID, $this->type_meta_key, 1);
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => '',
                    'status' => 'avatar_marked_skipped'
                );
                continue;
            }
            
            // 检查是否已经处理过
            if (!empty($attachment->lsky_url)) {
                if (function_exists('update_post_meta') && (empty($attachment->lsky_type) || (string) $attachment->lsky_type === '')) {
                    update_post_meta($attachment->ID, $this->type_meta_key, 0);
                }
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => $attachment->lsky_url,
                    'status' => 'already_processed'
                );
                continue;
            }
            
            $file = get_attached_file($attachment->ID);
            if ($file && file_exists($file)) {
                try {
                    if (function_exists('lsky_pro_should_upload_to_lsky')) {
                        $should_upload = lsky_pro_should_upload_to_lsky(
                            array(
                                'file_path' => (string) $file,
                                'mime_type' => '',
                                'attachment_id' => (int) $attachment->ID,
                                'source' => 'media_batch',
                            ),
                            array(
                                'doing_ajax' => function_exists('wp_doing_ajax') ? wp_doing_ajax() : false,
                                'action' => isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '',
                                'context' => isset($_REQUEST['context']) ? sanitize_key((string) $_REQUEST['context']) : '',
                                'referer' => function_exists('wp_get_referer') ? (string) wp_get_referer() : '',
                                'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                            )
                        );

                        if (!$should_upload) {
                            $this->success++;
                            $processed_items[] = array(
                                'success' => true,
                                'original' => basename($file),
                                'new_url' => '',
                                'status' => 'excluded'
                            );
                            continue;
                        }
                    }

                    $new_url = $this->uploader->upload($file);
                    if ($new_url) {
                        update_post_meta($attachment->ID, '_lsky_pro_url', $new_url);
                        update_post_meta($attachment->ID, $this->type_meta_key, 0);

                        $photo_id = $this->uploader->getLastUploadedPhotoId();
                        if (is_numeric($photo_id)) {
                            $photo_id = (int) $photo_id;
                            if ($photo_id > 0) {
                                update_post_meta($attachment->ID, '_lsky_pro_photo_id', $photo_id);
                            }
                        }

                        $this->success++;
                        $processed_items[] = array(
                            'success' => true,
                            'original' => basename($file),
                            'new_url' => $new_url,
                            'status' => 'newly_processed'
                        );
                    } else {
                        $this->failed++;
                        $processed_items[] = array(
                            'success' => false,
                            'original' => basename($file),
                            'error' => $this->uploader->getError(),
                            'status' => 'failed'
                        );
                    }
                } catch (Exception $e) {
                    $this->failed++;
                    $processed_items[] = array(
                        'success' => false,
                        'original' => basename($file),
                        'error' => $e->getMessage(),
                        'status' => 'failed'
                    );
                }
            }
        }

        $remaining_after = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) {$base_sql}",
                $this->avatar_meta_key,
                $this->batch_skip_meta_key,
                $this->type_meta_key
            )
        );

        $processed_overall = max(0, $total - $remaining_after);
        $completed = ($remaining_after === 0);

        return array(
            // 为兼容前端进度条，这里返回“累计已完成数量”而不是“本次处理数量”
            'processed' => $processed_overall,
            'success' => $this->success,
            'failed' => $this->failed,
            'total' => $total,
            'completed' => $completed,
            'processed_items' => $processed_items,
            'message' => sprintf(
                '本次处理 %d 张图片，成功 %d 张，失败 %d 张（累计完成 %d/%d）',
                $this->processed,
                $this->success,
                $this->failed,
                $processed_overall,
                $total
            )
        );
    }

    /**
     * 判断附件是否为用户头像。
     *
     * 说明：不同站点/插件保存头像的方式不一，这里用“常见 usermeta key + meta_value 包含附件ID”的
     * 方式做一个尽量安全的识别；识别到后会在附件 postmeta 写入标记，后续批处理将直接跳过。
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

        // 兜底：有些头像插件/主题在 usermeta 里存的是 URL/文件名/路径，而不是附件ID
        // 这里按 basename / guid 做 LIKE 匹配，尽量避免误伤（只在常见头像 key 范围内查）。
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
    
    /**
     * 处理文章中的图片
     */
    private function process_batch() {
        global $wpdb;

        // 计算文章总数（含图片）与剩余未完成数（用于断点续跑进度）
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) 
             FROM {$wpdb->posts} 
             WHERE post_type IN ('post', 'page') 
               AND post_status = 'publish' 
               AND post_content LIKE '%<img%'"
        );

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content, p.post_title
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} dm ON p.ID = dm.post_id AND dm.meta_key = %s
                 WHERE p.post_type IN ('post', 'page')
                   AND p.post_status = 'publish'
                   AND p.post_content LIKE '%<img%'
                   AND (dm.meta_value IS NULL OR dm.meta_value = '' OR dm.meta_value = '0')
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $this->post_done_meta_key,
                $this->batch_size
            )
        );

        $processed_items = array();
        foreach ($posts as $post) {
            $this->processed++;
            $content = $post->post_content;
            $pattern = '/<img[^>]+src=([\'\"])((?:http|https):\/\/[^>]+?)\1[^>]*>/i';

            $options = get_option('lsky_pro_options');
            $api_url = is_array($options) && isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
            $lsky_host = $api_url !== '' ? (string) parse_url($api_url, PHP_URL_HOST) : '';

            $all_images_already_lsky = true;
            $had_failure = false;
            
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[2] as $url) {
                    // 检查是否已经是图床URL
                    if ($lsky_host !== '' && strpos($url, $lsky_host) !== false) {
                        $processed_items[] = array(
                            'success' => true,
                            'original' => $url,
                            'new_url' => $url,
                            'status' => 'already_processed'
                        );
                        continue;
                    }

                    $all_images_already_lsky = false;
                    
                    try {
                        $temp_file = $this->download_remote_image($url);
                        if (!$temp_file) {
                            $this->failed++;
                            $had_failure = true;
                            $processed_items[] = array(
                                'success' => false,
                                'original' => $url,
                                'error' => '下载图片失败',
                                'status' => 'failed'
                            );
                            continue;
                        }
                        
                        $new_url = $this->uploader->upload($temp_file);
                        if ($new_url) {
                            $content = str_replace($url, $new_url, $content);
                            $this->success++;
                            $processed_items[] = array(
                                'success' => true,
                                'original' => $url,
                                'new_url' => $new_url,
                                'status' => 'newly_processed'
                            );
                        } else {
                            $this->failed++;
                            $had_failure = true;
                            $processed_items[] = array(
                                'success' => false,
                                'original' => $url,
                                'error' => $this->uploader->getError(),
                                'status' => 'failed'
                            );
                        }
                        
                        @unlink($temp_file);
                    } catch (Exception $e) {
                        $this->failed++;
                        $had_failure = true;
                        $processed_items[] = array(
                            'success' => false,
                            'original' => $url,
                            'error' => $e->getMessage(),
                            'status' => 'failed'
                        );
                    }
                }
            }
            
            if ($content !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $content
                ));
            }

            // 若该文章已不存在非图床图片（或全部替换成功），标记为完成，避免下次从头重复处理
            if (!$had_failure) {
                // 注意：即使一开始就全是图床图片，也应标记为完成
                if ($all_images_already_lsky || ($content !== $post->post_content)) {
                    update_post_meta($post->ID, $this->post_done_meta_key, 1);
                }
            }
        }

        $remaining_after = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} dm ON p.ID = dm.post_id AND dm.meta_key = %s
                 WHERE p.post_type IN ('post', 'page')
                   AND p.post_status = 'publish'
                   AND p.post_content LIKE '%<img%'
                   AND (dm.meta_value IS NULL OR dm.meta_value = '' OR dm.meta_value = '0')",
                $this->post_done_meta_key
            )
        );

        $processed_overall = max(0, $total - $remaining_after);
        $completed = ($remaining_after === 0);

        return array(
            // 为兼容前端进度条，这里返回“累计已完成文章数”
            'processed' => $processed_overall,
            'success' => $this->success,
            'failed' => $this->failed,
            'total' => $total,
            'completed' => $completed,
            'processed_items' => $processed_items,
            'message' => sprintf(
                '本次处理 %d 篇文章，成功 %d 张，失败 %d 张（累计完成 %d/%d）',
                $this->processed,
                $this->success,
                $this->failed,
                $processed_overall,
                $total
            )
        );
    }
    
    /**
     * 下载远程图片
     */
    private function download_remote_image($url) {
        $tmp_dir = wp_upload_dir()['basedir'] . '/temp';
        if (!file_exists($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }
        
        $temp_file = $tmp_dir . '/' . uniqid('batch_') . '_' . basename($url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        if (file_put_contents($temp_file, $image_data) === false) {
            return false;
        }
        
        return $temp_file;
    }
}
