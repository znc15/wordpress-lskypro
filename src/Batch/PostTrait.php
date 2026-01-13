<?php

declare(strict_types=1);

namespace LskyPro\Batch;

trait PostTrait
{
    /**
     * 文章批处理失败标记 meta key（1 表示该文章批处理中出现过失败）
     */
    private $post_failed_meta_key = '_lsky_pro_post_batch_failed';

    private function process_batch()
    {
        global $wpdb;

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

        $processed_items = [];
        foreach ($posts as $post) {
            $this->processed++;
            $content = (string) $post->post_content;
            $pattern = '/<img[^>]+src=([\'\"])((?:http|https):\/\/[^>]+?)\1[^>]*>/i';

            $options = \get_option('lsky_pro_options');
            $api_url = \is_array($options) && isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
            $lsky_host = $api_url !== '' ? (string) \parse_url($api_url, \PHP_URL_HOST) : '';

            $had_failure = false;

            if (\preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[2] as $url) {
                    if ($lsky_host !== '' && \strpos((string) $url, $lsky_host) !== false) {
                        $processed_items[] = [
                            'success' => true,
                            'original' => (string) $url,
                            'new_url' => (string) $url,
                            'status' => 'already_processed',
                        ];
                        continue;
                    }

                    try {
                        $temp_file = $this->download_remote_image((string) $url);
                        if (!$temp_file) {
                            $this->failed++;
                            $had_failure = true;
                            $processed_items[] = [
                                'success' => false,
                                'original' => (string) $url,
                                'error' => '下载图片失败',
                                'status' => 'failed',
                            ];
                            continue;
                        }

                        $new_url = $this->uploader->upload($temp_file);
                        if ($new_url) {
                            $content = \str_replace((string) $url, $new_url, $content);
                            $this->success++;
                            $processed_items[] = [
                                'success' => true,
                                'original' => (string) $url,
                                'new_url' => $new_url,
                                'status' => 'newly_processed',
                            ];
                        } else {
                            $this->failed++;
                            $had_failure = true;
                            $processed_items[] = [
                                'success' => false,
                                'original' => (string) $url,
                                'error' => $this->uploader->getError(),
                                'status' => 'failed',
                            ];
                        }

                        @\unlink($temp_file);
                    } catch (\Exception $e) {
                        $this->failed++;
                        $had_failure = true;
                        $processed_items[] = [
                            'success' => false,
                            'original' => (string) $url,
                            'error' => $e->getMessage(),
                            'status' => 'failed',
                        ];
                    }
                }
            }

            if ($content !== (string) $post->post_content) {
                $modified = \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
                $modified_gmt = \function_exists('current_time') ? \current_time('mysql', true) : \gmdate('Y-m-d H:i:s');

                $wpdb->update(
                    $wpdb->posts,
                    [
                        'post_content' => $content,
                        'post_modified' => $modified,
                        'post_modified_gmt' => $modified_gmt,
                    ],
                    ['ID' => (int) $post->ID],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                if (\function_exists('clean_post_cache')) {
                    \clean_post_cache((int) $post->ID);
                }
            }

            if ($had_failure) {
                \update_post_meta((int) $post->ID, $this->post_failed_meta_key, 1);
            }
            \update_post_meta((int) $post->ID, $this->post_done_meta_key, 1);
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

        $processed_overall = \max(0, $total - $remaining_after);
        $completed = ($remaining_after === 0);

        return [
            'processed' => $processed_overall,
            'success' => $this->success,
            'failed' => $this->failed,
            'total' => $total,
            'completed' => $completed,
            'processed_items' => $processed_items,
            'message' => \sprintf(
                '本次处理 %d 篇文章，成功 %d 张，失败 %d 张（累计完成 %d/%d）',
                $this->processed,
                $this->success,
                $this->failed,
                $processed_overall,
                $total
            ),
        ];
    }

    private function download_remote_image($url)
    {
        $uploads = \wp_upload_dir();
        $tmp_dir = (string) ($uploads['basedir'] ?? '') . '/temp';
        if (!\file_exists($tmp_dir)) {
            \wp_mkdir_p($tmp_dir);
        }

        $temp_file = $tmp_dir . '/' . \uniqid('batch_', true) . '_' . \basename((string) $url);

        $response = \wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (\is_wp_error($response) || (int) \wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $image_data = (string) \wp_remote_retrieve_body($response);
        if (\file_put_contents($temp_file, $image_data) === false) {
            return false;
        }

        return $temp_file;
    }
}
