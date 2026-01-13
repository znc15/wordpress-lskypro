<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Batch\AvatarTrait;
use LskyPro\Batch\MediaTrait;
use LskyPro\Batch\PostTrait;
use LskyPro\Batch\ResetTrait;

final class Batch
{

    use AvatarTrait;
    use MediaTrait;
    use PostTrait;
    use ResetTrait;

    private Uploader $uploader;
    private int $processed = 0;
    private int $success = 0;
    private int $failed = 0;

    /**
     * 每批处理的文章数/附件数
     */
    private int $batch_size = 10;

    /**
     * 头像附件标记 meta key（1 表示为头像，批处理跳过）
     */
    private string $avatar_meta_key = '_lsky_pro_is_avatar';

    /**
     * 通用：批处理跳过标记 meta key（1 表示跳过）
     */
    private string $batch_skip_meta_key = '_lsky_pro_batch_skip';

    /**
     * 文件类型标记：0=非限制文件，1=限制文件（例如头像）
     */
    private string $type_meta_key = '_lsky_pro_type';

    /**
     * 文章批处理完成标记 meta key（1 表示该文章已完成批处理）
     */
    private string $post_done_meta_key = '_lsky_pro_post_batch_done';

    public function __construct()
    {
        $this->uploader = new Uploader();

        // 注册 AJAX 处理器
        \add_action('wp_ajax_lsky_pro_process_media_batch', [$this, 'handle_ajax']);
        \add_action('wp_ajax_lsky_pro_process_post_batch', [$this, 'handle_ajax']);
        \add_action('wp_ajax_lsky_pro_reset_post_batch', [$this, 'handle_reset_post_batch']);
        \add_action('wp_ajax_lsky_pro_reset_media_batch', [$this, 'handle_reset_media_batch']);
    }

    /**
     * 处理 AJAX 请求
     */
    public function handle_ajax(): void
    {
        \check_ajax_referer('lsky_pro_batch', 'nonce');

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => '权限不足']);
        }

        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        // 重置计数器
        $this->processed = 0;
        $this->success = 0;
        $this->failed = 0;

        if ($action === 'lsky_pro_process_media_batch') {
            $result = $this->process_media_batch();
        } elseif ($action === 'lsky_pro_process_post_batch') {
            $result = $this->process_batch();
        } else {
            \wp_send_json_error(['message' => '无效的操作类型']);
            return;
        }

        if ($result) {
            \wp_send_json_success($result);
        }

        \wp_send_json_error(['message' => '处理失败']);
    }
}
