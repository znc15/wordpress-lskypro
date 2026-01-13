<?php

declare(strict_types=1);

namespace LskyPro\Batch;

use LskyPro\Support\UploadExclusions;

trait MediaTrait
{
    /**
     * 处理媒体库图片
     */
    private function process_media_batch()
    {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'"
        );

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

        $processed_items = [];
        foreach ($attachments as $attachment) {
            $this->processed++;

            if (!empty($attachment->is_avatar) && (string) $attachment->is_avatar !== '0') {
                if (\function_exists('update_post_meta')) {
                    \update_post_meta((int) $attachment->ID, $this->type_meta_key, 1);
                }
                $this->success++;
                $processed_items[] = [
                    'success' => true,
                    'original' => \basename((string) $attachment->guid),
                    'new_url' => '',
                    'status' => 'avatar_skipped',
                ];
                continue;
            }

            if (!empty($attachment->lsky_type) && (string) $attachment->lsky_type !== '0') {
                $this->success++;
                $processed_items[] = [
                    'success' => true,
                    'original' => \basename((string) $attachment->guid),
                    'new_url' => '',
                    'status' => 'restricted_skipped',
                ];
                continue;
            }

            if (!empty($attachment->batch_skip) && (string) $attachment->batch_skip !== '0') {
                $this->success++;
                $processed_items[] = [
                    'success' => true,
                    'original' => \basename((string) $attachment->guid),
                    'new_url' => '',
                    'status' => 'excluded_skipped',
                ];
                continue;
            }

            if ($this->is_avatar_attachment((int) $attachment->ID)) {
                \update_post_meta((int) $attachment->ID, $this->avatar_meta_key, 1);
                \update_post_meta((int) $attachment->ID, $this->batch_skip_meta_key, 1);
                \update_post_meta((int) $attachment->ID, $this->type_meta_key, 1);
                $this->success++;
                $processed_items[] = [
                    'success' => true,
                    'original' => \basename((string) $attachment->guid),
                    'new_url' => '',
                    'status' => 'avatar_marked_skipped',
                ];
                continue;
            }

            if (!empty($attachment->lsky_url)) {
                if (\function_exists('update_post_meta') && (empty($attachment->lsky_type) || (string) $attachment->lsky_type === '')) {
                    \update_post_meta((int) $attachment->ID, $this->type_meta_key, 0);
                }
                $this->success++;
                $processed_items[] = [
                    'success' => true,
                    'original' => \basename((string) $attachment->guid),
                    'new_url' => (string) $attachment->lsky_url,
                    'status' => 'already_processed',
                ];
                continue;
            }

            $file = \get_attached_file((int) $attachment->ID);
            if ($file && \file_exists($file)) {
                try {
                    $should_upload = UploadExclusions::shouldUpload(
                        [
                            'file_path' => (string) $file,
                            'mime_type' => '',
                            'attachment_id' => (int) $attachment->ID,
                            'source' => 'media_batch',
                        ],
                        [
                            'doing_ajax' => \function_exists('wp_doing_ajax') ? \wp_doing_ajax() : false,
                            'action' => isset($_REQUEST['action']) ? \sanitize_key((string) $_REQUEST['action']) : '',
                            'context' => isset($_REQUEST['context']) ? \sanitize_key((string) $_REQUEST['context']) : '',
                            'referer' => \function_exists('wp_get_referer') ? (string) \wp_get_referer() : '',
                            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                        ]
                    );

                    if (!$should_upload) {
                        $this->success++;
                        $processed_items[] = [
                            'success' => true,
                            'original' => \basename((string) $file),
                            'new_url' => '',
                            'status' => 'excluded',
                        ];
                        continue;
                    }

                    $new_url = $this->uploader->upload($file);
                    if ($new_url) {
                        \update_post_meta((int) $attachment->ID, '_lsky_pro_url', $new_url);
                        \update_post_meta((int) $attachment->ID, $this->type_meta_key, 0);

                        $photo_id = $this->uploader->getLastUploadedPhotoId();
                        if (\is_numeric($photo_id)) {
                            $photo_id = (int) $photo_id;
                            if ($photo_id > 0) {
                                \update_post_meta((int) $attachment->ID, '_lsky_pro_photo_id', $photo_id);
                            }
                        }

                        $this->success++;
                        $processed_items[] = [
                            'success' => true,
                            'original' => \basename((string) $file),
                            'new_url' => $new_url,
                            'status' => 'newly_processed',
                        ];
                    } else {
                        $this->failed++;
                        $processed_items[] = [
                            'success' => false,
                            'original' => \basename((string) $file),
                            'error' => $this->uploader->getError(),
                            'status' => 'failed',
                        ];
                    }
                } catch (\Exception $e) {
                    $this->failed++;
                    $processed_items[] = [
                        'success' => false,
                        'original' => \basename((string) $file),
                        'error' => $e->getMessage(),
                        'status' => 'failed',
                    ];
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
                '本次处理 %d 张图片，成功 %d 张，失败 %d 张（累计完成 %d/%d）',
                $this->processed,
                $this->success,
                $this->failed,
                $processed_overall,
                $total
            ),
        ];
    }
}
