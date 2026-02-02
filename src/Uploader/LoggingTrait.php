<?php

declare(strict_types=1);

namespace LskyPro\Uploader;

use LskyPro\Support\Logger;

trait LoggingTrait
{
    private function debug_log($message)
    {
        Logger::debug((string) $message, [], 'uploader');
    }

    private function initializeLogDirectory()
    {
        // Prefer a safer, writable directory under uploads.
        $dir = Logger::getLogDir();
        if (\is_string($dir) && $dir !== '') {
            $this->log_dir = $dir;
        }

        // Directory creation and protections are handled by Logger when file logging is enabled.
        return true;
    }

    private function initializeLogFile($filename)
    {
        $log_file = $this->log_dir . '/' . $filename;
        if (!\file_exists($log_file)) {
            \file_put_contents($log_file, '');
            @\chmod($log_file, 0644);
        }
    }

    private function logSuccess($filename, $url)
    {
        $context_suffix = '';
        if (\func_num_args() >= 3) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(2));
        }

        Logger::info('upload', \sprintf('成功：%s => %s%s', $filename, $url, $context_suffix));
    }

    private function logError($filename, $error)
    {
        $context_suffix = '';
        if (\func_num_args() >= 3) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(2));
        }

        Logger::error('error', \sprintf('失败：%s - 错误：%s%s', \basename((string) $filename), $error, $context_suffix));
    }

    private function logRouting($filename, $matched, $storage_id, $album_id)
    {
        $context_suffix = '';
        if (\func_num_args() >= 5) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(4));
        }

        Logger::info(
            'upload',
            \sprintf(
                '路由：%s | 匹配:%s | storage_id:%d | album_id:%d%s',
                \basename((string) $filename),
                $matched ? '是' : '否',
                (int) $storage_id,
                (int) $album_id,
                $context_suffix
            )
        );
    }

    private function formatUploadLogContext($context)
    {
        if (!\is_array($context) || empty($context)) {
            return '';
        }

        $source = isset($context['source']) ? (string) $context['source'] : '';
        $trigger = isset($context['trigger']) ? (string) $context['trigger'] : '';
        $post_id = isset($context['post_id']) ? (int) $context['post_id'] : 0;
        $post_title = isset($context['post_title']) ? (string) $context['post_title'] : '';
        $post_url = isset($context['post_url']) ? (string) $context['post_url'] : '';

        $post_title = \str_replace(["\r", "\n"], ' ', $post_title);
        $post_url = \str_replace(["\r", "\n"], '', $post_url);

        $parts = [];
        if ($source !== '') {
            $parts[] = '来源:' . $source;
        }

        if ($trigger === 'post' && $post_id > 0) {
            $label = '文章:' . $post_id;
            if ($post_title !== '') {
                $label .= ' ' . $post_title;
            }
            if ($post_url !== '') {
                $label .= ' ' . $post_url;
            }
            $parts[] = $label;
        }

        if (empty($parts)) {
            return '';
        }

        return ' | ' . \implode(' | ', $parts);
    }

    public function getLogContent($type = 'upload')
    {
        $dir = Logger::getLogDir();
        $log_file = ($dir !== '' ? ($dir . '/' . $type . '.log') : '');
        if ($log_file !== '' && \file_exists($log_file)) {
            return (string) \file_get_contents($log_file);
        }
        return '暂无记录';
    }

    public function clearLogs()
    {
        $dir = Logger::getLogDir();
        if ($dir === '') {
            return;
        }

        $log_files = ['upload.log', 'error.log'];
        foreach ($log_files as $file) {
            $log_file = $dir . '/' . $file;
            if (\file_exists($log_file)) {
                @\unlink($log_file);
            }
        }
    }

    private function should_verify_ssl()
    {
        $parsed = \wp_parse_url((string) $this->api_url);
        $is_https = \is_array($parsed) && isset($parsed['scheme']) && \strtolower((string) $parsed['scheme']) === 'https';
        $default = $is_https;
        return (bool) \apply_filters('lsky_pro_sslverify', $default);
    }
}
