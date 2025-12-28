<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('LskyProBatch_Post', false)) {
    trait LskyProBatch_Post {
        /**
         * 处理文章中的图片
         */
        private function process_batch() {
            global $wpdb;

            // 计算文章总数（含图片）
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) 
                 FROM {$wpdb->posts} 
                 WHERE post_type IN ('post', 'page') 
                   AND post_status = 'publish' 
                   AND post_content LIKE '%<img%'"
            );

            // 仅选择未完成的文章（断点续跑）
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
}
