<?php

if (!defined('ABSPATH')) {
    exit;
}

trait LskyProUploaderLoggingTrait {
    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log((string) $message);
        }
    }

    private function initializeLogDirectory() {
        if (!file_exists($this->log_dir)) {
            if (!wp_mkdir_p($this->log_dir)) {
                error_log('无法创建日志目录: ' . $this->log_dir);
                return false;
            }
        }

        if (!is_writable($this->log_dir)) {
            chmod($this->log_dir, 0755);
            if (!is_writable($this->log_dir)) {
                error_log('日志目录不可写: ' . $this->log_dir);
                return false;
            }
        }

        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }

        $index_file = $this->log_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }

        $this->initializeLogFile('upload.log');
        $this->initializeLogFile('error.log');

        return true;
    }

    private function initializeLogFile($filename) {
        $log_file = $this->log_dir . '/' . $filename;
        if (!file_exists($log_file)) {
            file_put_contents($log_file, "");
            chmod($log_file, 0644);
        }
    }

    private function logSuccess($filename, $url) {
        $log_file = $this->log_dir . '/upload.log';
        $log_message = sprintf(
            "[%s] 成功：%s => %s\n",
            date('Y-m-d H:i:s'),
            $filename,
            $url
        );

        if (file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX) === false) {
            error_log('写入成功日志失败');
        }
    }

    private function logError($filename, $error) {
        $log_file = $this->log_dir . '/error.log';
        $log_message = sprintf(
            "[%s] 失败：%s - 错误：%s\n",
            date('Y-m-d H:i:s'),
            basename($filename),
            $error
        );

        if (file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX) === false) {
            error_log('写入错误日志失败');
        }
    }

    public function getLogContent($type = 'upload') {
        $log_file = $this->log_dir . '/' . $type . '.log';
        if (file_exists($log_file)) {
            return file_get_contents($log_file);
        }
        return '暂无记录';
    }

    public function clearLogs() {
        $log_files = array('upload.log', 'error.log');
        foreach ($log_files as $file) {
            $log_file = $this->log_dir . '/' . $file;
            if (file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }

    private function should_verify_ssl() {
        $parsed = wp_parse_url((string)$this->api_url);
        $is_https = is_array($parsed) && isset($parsed['scheme']) && strtolower($parsed['scheme']) === 'https';
        $default = $is_https;
        return (bool)apply_filters('lsky_pro_sslverify', $default);
    }
}
