<?php

declare(strict_types=1);

namespace LskyPro\Client;

use LskyPro\Support\Http;
use LskyPro\Support\Options;

final class LskyClient
{
    private string $apiUrl;
    private string $token;
    private string $error = '';

    public function __construct(?string $apiUrl = null, ?string $token = null)
    {
        if ($apiUrl === null || $token === null) {
            $options = Options::normalized();
            $apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
            $token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';
        }

        $this->apiUrl = \rtrim(\trim((string) $apiUrl), '/');
        $this->token = \trim((string) $token);
    }

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * 获取用户信息（完整响应结构）。
     *
     * @return array<string, mixed>|false
     */
    public function getUserProfileResponse()
    {
        return $this->requestJson('GET', '/user/profile');
    }

    /**
     * 获取 group 信息（完整响应结构，包含 storages 与上传限制）。
     *
     * @return array<string, mixed>|false
     */
    public function getGroupResponse()
    {
        if ($this->apiUrl === '' || $this->token === '') {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $cacheKey = 'lsky_pro_group_' . \md5($this->apiUrl . '|' . $this->token);
        $cached = \function_exists('get_transient') ? \get_transient($cacheKey) : false;
        if (\is_array($cached)) {
            return $cached;
        }

        $res = $this->requestJson('GET', '/group');
        if ($res !== false && \function_exists('set_transient')) {
            \set_transient($cacheKey, $res, 10 * \MINUTE_IN_SECONDS);
        }

        return $res;
    }

    /**
     * 获取相册列表（完整响应结构，带分页 meta）。
     *
     * @return array<string, mixed>|false
     */
    public function getAlbumsResponse(int $page = 1, int $perPage = 100, string $q = '')
    {
        if ($this->apiUrl === '' || $this->token === '') {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        if ($page <= 0) {
            $page = 1;
        }
        if ($perPage <= 0) {
            $perPage = 100;
        }
        $q = \trim($q);

        $cacheKey = 'lsky_pro_albums_' . \md5($this->apiUrl . '|' . $this->token . '|' . $page . '|' . $perPage . '|' . $q);
        $cached = \function_exists('get_transient') ? \get_transient($cacheKey) : false;
        if (\is_array($cached)) {
            return $cached;
        }

        $url = $this->apiUrl . '/user/albums';
        if (\function_exists('add_query_arg')) {
            $args = [
                'page' => $page,
                'per_page' => $perPage,
            ];
            if ($q !== '') {
                $args['q'] = $q;
            }
            $url = \add_query_arg($args, $url);
        }

        $res = $this->requestJsonUrl('GET', $url);
        if ($res === false) {
            // 短缓存：避免频繁打接口。
            if (\function_exists('set_transient')) {
                \set_transient($cacheKey, [], 60);
            }
            return false;
        }

        if (\function_exists('set_transient')) {
            \set_transient($cacheKey, $res, 10 * \MINUTE_IN_SECONDS);
        }

        return $res;
    }

    /**
     * 获取全部相册 items（列表数组）。
     *
     * @return array<int, mixed>|false
     */
    public function getAllAlbums(string $q = '', int $perPage = 100)
    {
        $q = \trim($q);
        if ($perPage <= 0) {
            $perPage = 100;
        }

        $cacheKey = 'lsky_pro_albums_all_' . \md5($this->apiUrl . '|' . $this->token . '|' . $perPage . '|' . $q);
        $cached = \function_exists('get_transient') ? \get_transient($cacheKey) : false;
        if (\is_array($cached)) {
            return $cached;
        }

        $first = $this->getAlbumsResponse(1, $perPage, $q);
        if ($first === false) {
            return false;
        }

        $extractItems = static function ($resp): array {
            if (!\is_array($resp) || !isset($resp['data']) || !\is_array($resp['data'])) {
                return [];
            }
            if (isset($resp['data']['data']) && \is_array($resp['data']['data'])) {
                return $resp['data']['data'];
            }
            $data = $resp['data'];
            $isList = \array_keys($data) === \range(0, \count($data) - 1);
            if ($isList) {
                return $data;
            }
            if (isset($resp['data']['albums']) && \is_array($resp['data']['albums'])) {
                return $resp['data']['albums'];
            }
            return [];
        };

        $extractLastPage = static function ($resp): int {
            if (!\is_array($resp) || !isset($resp['data']) || !\is_array($resp['data'])) {
                return 1;
            }
            if (isset($resp['data']['meta']['last_page'])) {
                return (int) $resp['data']['meta']['last_page'];
            }
            if (isset($resp['data']['last_page'])) {
                return (int) $resp['data']['last_page'];
            }
            return 1;
        };

        $albums = $extractItems($first);
        $lastPage = $extractLastPage($first);
        if ($lastPage < 1) {
            $lastPage = 1;
        }

        // Safety cap.
        $maxPages = 50;
        if ($lastPage > $maxPages) {
            $lastPage = $maxPages;
        }

        for ($p = 2; $p <= $lastPage; $p++) {
            $resp = $this->getAlbumsResponse($p, $perPage, $q);
            if ($resp === false) {
                return false;
            }
            $pageAlbums = $extractItems($resp);
            if (!empty($pageAlbums)) {
                $albums = \array_merge($albums, $pageAlbums);
            }
        }

        $albums = \array_values($albums);
        if (\function_exists('set_transient')) {
            \set_transient($cacheKey, $albums, 10 * \MINUTE_IN_SECONDS);
        }

        return $albums;
    }

    /**
     * 删除图床图片（photo ids）。
     *
     * @param array<int, int> $photoIds
     */
    public function deletePhotos(array $photoIds): bool
    {
        if ($this->apiUrl === '' || $this->token === '') {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $ids = [];
        foreach ($photoIds as $id) {
            $id = (int) \absint($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = \array_values(\array_unique($ids));
        if (empty($ids)) {
            return true;
        }

        $url = $this->apiUrl . '/user/photos';
        $body = \function_exists('wp_json_encode')
            ? \wp_json_encode($ids, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            : \json_encode($ids);
        if (!\is_string($body)) {
            $body = '[]';
        }

        $resp = Http::requestWithFallback(
            $url,
            [
                'method' => 'DELETE',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]
        );

        if (\is_wp_error($resp)) {
            $this->error = $resp->get_error_message();
            return false;
        }

        $httpCode = (int) \wp_remote_retrieve_response_code($resp);
        if ($httpCode === 204) {
            return true;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        $this->error = '删除失败，HTTP ' . $httpCode;
        return false;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|false
     */
    private function requestJson(string $method, string $endpoint, array $args = [])
    {
        if ($this->apiUrl === '' || $this->token === '') {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $url = $this->apiUrl . $endpoint;
        return $this->requestJsonUrl($method, $url, $args);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|false
     */
    private function requestJsonUrl(string $method, string $url, array $args = [])
    {
        if ($this->apiUrl === '' || $this->token === '') {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $method = \strtoupper(\trim($method));
        if ($method === '') {
            $method = 'GET';
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];
        if (isset($args['headers']) && \is_array($args['headers'])) {
            $headers = \array_merge($headers, $args['headers']);
        }
        $args['headers'] = $headers;
        $args['method'] = $method;

        $resp = Http::requestWithFallback($url, $args);
        if (\is_wp_error($resp)) {
            $this->error = $resp->get_error_message();
            return false;
        }

        $httpCode = (int) \wp_remote_retrieve_response_code($resp);
        $rawBody = (string) \wp_remote_retrieve_body($resp);
        $json = \json_decode($rawBody, true);
        if (!\is_array($json)) {
            $this->error = '响应解析失败（HTTP ' . $httpCode . '）';
            return false;
        }

        if ($httpCode !== 200) {
            $msg = isset($json['message']) ? (string) $json['message'] : ('HTTP ' . $httpCode);
            $this->error = $msg;
            return false;
        }

        if (isset($json['status']) && $json['status'] === 'success') {
            return $json;
        }

        $this->error = isset($json['message']) ? (string) $json['message'] : 'API响应异常';
        return false;
    }
}

