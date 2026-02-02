<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\UploadExclusions;
use LskyPro\Support\RemoteDownloader;
use LskyPro\Support\Logger;

final class Remote
{
    /** @var Uploader */
    private $uploader;

    /** @var string|null */
    private $error;

    /** @var int */
    private $processed = 0;

    /** @var int */
    private $failed = 0;

    public function __construct()
    {
        $this->uploader = new Uploader();
    }

    /**
     * @return bool
     */
    public function process_post_images($postId)
    {
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(0);
        }

        $postId = (int) $postId;
        if ($postId <= 0) {
            $this->error = '无效的文章ID';
            return false;
        }

        $lockMetaKey = '_lsky_pro_remote_processing_lock';
        $lockTtlSeconds = 10 * 60;
        $now = \time();
        $existingLock = \get_post_meta($postId, $lockMetaKey, true);
        if (\is_numeric($existingLock)) {
            $existingLock = (int) $existingLock;
            if ($existingLock > 0 && ($now - $existingLock) < $lockTtlSeconds) {
                $this->error = '文章正在处理远程图片，请稍后重试';
                Logger::debug("文章 {$postId} 正在处理中，跳过本次处理", [], 'remote');
                return false;
            }
        }

        \delete_post_meta($postId, $lockMetaKey);
        if (!\add_post_meta($postId, $lockMetaKey, (string) $now, true)) {
            $this->error = '文章正在处理远程图片，请稍后重试';
            Logger::warning('remote', "文章 {$postId} 加锁失败，可能并发处理中");
            return false;
        }

        try {
            if ($this->uploader && \method_exists($this->uploader, 'setUploadLogContextFromPost')) {
                $this->uploader->setUploadLogContextFromPost($postId, 'post_remote_images');
            }

            $content = \get_post_field('post_content', $postId);
            if (empty($content)) {
                $this->error = '文章内容为空';
                Logger::warning('remote', "文章 {$postId} 内容为空");
                return false;
            }

            Logger::debug("开始处理文章 {$postId} 的远程图片", [], 'remote');

            $pattern = '/<img[^>]+src=([\'\"])(https?:\/\/[^>]+?)\1[^>]*>/i';
            $siteUrl = \get_site_url();
            $uploads = \wp_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
            $updated = false;

            $processedUrls = \get_post_meta($postId, '_lsky_pro_processed_urls', true);
            if (!\is_array($processedUrls)) {
                $processedUrls = [];
            }

            $processedPhotoIds = \get_post_meta($postId, '_lsky_pro_processed_photo_ids', true);
            if (!\is_array($processedPhotoIds)) {
                $processedPhotoIds = [];
            }

            $persistProcessedUrls = static function () use ($postId, &$processedUrls): void {
                \update_post_meta($postId, '_lsky_pro_processed_urls', $processedUrls);
            };

            $persistProcessedPhotoIds = static function () use ($postId, &$processedPhotoIds): void {
                \update_post_meta($postId, '_lsky_pro_processed_photo_ids', $processedPhotoIds);
            };

            if (\preg_match_all($pattern, $content, $matches)) {
                Logger::debug('在文章 ' . $postId . ' 中找到 ' . \count($matches[2]) . ' 个图片', [], 'remote');

                foreach ($matches[2] as $url) {
                    Logger::debug('处理图片URL: ' . $url, [], 'remote');

                    $urlClean = (string) $url;
                    $parsed = \wp_parse_url($urlClean);
                    if (\is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host']) && !empty($parsed['path'])) {
                        $urlClean = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
                    }

                    if (isset($processedUrls[$urlClean])) {
                        Logger::debug('找到已处理图片的图床地址: ' . $processedUrls[$urlClean], [], 'remote');
                        if ($url !== $processedUrls[$urlClean]) {
                            $content = \str_replace($url, $processedUrls[$urlClean], $content);
                            $updated = true;
                            Logger::debug('替换为图床地址', [], 'remote');
                        }

                        // If we previously recorded photo_id for this URL, keep it.
                        if (isset($processedPhotoIds[$urlClean]) && \is_numeric($processedPhotoIds[$urlClean])) {
                            $persistProcessedPhotoIds();
                        }
                        continue;
                    }

                    if (\strpos($urlClean, $siteUrl) !== false && !$this->isLskyUrl($urlClean) && $baseurl !== '' && \strpos($urlClean, $baseurl) === 0) {
                        Logger::debug('检测到本站媒体图片，准备上传: ' . $urlClean, [], 'remote');

                        $attachmentId = \attachment_url_to_postid($urlClean);

                        $newUrl = $this->processLocalMediaImage($urlClean);
                        if ($newUrl) {
                            Logger::debug('本站媒体图片上传成功，新URL: ' . $newUrl, [], 'remote');
                            $content = \str_replace($url, $newUrl, $content);
                            $processedUrls[$urlClean] = $newUrl;
                            $persistProcessedUrls();

                            $photoId = 0;
                            if (\is_numeric($attachmentId) && (int) $attachmentId > 0) {
                                $photoId = (int) \absint((string) \get_post_meta((int) $attachmentId, '_lsky_pro_photo_id', true));
                            }
                            if ($photoId <= 0) {
                                $last = $this->uploader->getLastUploadedPhotoId();
                                if (\is_numeric($last)) {
                                    $last = (int) $last;
                                    if ($last > 0) {
                                        $photoId = $last;
                                    }
                                }
                            }

                            if ($photoId > 0) {
                                $processedPhotoIds[$urlClean] = $photoId;
                                $persistProcessedPhotoIds();
                            }

                            $this->processed++;
                            $updated = true;
                        } else {
                            Logger::warning('remote', '本站媒体图片处理失败: ' . (string) $this->error);
                            $this->failed++;
                        }

                        continue;
                    }

                    if (\strpos($urlClean, $siteUrl) === false && !$this->isLskyUrl($urlClean)) {
                        Logger::debug('检测到外链图片，准备上传: ' . $url, [], 'remote');

                        $newUrl = $this->processRemoteImage($url);
                        if ($newUrl) {
                            Logger::debug('图片上传成功，新URL: ' . $newUrl, [], 'remote');
                            $content = \str_replace($url, $newUrl, $content);
                            $processedUrls[$urlClean] = $newUrl;
                            $persistProcessedUrls();

                            $photoId = $this->uploader->getLastUploadedPhotoId();
                            if (\is_numeric($photoId)) {
                                $photoId = (int) $photoId;
                                if ($photoId > 0) {
                                    $processedPhotoIds[$urlClean] = $photoId;
                                    $persistProcessedPhotoIds();
                                }
                            }

                            $this->processed++;
                            $updated = true;
                        } else {
                            Logger::warning('remote', '图片处理失败: ' . (string) $this->error);
                            $this->failed++;
                        }
                    } else {
                        Logger::debug('跳过本站或图床图片: ' . $url, [], 'remote');
                        $processedUrls[$urlClean] = $url;
                        $persistProcessedUrls();
                    }
                }
            } else {
                Logger::debug('文章 ' . $postId . ' 中未找到需要处理的图片', [], 'remote');
            }

            $persistProcessedUrls();
            $persistProcessedPhotoIds();

            if ($updated) {
                Logger::debug('更新文章 ' . $postId . ' 内容', [], 'remote');

                global $wpdb;

                $modified = \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
                $modifiedGmt = \function_exists('current_time') ? \current_time('mysql', true) : \gmdate('Y-m-d H:i:s');

                $updatedRows = $wpdb->update(
                    $wpdb->posts,
                    [
                        'post_content' => $content,
                        'post_modified' => $modified,
                        'post_modified_gmt' => $modifiedGmt,
                    ],
                    ['ID' => $postId],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                if ($updatedRows === false) {
                    Logger::warning('remote', '更新文章 ' . $postId . ' 失败 - 数据库更新失败');
                } else {
                    if (\function_exists('clean_post_cache')) {
                        \clean_post_cache($postId);
                    }
                }

                Logger::debug('文章处理完成 - 成功: ' . $this->processed . ', 失败: ' . $this->failed, [], 'remote');
            }

            return true;
        } finally {
            if ($this->uploader && \method_exists($this->uploader, 'clearUploadLogContext')) {
                $this->uploader->clearUploadLogContext();
            }
            \delete_post_meta($postId, $lockMetaKey);
        }
    }

    public function process_zib_other_data(int $postId): bool
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return false;
        }

        $raw = \get_post_meta($postId, 'zib_other_data', true);
        if ($raw === '' || $raw === null) {
            return false;
        }

        $data = \maybe_unserialize($raw);
        if (!\is_array($data)) {
            return false;
        }

        $processedUrls = \get_post_meta($postId, '_lsky_pro_processed_urls', true);
        if (!\is_array($processedUrls)) {
            $processedUrls = [];
        }

        $processedPhotoIds = \get_post_meta($postId, '_lsky_pro_processed_photo_ids', true);
        if (!\is_array($processedPhotoIds)) {
            $processedPhotoIds = [];
        }

        $siteUrl = \function_exists('get_site_url') ? (string) \get_site_url() : '';
        $uploads = \function_exists('wp_upload_dir') ? \wp_upload_dir() : [];
        $baseurl = \is_array($uploads) && isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

        $normalize = static function (string $url): string {
            $url = \html_entity_decode($url);
            $url = \trim($url);
            if ($url === '') {
                return '';
            }
            $parts = \function_exists('wp_parse_url') ? \wp_parse_url($url) : \parse_url($url);
            if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
                return $url;
            }
            $norm = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
            if (!empty($parts['port'])) {
                $norm = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . $parts['path'];
            }
            return $norm;
        };

        $changed = false;
        foreach (['cover_image', 'thumbnail_url'] as $key) {
            if (!isset($data[$key]) || !\is_string($data[$key])) {
                continue;
            }

            $original = $data[$key];
            $urlClean = $normalize($original);
            if ($urlClean === '') {
                continue;
            }

            if (isset($processedUrls[$urlClean])) {
                $data[$key] = $processedUrls[$urlClean];
                if ($data[$key] !== $original) {
                    $changed = true;
                }
                continue;
            }

            if ($this->isLskyUrl($urlClean)) {
                $processedUrls[$urlClean] = $original;
                continue;
            }

            $isLocal = $siteUrl !== '' && $baseurl !== '' && \strpos($urlClean, $siteUrl) !== false && \strpos($urlClean, $baseurl) === 0;
            $newUrl = $isLocal ? $this->processLocalMediaImage($urlClean) : $this->processRemoteImage($original);

            if ($newUrl) {
                $data[$key] = $newUrl;
                $processedUrls[$urlClean] = $newUrl;
                if ($newUrl !== $original) {
                    $changed = true;
                }

                $photoId = 0;
                if ($isLocal && \function_exists('attachment_url_to_postid')) {
                    $aid = (int) \attachment_url_to_postid($urlClean);
                    if ($aid > 0) {
                        $photoId = (int) \absint((string) \get_post_meta($aid, '_lsky_pro_photo_id', true));
                    }
                }
                if ($photoId <= 0 && $this->uploader && \method_exists($this->uploader, 'getLastUploadedPhotoId')) {
                    $last = $this->uploader->getLastUploadedPhotoId();
                    if (\is_numeric($last) && (int) $last > 0) {
                        $photoId = (int) $last;
                    }
                }
                if ($photoId > 0) {
                    $processedPhotoIds[$urlClean] = $photoId;
                }
            } else {
                Logger::warning('remote', 'zib_other_data 图片处理失败: ' . (string) $this->error, ['post_id' => $postId]);
            }
        }

        if ($changed) {
            \update_post_meta($postId, 'zib_other_data', $data);
        }

        \update_post_meta($postId, '_lsky_pro_processed_urls', $processedUrls);
        \update_post_meta($postId, '_lsky_pro_processed_photo_ids', $processedPhotoIds);

        return $changed;
    }

    protected function processLocalMediaImage(string $url)
    {
        $attachmentId = \attachment_url_to_postid($url);
        $filePath = '';

        if (\is_numeric($attachmentId) && (int) $attachmentId > 0) {
            $attachmentId = (int) $attachmentId;

            $existingUrl = \get_post_meta($attachmentId, '_lsky_pro_url', true);
            if (\is_string($existingUrl) && $existingUrl !== '') {
                return $existingUrl;
            }

            $filePath = \get_attached_file($attachmentId);
        }

        if (!\is_string($filePath) || $filePath === '' || !\file_exists($filePath)) {
            $uploads = \wp_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
            $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            if ($baseurl !== '' && $basedir !== '' && \strpos($url, $baseurl) === 0) {
                $relative = \substr($url, \strlen($baseurl));
                $relative = \ltrim($relative, '/');
                $filePath = \rtrim($basedir, '/\\') . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            }
        }

        if (!\is_string($filePath) || $filePath === '' || !\file_exists($filePath)) {
            $this->error = '无法定位本地文件路径';
            return false;
        }

        $shouldUpload = UploadExclusions::shouldUpload(
            [
                'file_path' => $filePath,
                'mime_type' => '',
                'attachment_id' => (isset($attachmentId) && \is_int($attachmentId) && $attachmentId > 0) ? $attachmentId : null,
                'source' => 'post_local_media',
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
            return $url;
        }

        $newUrl = $this->uploader->upload($filePath, $url);
        if (!$newUrl) {
            $this->error = (string) $this->uploader->getError();
            return false;
        }

        if (isset($attachmentId) && \is_int($attachmentId) && $attachmentId > 0) {
            \update_post_meta($attachmentId, '_lsky_pro_url', $newUrl);
            $photoId = $this->uploader->getLastUploadedPhotoId();
            if (\is_numeric($photoId)) {
                $photoId = (int) $photoId;
                if ($photoId > 0) {
                    \update_post_meta($attachmentId, '_lsky_pro_photo_id', $photoId);
                }
            }
        }

        return $newUrl;
    }

    protected function isLskyUrl(string $url): bool
    {
        $options = \get_option('lsky_pro_options');
        $apiUrl = \is_array($options) && isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';

        if ($apiUrl === '') {
            Logger::warning('remote', '未配置图床API URL');
            return false;
        }

        $apiDomain = \parse_url($apiUrl, PHP_URL_HOST);
        $urlDomain = \parse_url($url, PHP_URL_HOST);

        $isLsky = $apiDomain === $urlDomain;
        Logger::debug('检查URL ' . $url . ' 是否为图床URL: ' . ($isLsky ? '是' : '否'), [], 'remote');

        return $isLsky;
    }

    protected function processRemoteImage(string $url)
    {
        Logger::debug('开始处理远程图片: ' . $url, [], 'remote');

        $tempFile = $this->downloadImage($url);
        if (!$tempFile) {
            Logger::warning('remote', '下载图片失败: ' . (string) $this->error);
            return false;
        }

        Logger::debug('图片下载成功，临时文件: ' . $tempFile, [], 'remote');

        $newUrl = $this->uploader->upload($tempFile, $url);

        @\unlink($tempFile);
        Logger::debug('清理临时文件: ' . $tempFile, [], 'remote');

        if (!$newUrl) {
            $this->error = (string) $this->uploader->getError();
            Logger::warning('remote', '上传到图床失败: ' . (string) $this->error);
            return false;
        }

        Logger::debug('上传到图床成功，新URL: ' . $newUrl, [], 'remote');
        return $newUrl;
    }

    private function downloadImage(string $url)
    {
        $maxBytes = 0;
        if ($this->uploader && \method_exists($this->uploader, 'get_max_upload_size_bytes')) {
            $maxBytes = (int) $this->uploader->get_max_upload_size_bytes();
        }

        $result = RemoteDownloader::downloadImage($url, [
            'timeout' => 30,
            'redirection' => 3,
            'max_bytes' => $maxBytes,
            // 旧实现使用浏览器 UA；这里保持一个通用 UA，便于部分站点放行。
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        if (\is_wp_error($result)) {
            $this->error = (string) $result->get_error_message();
            Logger::warning('remote', '下载远程图片失败: ' . (string) $this->error);
            return false;
        }

        $tempFile = isset($result['file']) ? (string) $result['file'] : '';
        if ($tempFile === '' || !\is_file($tempFile)) {
            $this->error = '下载远程图片失败：临时文件不存在';
            Logger::warning('remote', (string) $this->error);
            return false;
        }

        Logger::debug('图片下载成功', [], 'remote');
        return $tempFile;
    }

    /**
     * @return array{processed:int,failed:int}
     */
    public function get_results(): array
    {
        return [
            'processed' => $this->processed,
            'failed' => $this->failed,
        ];
    }

    public function getError()
    {
        return $this->error;
    }
}
