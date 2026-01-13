<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\UploadExclusions;
use LskyPro\Support\Options;

final class UploadHandler
{
    private string $uploadLogFile;

    public function __construct()
    {
        $this->uploadLogFile = \rtrim((string) \LSKY_PRO_PLUGIN_DIR, '/\\') . '/logs/upload.log';
        \add_filter('wp_handle_upload', [$this, 'handle_upload'], 10, 1);
        \add_filter('wp_handle_upload_prefilter', [$this, 'pre_upload']);
        \add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        \add_filter('wp_calculate_image_srcset', '__return_false');
        \add_filter('wp_generate_attachment_metadata', [$this, 'attachment_metadata'], 10, 2);
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

    private function writeUploadLog(string $message, array $context = []): void
    {
        $dir = \dirname($this->uploadLogFile);
        if (!\is_dir($dir)) {
            \wp_mkdir_p($dir);
        }

        $time = \date('Y-m-d H:i:s');
        $line = '[' . $time . '] ' . $message;
        if (!empty($context)) {
            $json = \wp_json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (\is_string($json) && $json !== '') {
                $line .= ' ' . $json;
            }
        }
        $line .= "\n";

        @\file_put_contents($this->uploadLogFile, $line, FILE_APPEND | LOCK_EX);
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

        $type = isset($file['type']) ? (string) $file['type'] : '';
        $name = isset($file['name']) ? (string) $file['name'] : '';
        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        $isWebp = (\strtolower($type) === 'image/webp') || (\preg_match('~\.webp$~i', $name) === 1);
        if (!$isWebp) {
            return $file;
        }

        if ($tmp === '' || !\is_file($tmp) || !\is_readable($tmp)) {
            return $file;
        }

        $converted = $this->convertWebpToJpegOrPng($tmp, $name);
        if ($converted === null) {
            // Keep original; WordPress may still show warning, but we did best effort.
            return $file;
        }

        // Point upload to the converted temporary file.
        $file['tmp_name'] = $converted['tmp_name'];
        $file['name'] = $converted['name'];
        $file['type'] = $converted['type'];
        if (isset($file['size']) && \is_numeric($file['size'])) {
            $file['size'] = (int) \filesize($converted['tmp_name']);
        }

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

                $this->maybeDeleteLocalFilesAfterLskyUpload($attachmentId, $metadata, $attachedFilePath);

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

        $this->maybeDeleteLocalFilesAfterLskyUpload($attachmentId, $metadata, $attachedFilePath);

        return $metadata;
    }

    private function maybeDeleteLocalFilesAfterLskyUpload(int $attachmentId, array $metadata, string $attachedFilePath): void
    {
        $options = Options::normalized();
        $enabled = !empty($options['delete_local_files_after_upload']);
        if (!$enabled) {
            return;
        }

        $lskyUrl = (string) \get_post_meta($attachmentId, '_lsky_pro_url', true);
        if ($lskyUrl === '') {
            $this->writeUploadLog('upload_cleanup: skip (no lsky url)', ['attachment_id' => $attachmentId]);
            return;
        }

        if ($attachedFilePath === '') {
            $this->writeUploadLog('upload_cleanup: skip (no attached file path)', ['attachment_id' => $attachmentId, 'lsky_url' => $lskyUrl]);
            return;
        }

        // Safety: only delete files under uploads basedir.
        $uploads = \wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($basedir === '') {
            $this->writeUploadLog('upload_cleanup: skip (no uploads basedir)', ['attachment_id' => $attachmentId, 'lsky_url' => $lskyUrl]);
            return;
        }

        $norm = \function_exists('wp_normalize_path') ? \wp_normalize_path($attachedFilePath) : $attachedFilePath;
        $baseNorm = \function_exists('wp_normalize_path') ? \wp_normalize_path($basedir) : $basedir;
        $normCmp = $norm;
        $baseCmp = $baseNorm;
        if (\DIRECTORY_SEPARATOR === '\\') {
            $normCmp = \strtolower($normCmp);
            $baseCmp = \strtolower($baseCmp);
        }
        if ($baseCmp !== '' && \strpos($normCmp, $baseCmp) !== 0) {
            $this->writeUploadLog('upload_cleanup: skip (not under uploads)', ['attachment_id' => $attachmentId, 'attached_file' => $norm, 'uploads_basedir' => $baseNorm]);
            return;
        }

        $filesToDelete = [];
        if (\is_file($attachedFilePath)) {
            $filesToDelete[] = $attachedFilePath;
        }

        // Delete intermediate sizes if present.
        if (isset($metadata['sizes']) && \is_array($metadata['sizes'])) {
            $dir = \dirname($attachedFilePath);
            foreach ($metadata['sizes'] as $size) {
                if (!\is_array($size) || empty($size['file'])) {
                    continue;
                }
                $fn = (string) $size['file'];
                if ($fn === '') {
                    continue;
                }
                $p = $dir . DIRECTORY_SEPARATOR . $fn;
                if (\is_file($p)) {
                    $filesToDelete[] = $p;
                }
            }
        }

        $filesToDelete = \array_values(\array_unique($filesToDelete));
        if (empty($filesToDelete)) {
            $this->writeUploadLog('upload_cleanup: skip (no local files found)', ['attachment_id' => $attachmentId, 'attached_file' => $norm]);
            return;
        }

        $this->writeUploadLog('upload_cleanup: deleting local files', ['attachment_id' => $attachmentId, 'files' => $filesToDelete, 'lsky_url' => $lskyUrl]);
        foreach ($filesToDelete as $p) {
            // Use WP helper if present.
            if (\function_exists('wp_delete_file')) {
                @\wp_delete_file($p);
            }
            if (\is_file($p)) {
                @\unlink($p);
            }
        }

        $remaining = [];
        foreach ($filesToDelete as $p) {
            if (\is_file($p)) {
                $remaining[] = $p;
            }
        }
        $this->writeUploadLog('upload_cleanup: done', ['attachment_id' => $attachmentId, 'remaining' => $remaining]);

        // Optional: mark attached file missing to avoid future file ops.
        // We keep _wp_attached_file as-is for URL generation, but local disk file is removed.
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
     * @return array{tmp_name:string,name:string,type:string}|null
     */
    private function convertWebpToJpegOrPng(string $tmpFile, string $originalName): ?array
    {
        $targetBase = $originalName !== '' ? (string) \pathinfo($originalName, PATHINFO_FILENAME) : 'image';
        $targetBase = $targetBase !== '' ? $targetBase : 'image';

        $prefer = (string) \apply_filters('lsky_pro_webp_convert_format', 'jpg');
        $prefer = \strtolower(\trim($prefer));
        if (!\in_array($prefer, ['jpg', 'jpeg', 'png'], true)) {
            $prefer = 'jpg';
        }

        $createTmp = static function (string $suffix): string {
            $t = \function_exists('wp_tempnam') ? (string) \wp_tempnam('lskypro_' . $suffix . '_') : '';
            if ($t === '' || !\is_string($t)) {
                $t = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . \uniqid('lskypro_' . $suffix . '_', true);
            }
            return $t;
        };

        // Try GD first.
        if (\function_exists('imagecreatefromwebp')) {
            $img = @\imagecreatefromwebp($tmpFile);
            if ($img !== false) {
                $writeJpeg = static function ($imgRes, string $dest): bool {
                    if (!\function_exists('imagejpeg')) {
                        return false;
                    }
                    if (\function_exists('imageinterlace')) {
                        @\imageinterlace($imgRes, true);
                    }
                    return @\imagejpeg($imgRes, $dest, 90);
                };

                $writePng = static function ($imgRes, string $dest): bool {
                    if (!\function_exists('imagepng')) {
                        return false;
                    }
                    if (\function_exists('imagesavealpha')) {
                        @\imagesavealpha($imgRes, true);
                    }
                    return @\imagepng($imgRes, $dest);
                };

                $order = $prefer === 'png' ? ['png', 'jpg'] : ['jpg', 'png'];
                foreach ($order as $fmt) {
                    $dest = $createTmp($fmt);
                    $ok = $fmt === 'png' ? $writePng($img, $dest) : $writeJpeg($img, $dest);
                    if ($ok && \is_file($dest) && \filesize($dest) > 0) {
                        @\imagedestroy($img);
                        $ext = $fmt === 'png' ? 'png' : 'jpg';
                        return [
                            'tmp_name' => $dest,
                            'name' => $targetBase . '.' . $ext,
                            'type' => $fmt === 'png' ? 'image/png' : 'image/jpeg',
                        ];
                    }
                    if (\is_file($dest)) {
                        @\unlink($dest);
                    }
                }

                @\imagedestroy($img);
            }
        }

        // Fallback to WordPress image editor if available.
        if (\function_exists('wp_get_image_editor')) {
            $editor = \wp_get_image_editor($tmpFile);
            if (!\is_wp_error($editor)) {
                $fmt = $prefer === 'png' ? 'png' : 'jpeg';
                $dest = $createTmp($fmt);
                $saved = $editor->save($dest, $fmt);
                if (\is_array($saved) && isset($saved['path']) && \is_file((string) $saved['path'])) {
                    $path = (string) $saved['path'];
                    $mime = isset($saved['mime-type']) ? (string) $saved['mime-type'] : ($fmt === 'png' ? 'image/png' : 'image/jpeg');
                    $ext = $fmt === 'png' ? 'png' : 'jpg';
                    return [
                        'tmp_name' => $path,
                        'name' => $targetBase . '.' . $ext,
                        'type' => $mime,
                    ];
                }
                if (\is_file($dest)) {
                    @\unlink($dest);
                }
            }
        }

        return null;
    }
}
