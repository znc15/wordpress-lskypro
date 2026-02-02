<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Batch\AvatarTrait;
use LskyPro\Batch\MediaTrait;
use LskyPro\Batch\PostTrait;
use LskyPro\Batch\ResetTrait;
use LskyPro\Support\Logger;
use LskyPro\Support\Queue;

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
        \add_action('wp_ajax_lsky_pro_stop_batch', [$this, 'handle_stop_batch']);
        \add_action('wp_ajax_lsky_pro_reset_post_batch', [$this, 'handle_reset_post_batch']);
        \add_action('wp_ajax_lsky_pro_reset_media_batch', [$this, 'handle_reset_media_batch']);

        // Async worker (Action Scheduler / WP-Cron)
        \add_action('lsky_pro_batch_worker', [$this, 'handle_batch_worker'], 10, 1);
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

        $type = '';
        if ($action === 'lsky_pro_process_media_batch') {
            $type = 'media';
        } elseif ($action === 'lsky_pro_process_post_batch') {
            $type = 'post';
        } else {
            \wp_send_json_error(['message' => '无效的操作类型']);
            return;
        }

        // Async mode: enqueue worker and return latest state for polling.
        if ($this->isAsyncBatchEnabled()) {
            $stateKey = $this->batchStateKey($type);
            $runningKey = $this->batchRunningKey($type);

            $running = \function_exists('get_transient') ? (bool) \get_transient($runningKey) : false;
            $state = \function_exists('get_transient') ? \get_transient($stateKey) : false;
            if (!\is_array($state)) {
                $state = $this->emptyBatchState($type);
            }

            if (!$running) {
                $this->clearBatchAsyncState($type);
                if (\function_exists('set_transient')) {
                    \set_transient($runningKey, 1, \HOUR_IN_SECONDS);
                }
                Queue::enqueue('lsky_pro_batch_worker', [$type], 0);
                $state['queued'] = true;
                $state['message'] = ($type === 'media' ? '媒体库批处理已加入后台队列，正在处理中...' : '文章批处理已加入后台队列，正在处理中...');
                Logger::debug('batch: queued worker', ['type' => $type], 'batch');
            }

            $state['async'] = true;
            $state['running'] = (bool) (\function_exists('get_transient') ? \get_transient($runningKey) : false);

            \wp_send_json_success($state);
            return;
        }

        // Sync mode (fallback)
        $this->processed = 0;
        $this->success = 0;
        $this->failed = 0;

        $result = ($type === 'media') ? $this->process_media_batch() : $this->process_batch();
        if ($result) {
            \wp_send_json_success($result);
        }

        \wp_send_json_error(['message' => '处理失败']);
    }

    public function handle_stop_batch(): void
    {
        \check_ajax_referer('lsky_pro_batch', 'nonce');

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => '权限不足']);
        }

        $type = isset($_POST['type']) ? \sanitize_key((string) $_POST['type']) : '';
        if (!\in_array($type, ['media', 'post'], true)) {
            \wp_send_json_error(['message' => '无效的批处理类型']);
        }

        if (\function_exists('set_transient')) {
            \set_transient($this->batchStopKey($type), 1, \HOUR_IN_SECONDS);
        }
        if (\function_exists('delete_transient')) {
            \delete_transient($this->batchRunningKey($type));
        }

        Logger::debug('batch: stop requested', ['type' => $type], 'batch');
        \wp_send_json_success(['message' => '已请求停止处理']);
    }

    public function handle_batch_worker(string $type): void
    {
        $type = \sanitize_key((string) $type);
        if (!\in_array($type, ['media', 'post'], true)) {
            return;
        }

        $stopKey = $this->batchStopKey($type);
        if (\function_exists('get_transient') && \get_transient($stopKey)) {
            $stateKey = $this->batchStateKey($type);
            $state = \function_exists('get_transient') ? \get_transient($stateKey) : false;
            if (!\is_array($state)) {
                $state = $this->emptyBatchState($type);
            }
            $state['async'] = true;
            $state['queued'] = false;
            $state['running'] = false;
            $state['message'] = '已停止处理';
            $state['updated_at'] = \time();
            if (\function_exists('set_transient')) {
                \set_transient($stateKey, $state, \HOUR_IN_SECONDS);
            }

            $this->clearBatchAsyncFlags($type);
            Logger::debug('batch: stopped by flag', ['type' => $type], 'batch');
            return;
        }

        // Reset counters
        $this->processed = 0;
        $this->success = 0;
        $this->failed = 0;

        $result = ($type === 'media') ? $this->process_media_batch() : $this->process_batch();
        if (!\is_array($result)) {
            $result = $this->emptyBatchState($type);
            $result['message'] = '批处理执行失败（无返回结果）';
            $result['completed'] = true;
        }

        $stateKey = $this->batchStateKey($type);
        $prev = \function_exists('get_transient') ? \get_transient($stateKey) : false;
        $seq = (\is_array($prev) && isset($prev['seq'])) ? ((int) \absint((string) $prev['seq']) + 1) : 1;

        $result['async'] = true;
        $result['queued'] = false;
        $result['running'] = true;
        $result['seq'] = $seq;
        $result['updated_at'] = \time();

        if (\function_exists('set_transient')) {
            \set_transient($stateKey, $result, \HOUR_IN_SECONDS);
        }

        $completed = !empty($result['completed']);
        if ($completed) {
            $this->clearBatchAsyncFlags($type);
            Logger::debug('batch: completed', ['type' => $type, 'seq' => $seq], 'batch');
            return;
        }

        // Continue processing in background.
        Queue::enqueue('lsky_pro_batch_worker', [$type], 2);
        Logger::debug('batch: scheduled next worker', ['type' => $type, 'seq' => $seq], 'batch');
    }

    private function isAsyncBatchEnabled(): bool
    {
        $enabled = true;
        if (\function_exists('apply_filters')) {
            $enabled = (bool) \apply_filters('lsky_pro_enable_async_batch', $enabled);
        }
        return $enabled;
    }

    private function batchStateKey(string $type): string
    {
        return 'lsky_pro_batch_state_' . $type;
    }

    private function batchRunningKey(string $type): string
    {
        return 'lsky_pro_batch_running_' . $type;
    }

    private function batchStopKey(string $type): string
    {
        return 'lsky_pro_batch_stop_' . $type;
    }

    private function clearBatchAsyncState(string $type): void
    {
        if (!\function_exists('delete_transient')) {
            return;
        }
        \delete_transient($this->batchStateKey($type));
        \delete_transient($this->batchRunningKey($type));
        \delete_transient($this->batchStopKey($type));
    }

    private function clearBatchAsyncFlags(string $type): void
    {
        if (!\function_exists('delete_transient')) {
            return;
        }
        \delete_transient($this->batchRunningKey($type));
        \delete_transient($this->batchStopKey($type));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBatchState(string $type): array
    {
        return [
            'async' => true,
            'queued' => true,
            'running' => false,
            'seq' => 0,
            'updated_at' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'total' => 0,
            'completed' => false,
            'processed_items' => [],
            'message' => $type === 'media' ? '媒体库批处理等待开始' : '文章批处理等待开始',
        ];
    }
}
