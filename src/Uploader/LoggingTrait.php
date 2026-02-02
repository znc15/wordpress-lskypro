<?php

declare(strict_types=1);

namespace LskyPro\Uploader;

trait LoggingTrait
{
    private function debug_log($message)
    {
        if (\defined('WP_DEBUG') && \WP_DEBUG) {
            \error_log((string) $message);
        }
    }

    private function initializeLogDirectory()
    {
        if (!\file_exists($this->log_dir)) {
            if (!\wp_mkdir_p($this->log_dir)) {
                \error_log('无法创建日志目录: ' . $this->log_dir);
                return false;
            }
        }

        if (!\is_writable($this->log_dir)) {
            @\chmod($this->log_dir, 0755);
            if (!\is_writable($this->log_dir)) {
                \error_log('日志目录不可写: ' . $this->log_dir);
                return false;
            }
        }

        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!\file_exists($htaccess_file)) {
            \file_put_contents($htaccess_file, "Deny from all\n");
        }

        $index_file = $this->log_dir . '/index.php';
        if (!\file_exists($index_file)) {
            \file_put_contents($index_file, '<?php // Silence is golden');
        }

        $this->initializeLogFile('upload.log');
        $this->initializeLogFile('error.log');

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
        $log_file = $this->log_dir . '/upload.log';
        $context_suffix = '';
        if (\func_num_args() >= 3) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(2));
        }

        $log_message = \sprintf(
            "[%s] 成功：%s => %s%s\n",
            \date('Y-m-d H:i:s'),
            $filename,
            $url,
            $context_suffix
        );

        if (\file_put_contents($log_file, $log_message, \FILE_APPEND | \LOCK_EX) === false) {
            \error_log('写入成功日志失败');
        }
    }

    private function logError($filename, $error)
    {
        $log_file = $this->log_dir . '/error.log';
        $context_suffix = '';
        if (\func_num_args() >= 3) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(2));
        }

        $log_message = \sprintf(
            "[%s] 失败：%s - 错误：%s%s\n",
            \date('Y-m-d H:i:s'),
            \basename((string) $filename),
            $error,
            $context_suffix
        );

        if (\file_put_contents($log_file, $log_message, \FILE_APPEND | \LOCK_EX) === false) {
            \error_log('写入错误日志失败');
        }
    }

    private function logRouting($filename, $matched, $storage_id, $album_id)
    {
        $log_file = $this->log_dir . '/upload.log';
        $context_suffix = '';
        if (\func_num_args() >= 5) {
            $context_suffix = $this->formatUploadLogContext(\func_get_arg(4));
        }

        $log_message = \sprintf(
            "[%s] 路由：%s | 匹配:%s | storage_id:%d | album_id:%d%s\n",
            \date('Y-m-d H:i:s'),
            \basename((string) $filename),
            $matched ? '是' : '否',
            (int) $storage_id,
            (int) $album_id,
            $context_suffix
        );

        if (\file_put_contents($log_file, $log_message, \FILE_APPEND | \LOCK_EX) === false) {
            \error_log('写入路由日志失败');
        }
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
        $log_file = $this->log_dir . '/' . $type . '.log';
        if (\file_exists($log_file)) {
            return \file_get_contents($log_file);
        }
        return '暂无记录';
    }

    public function clearLogs()
    {
        $log_files = ['upload.log', 'error.log'];
        foreach ($log_files as $file) {
            $log_file = $this->log_dir . '/' . $file;
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
