<?php

declare(strict_types=1);

namespace LskyPro\Support;

use WP_Error;

final class RemoteDownloader
{
    /**
     * 安全下载远程图片到临时文件（stream 落盘、限制大小、校验 Content-Type、阻断不安全 URL）。
     *
     * 注意：返回的文件名会带扩展名，便于后续上传时通过扩展名校验。
     *
     * @param array{
     *   timeout?:int,
     *   redirection?:int,
     *   max_bytes?:int,
     *   sslverify?:bool,
     *   user_agent?:string,
     *   headers?:array<string,string>,
     *   allowed_ports?:array<int,int>
     * } $args
     *
     * @return array{file:string,content_type:string,size:int,url:string}|WP_Error
     */
    public static function downloadImage(string $url, array $args = [])
    {
        $url = \trim(\html_entity_decode($url));
        if ($url === '') {
            return new WP_Error('lsky_pro_remote_empty_url', '远程图片 URL 为空');
        }

        if (\function_exists('wp_http_validate_url')) {
            $validated = \wp_http_validate_url($url);
            if ($validated === false) {
                return new WP_Error('lsky_pro_remote_invalid_url', '远程图片 URL 非法');
            }
            $url = (string) $validated;
        }

        $parts = \function_exists('wp_parse_url') ? \wp_parse_url($url) : \parse_url($url);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return new WP_Error('lsky_pro_remote_invalid_url', '远程图片 URL 解析失败');
        }

        $scheme = \strtolower((string) $parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('lsky_pro_remote_invalid_scheme', '仅支持 http/https 远程图片');
        }

        $host = \strtolower((string) $parts['host']);
        if ($host === 'localhost') {
            return new WP_Error('lsky_pro_remote_blocked_host', '已拒绝下载 localhost 地址');
        }

        // 若 host 为 IP 字面量，阻断内网/保留地址。域名解析的拦截由 wp_safe_remote_* 负责。
        if (\filter_var($host, \FILTER_VALIDATE_IP)) {
            $public = \filter_var(
                $host,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                return new WP_Error('lsky_pro_remote_blocked_ip', '已拒绝下载内网/保留地址');
            }
        }

        $allowedPorts = $args['allowed_ports'] ?? [80, 443];
        if (\function_exists('apply_filters')) {
            $allowedPorts = (array) \apply_filters('lsky_pro_remote_allowed_ports', $allowedPorts, $url);
        }
        $allowedPorts = \array_values(\array_unique(\array_map('absint', (array) $allowedPorts)));
        $allowedPorts = \array_values(\array_filter($allowedPorts, static function (int $p): bool {
            return $p > 0;
        }));
        if (empty($allowedPorts)) {
            $allowedPorts = [80, 443];
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $effectivePort = $port > 0 ? $port : ($scheme === 'https' ? 443 : 80);
        if (!\in_array($effectivePort, $allowedPorts, true)) {
            return new WP_Error('lsky_pro_remote_blocked_port', '远程图片端口不在允许列表');
        }

        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : 30;
        if ($timeout < 1) {
            $timeout = 30;
        }

        $redirection = isset($args['redirection']) ? (int) $args['redirection'] : 3;
        if ($redirection < 0) {
            $redirection = 3;
        }

        $maxBytes = isset($args['max_bytes']) ? (int) $args['max_bytes'] : 0;
        if ($maxBytes <= 0) {
            $maxBytes = 20 * 1024 * 1024;
        }
        if (\function_exists('apply_filters')) {
            $maxBytes = (int) \apply_filters('lsky_pro_remote_max_bytes', $maxBytes, $url);
            if ($maxBytes <= 0) {
                $maxBytes = 20 * 1024 * 1024;
            }
        }

        $sslverify = isset($args['sslverify']) ? (bool) $args['sslverify'] : ($scheme === 'https');
        if (\function_exists('apply_filters')) {
            // 全局 filter：也用于 cURL 上传。
            $sslverify = (bool) \apply_filters('lsky_pro_sslverify', $sslverify);
            // 远程下载专用 filter：用于仅调整远程图片下载策略。
            $sslverify = (bool) \apply_filters('lsky_pro_remote_sslverify', $sslverify, $url, $args);
        }

        $userAgent = isset($args['user_agent']) ? (string) $args['user_agent'] : 'WordPress/LskyPro-RemoteDownloader';
        if ($userAgent === '') {
            $userAgent = 'WordPress/LskyPro-RemoteDownloader';
        }

        $headers = [
            'Accept' => 'image/*',
            'User-Agent' => $userAgent,
        ];
        if (isset($args['headers']) && \is_array($args['headers'])) {
            foreach ($args['headers'] as $k => $v) {
                $k = (string) $k;
                if ($k === '') {
                    continue;
                }
                $headers[$k] = (string) $v;
            }
        }

        $tmpDir = self::getTempDir();
        if ($tmpDir === '') {
            return new WP_Error('lsky_pro_remote_tmpdir', '无法获取临时目录');
        }
        $tmpDir = \rtrim($tmpDir, '/\\');

        // 先用 .tmp 落盘，下载完成后再按 Content-Type 生成最终带扩展名的临时文件。
        $tmpBase = \str_replace('.', '', \uniqid('lskypro_remote_', true));
        $tmpPath = $tmpDir . \DIRECTORY_SEPARATOR . $tmpBase . '.tmp';

        $dir = \dirname($tmpPath);
        if (!\is_dir($dir) && \function_exists('wp_mkdir_p')) {
            \wp_mkdir_p($dir);
        }

        $requestArgs = [
            'timeout' => $timeout,
            'redirection' => $redirection,
            'sslverify' => $sslverify,
            'stream' => true,
            'filename' => $tmpPath,
            'headers' => $headers,
            // 强制启用 WP 的安全 URL 检查（阻断 SSRF/内网等）。
            'reject_unsafe_urls' => true,
            // WordPress 5.4+ 支持；旧版本会忽略该参数。
            'limit_response_size' => $maxBytes,
        ];

        $resp = \function_exists('wp_safe_remote_get')
            ? \wp_safe_remote_get($url, $requestArgs)
            : \wp_remote_get($url, $requestArgs);

        if (\is_wp_error($resp)) {
            self::safeUnlink($tmpPath);
            return $resp;
        }

        $code = (int) \wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            self::safeUnlink($tmpPath);
            return new WP_Error('lsky_pro_remote_http_error', '下载远程图片失败: HTTP ' . $code);
        }

        $contentTypeRaw = (string) \wp_remote_retrieve_header($resp, 'content-type');
        $contentType = \strtolower(\trim((string) \strtok($contentTypeRaw, ';')));
        if ($contentType === '' || \strpos($contentType, 'image/') !== 0) {
            self::safeUnlink($tmpPath);
            return new WP_Error('lsky_pro_remote_not_image', '远程资源不是图片（Content-Type: ' . $contentTypeRaw . '）');
        }

        if (!\is_file($tmpPath) || !\is_readable($tmpPath)) {
            self::safeUnlink($tmpPath);
            return new WP_Error('lsky_pro_remote_download_failed', '下载失败：未生成临时文件');
        }

        $size = \filesize($tmpPath);
        if (!\is_int($size) || $size <= 0) {
            self::safeUnlink($tmpPath);
            return new WP_Error('lsky_pro_remote_empty_file', '下载失败：文件为空');
        }

        if ($maxBytes > 0 && $size > $maxBytes) {
            self::safeUnlink($tmpPath);
            return new WP_Error('lsky_pro_remote_too_large', '远程图片超出大小限制');
        }

        $ext = self::extensionFromContentType($contentType);
        $preferredBase = self::preferredBasenameFromUrl($parts);
        $finalName = $tmpBase . '_' . $preferredBase . '.' . $ext;
        $finalPath = $tmpDir . \DIRECTORY_SEPARATOR . $finalName;

        // rename 失败时（跨盘等）用 copy + unlink 兜底。
        if (!@\rename($tmpPath, $finalPath)) {
            if (!@\copy($tmpPath, $finalPath)) {
                self::safeUnlink($tmpPath);
                self::safeUnlink($finalPath);
                return new WP_Error('lsky_pro_remote_move_failed', '下载失败：无法生成最终临时文件');
            }
            self::safeUnlink($tmpPath);
        }

        return [
            'file' => $finalPath,
            'content_type' => $contentType,
            'size' => (int) $size,
            'url' => $url,
        ];
    }

    private static function getTempDir(): string
    {
        if (\function_exists('get_temp_dir')) {
            $dir = (string) \get_temp_dir();
            if ($dir !== '') {
                return \rtrim($dir, '/\\');
            }
        }

        $sys = \sys_get_temp_dir();
        return \is_string($sys) && $sys !== '' ? \rtrim($sys, '/\\') : '';
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function preferredBasenameFromUrl(array $parts): string
    {
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $base = $path !== '' ? (string) \basename($path) : '';
        if ($base === '') {
            $base = 'remote';
        }

        if (\function_exists('sanitize_file_name')) {
            $base = (string) \sanitize_file_name($base);
        } else {
            $base = (string) \preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        }

        $name = (string) \pathinfo($base, \PATHINFO_FILENAME);
        $name = \trim($name);
        return $name !== '' ? $name : 'remote';
    }

    private static function extensionFromContentType(string $contentType): string
    {
        switch ($contentType) {
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            case 'image/avif':
                return 'avif';
            case 'image/x-icon':
            case 'image/vnd.microsoft.icon':
                return 'ico';
        }

        // 兜底：保持 jpg，最终仍会由后续 getimagesize/mime 校验兜底。
        return 'jpg';
    }

    private static function safeUnlink(string $path): void
    {
        if ($path !== '' && \is_file($path)) {
            @\unlink($path);
        }
    }
}

