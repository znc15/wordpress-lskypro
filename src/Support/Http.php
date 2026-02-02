<?php

declare(strict_types=1);

namespace LskyPro\Support;

use WP_Error;

final class Http
{
    /**
     * 对 WP HTTP API 做一层封装：默认请求；若出现 cURL/TLS 握手类错误，则临时禁用 curl transport 并重试一次。
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public static function requestWithFallback(string $url, array $args = [])
    {
        $scheme = '';
        if (\function_exists('wp_parse_url')) {
            $scheme = (string) \wp_parse_url($url, \PHP_URL_SCHEME);
        } else {
            $scheme = (string) \parse_url($url, \PHP_URL_SCHEME);
        }

        $isHttps = \strtolower($scheme) === 'https';

        // Safer default: verify SSL certs on HTTPS. Allow site owners to override via filters
        // (e.g. for self-signed certificates in internal deployments).
        $sslVerifyDefault = $isHttps;
        if (\function_exists('apply_filters')) {
            $sslVerifyDefault = (bool) \apply_filters('lsky_pro_sslverify', $sslVerifyDefault);
            $sslVerifyDefault = (bool) \apply_filters('lsky_pro_http_sslverify', $sslVerifyDefault, $url, $args);
        }

        $defaults = [
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => $sslVerifyDefault,
        ];

        $args = \array_merge($defaults, $args);

        $resp = \wp_remote_request($url, $args);
        if (!self::isCurlHandshakeError($resp)) {
            return $resp;
        }

        \add_filter('http_api_transports', [self::class, 'transportsWithoutCurl'], 999);
        $retry = \wp_remote_request($url, $args);
        \remove_filter('http_api_transports', [self::class, 'transportsWithoutCurl'], 999);

        return $retry;
    }

    /**
     * @param mixed $response
     */
    private static function isCurlHandshakeError($response): bool
    {
        if (!\is_wp_error($response)) {
            return false;
        }

        $msg = (string) $response->get_error_message();
        if ($msg === '') {
            return false;
        }

        if (\stripos($msg, 'cURL error 35') !== false) {
            return true;
        }

        return \stripos($msg, 'SSL') !== false && \stripos($msg, 'cURL error') !== false;
    }

    /**
     * @param mixed $transports
     * @return mixed
     */
    public static function transportsWithoutCurl($transports)
    {
        if (!\is_array($transports)) {
            return $transports;
        }

        return \array_values(\array_diff($transports, ['curl']));
    }
}
