<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\UploadExclusions;

final class UploadHandler
{
    public function __construct()
    {
        \add_filter('wp_handle_upload', [$this, 'handle_upload'], 10, 1);
        \add_filter('wp_handle_upload_prefilter', [$this, 'pre_upload']);
        \add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        \add_filter('wp_calculate_image_srcset', '__return_false');
        \add_filter('wp_generate_attachment_metadata', [$this, 'attachment_metadata'], 10, 2);
        \add_filter('wp_image_editors', [$this, 'disable_image_editors']);
        \add_action('delete_attachment', [$this, 'handle_delete_attachment'], 10, 1);
    }

    private function debugLog(string $message, $context = null): void
    {
        if (!\defined('WP_DEBUG') || WP_DEBUG !== true) {
            return;
        }

        if ($context !== null) {
            $message .= ' ' . \print_r($context, true);
        }

        \error_log('[LskyPro] ' . (string) $message);
    }

    private function logError(string $filename, string $error): void
    {
        $logDir = LSKY_PRO_PLUGIN_DIR . 'logs';
        $logFile = $logDir . '/error.log';

        if (!\file_exists($logDir)) {
            \wp_mkdir_p($logDir);
        }

        $logMessage = \sprintf(
            "[%s] 失败: %s - 错误: %s（本地文件已保留）\n",
            \date('Y-m-d H:i:s'),
            \basename($filename),
            $error
        );

        \file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    private function addAdminNotice(string $type, string $message): void
    {
        $type = $type === 'error' ? 'error' : 'success';

        \add_action('admin_notices', static function () use ($type, $message): void {
            $class = $type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
            echo '<div class="' . \esc_attr($class) . '">';
            echo '<p>' . \wp_kses_post($message) . '</p>';
            echo '</div>';
        });
    }

    private function getUploadedPhotoIdTransientKey(string $imageUrl): string
    {
        return 'lsky_pro_uploaded_photo_id_' . \md5($imageUrl);
    }

    private function normalizeFilePath(string $path): string
    {
        $p = $path;
        if (\function_exists('wp_normalize_path')) {
            $p = \wp_normalize_path($p);
        }
        return $p;
    }

    private function getPendingUploadTransientKey(string $localFilePath): string
    {
        $p = $this->normalizeFilePath(\trim($localFilePath));
        return 'lsky_pro_pending_upload_' . \md5($p);
    }

    /**
     * @param array<string, mixed> $fileArray
     * @return array<string, mixed>
     */
    public function handle_upload(array $fileArray): array
    {
        $this->debugLog('handle_upload', $fileArray);

        $mimeType = isset($fileArray['type']) ? (string) $fileArray['type'] : '';
        if ($mimeType === '' || !\preg_match('!^image/!', $mimeType)) {
            return $fileArray;
        }

        $localFilePath = isset($fileArray['file']) ? (string) $fileArray['file'] : '';
        if ($localFilePath === '') {
            return $fileArray;
        }

        $shouldUpload = UploadExclusions::shouldUpload(
            [
                'file_path' => $localFilePath,
                'mime_type' => $mimeType,
                'attachment_id' => null,
                'source' => 'upload',
            ],
            [
                'doing_ajax' => \function_exists('wp_doing_ajax') ? \wp_doing_ajax() : false,
                'action' => isset($_REQUEST['action']) ? \sanitize_key((string) $_REQUEST['action']) : '',
                'context' => isset($_REQUEST['context']) ? \sanitize_key((string) $_REQUEST['context']) : '',
                'referer' => \function_exists('wp_get_referer') ? (string) \wp_get_referer() : '',
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
            ]
        );

        if (!$shouldUpload) {
            $this->debugLog('excluded: skip upload to lsky');
            return $fileArray;
        }

        try {
            $uploader = new Uploader();
            $imageUrl = $uploader->upload($localFilePath);

            if ($imageUrl) {
                $this->debugLog('upload success: ' . $imageUrl);

                $photoId = $uploader->getLastUploadedPhotoId();
                $pending = [
                    'url' => (string) $imageUrl,
                    'photo_id' => null,
                    'created_at' => \time(),
                ];

                if (\is_numeric($photoId)) {
                    $photoId = (int) $photoId;
                    if ($photoId > 0) {
                        $pending['photo_id'] = $photoId;
                    }
                }

                \set_transient($this->getPendingUploadTransientKey($localFilePath), $pending, 2 * HOUR_IN_SECONDS);

                \do_action('lsky_pro_upload_success', $imageUrl);

                $this->addAdminNotice('success', '图片已成功上传到图床！URL: ' . \esc_url($imageUrl));
            } else {
                $errorMessage = (string) $uploader->getError();
                if ($errorMessage === '') {
                    $errorMessage = '上传失败: 未知错误';
                }

                $this->debugLog('upload failed: ' . $errorMessage);
                $this->logError($localFilePath, $errorMessage);

                \do_action('lsky_pro_upload_error', $errorMessage);

                $this->addAdminNotice('error', '图床上传失败（图片已保留在本地）: ' . \esc_html($errorMessage));
            }
        } catch (\Exception $e) {
            $this->debugLog('upload exception: ' . $e->getMessage());
            $this->logError($localFilePath, '异常: ' . $e->getMessage());

            \do_action('lsky_pro_upload_exception', $e);

            $this->addAdminNotice('error', '图床上传异常（图片已保留在本地）: ' . \esc_html($e->getMessage()));
        }

        return $fileArray;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function pre_upload(array $file): array
    {
        $this->debugLog('pre_upload', $file);
        return $file;
    }

    public function filter_attachment_url(string $url, int $postId): string
    {
        $lskyUrl = \get_post_meta($postId, '_lsky_pro_url', true);
        return $lskyUrl ? (string) $lskyUrl : $url;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function attachment_metadata(array $metadata, int $attachmentId): array
    {
        UploadExclusions::applyPendingForAttachment((int) $attachmentId);

        $attachedFilePath = \function_exists('get_attached_file') ? (string) \get_attached_file((int) $attachmentId) : '';
        if ($attachedFilePath !== '' && \function_exists('get_transient')) {
            $pendingKey = $this->getPendingUploadTransientKey($attachedFilePath);
            $pending = \get_transient($pendingKey);
            if (\is_array($pending)) {
                $newUrl = isset($pending['url']) ? \trim((string) $pending['url']) : '';
                if ($newUrl !== '') {
                    \update_post_meta($attachmentId, '_lsky_pro_url', $newUrl);
                }

                $photoId = $pending['photo_id'] ?? null;
                if (\is_numeric($photoId)) {
                    $photoId = (int) $photoId;
                    if ($photoId > 0) {
                        \update_post_meta($attachmentId, '_lsky_pro_photo_id', $photoId);
                    }
                }

                if (\function_exists('delete_transient')) {
                    \delete_transient($pendingKey);
                }

                return $metadata;
            }
        }

        $file = (string) \get_post_meta($attachmentId, '_wp_attached_file', true);
        if ($file !== '' && \strpos($file, 'http') === 0) {
            \update_post_meta($attachmentId, '_lsky_pro_url', $file);

            $key = $this->getUploadedPhotoIdTransientKey($file);
            $photoId = \function_exists('get_transient') ? \get_transient($key) : false;
            if (\is_numeric($photoId)) {
                $photoId = (int) $photoId;
                if ($photoId > 0) {
                    \update_post_meta($attachmentId, '_lsky_pro_photo_id', $photoId);
                }
            }
            if (\function_exists('delete_transient')) {
                \delete_transient($key);
            }
        }

        return $metadata;
    }

    public function handle_delete_attachment(int $attachmentId): void
    {
        $photoId = \get_post_meta($attachmentId, '_lsky_pro_photo_id', true);
        $photoId = \absint($photoId);
        if ($photoId <= 0) {
            return;
        }

        try {
            $uploader = new Uploader();
            $uploader->delete_photos([$photoId]);
        } catch (\Exception $e) {
            $this->debugLog('delete remote exception: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    public function disable_image_editors(): array
    {
        return [];
    }
}
