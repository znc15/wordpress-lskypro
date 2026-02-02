<?php

declare(strict_types=1);

namespace LskyPro\Support;

final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = [], string $channel = 'debug'): void
    {
        self::write('debug', $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('info', $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('warning', $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('error', $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $level = \strtolower(\trim($level));
        $channel = self::sanitizeChannel($channel);

        $time = \date('Y-m-d H:i:s');
        $line = '[' . $time . '] [' . \strtoupper($level) . '] [' . $channel . '] ' . $message;

        if (!empty($context) && \function_exists('wp_json_encode')) {
            $json = \wp_json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if (\is_string($json) && $json !== '') {
                // Avoid overly large log lines.
                if (\strlen($json) > 2000) {
                    $json = \substr($json, 0, 2000) . '...(truncated)';
                }
                $line .= ' ' . $json;
            }
        }

        // Error log (debug only)
        if (self::isDebugEnabled()) {
            \error_log('[LskyPro] ' . $line);
        }

        // Optional file log (enabled via WP_DEBUG_LOG or filter)
        if (!self::isFileEnabled()) {
            return;
        }

        $dir = self::ensureLogDir();
        if ($dir === '') {
            return;
        }

        $file = $dir . \DIRECTORY_SEPARATOR . $channel . '.log';
        @\file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }

    private static function isDebugEnabled(): bool
    {
        return \defined('WP_DEBUG') && \WP_DEBUG;
    }

    private static function isFileEnabled(): bool
    {
        $enabled = \defined('WP_DEBUG_LOG') && \WP_DEBUG_LOG;
        if (\function_exists('apply_filters')) {
            $enabled = (bool) \apply_filters('lsky_pro_enable_file_logs', $enabled);
        }
        return (bool) $enabled;
    }

    private static function ensureLogDir(): string
    {
        $dir = self::resolveLogDir();
        if ($dir === '') {
            return '';
        }

        if (!\is_dir($dir) && \function_exists('wp_mkdir_p')) {
            \wp_mkdir_p($dir);
        }

        if (!\is_dir($dir) || !\is_writable($dir)) {
            return '';
        }

        // Best-effort protections (Apache). On Nginx, rely on directory not being indexed / server config.
        $index = $dir . \DIRECTORY_SEPARATOR . 'index.php';
        if (!\is_file($index)) {
            @\file_put_contents($index, '<?php // Silence is golden');
        }

        $htaccess = $dir . \DIRECTORY_SEPARATOR . '.htaccess';
        if (!\is_file($htaccess)) {
            @\file_put_contents($htaccess, "Deny from all\n");
        }

        return $dir;
    }

    /**
     * 返回日志目录（不创建目录）。用于读取/展示日志路径。
     */
    public static function getLogDir(): string
    {
        return self::resolveLogDir();
    }

    private static function resolveLogDir(): string
    {
        if (!\function_exists('wp_upload_dir')) {
            return '';
        }

        $uploads = \wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($basedir === '') {
            return '';
        }

        return \rtrim($basedir, '/\\') . \DIRECTORY_SEPARATOR . 'lskypro-logs';
    }

    private static function sanitizeChannel(string $channel): string
    {
        $channel = \strtolower(\trim($channel));
        if ($channel === '') {
            $channel = 'general';
        }

        $channel = (string) \preg_replace('/[^a-z0-9._-]+/', '_', $channel);
        $channel = \trim($channel, '_');
        return $channel !== '' ? $channel : 'general';
    }
}

