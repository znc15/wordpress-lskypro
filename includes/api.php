<?php
/**
 * 图床API接口
 */
class LskyProApi {
    private $api_url;
    private $token;
    private $error;
    
    public function __construct() {
        $options = get_option('lsky_pro_options');
        $this->api_url = $options['lsky_pro_api_url'] ?? '';
        $this->token = $options['lsky_pro_token'] ?? '';
    }
    
    /**
     * 获取用户信息
     */
    public function get_user_info() {
        return $this->make_request('GET', '/user/profile');
    }
    
    /**
     * 获取存储策略列表
     */
    public function get_strategies() {
        // v2 推荐：通过 /group 获取当前组允许的 storages
        $group = $this->get_group();
        if ($group !== false) {
            return $group;
        }

        $endpoints = array(
            '/strategies',
            '/storages',
            '/storage/strategies',
        );

        foreach ($endpoints as $endpoint) {
            $result = $this->make_request('GET', $endpoint);
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    /**
     * 获取当前所在组信息（包含 storages / allow_file_types 等）
     */
    public function get_group() {
        return $this->make_request('GET', '/group');
    }
    
    /**
     * 发送API请求
     */
    private function make_request($method, $endpoint) {
        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $base_url = $this->normalize_api_base_url($this->api_url);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => rtrim($base_url, '/') . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            $this->error = 'cURL Error: ' . $err;
            return false;
        }

        if ($http_code !== 200) {
            $this->error = "HTTP Error: $http_code";
            return false;
        }

        $result = json_decode($response, true);
        if (!$result) {
            $this->error = '请检查API地址和Token是否正确';
            return false;
        }

        // 兼容：部分接口 status 为布尔 true；新版为字符串 success
        if (isset($result['status']) && ($result['status'] === true || $result['status'] === 'success')) {
            return $result;
        }

        // 若接口未提供 status 字段，则直接返回解析结果（保持宽松兼容）。
        if (!array_key_exists('status', $result)) {
            return $result;
        }

        $this->error = isset($result['message']) ? (string) $result['message'] : 'API响应异常';
        return false;
    }

    /**
     * 归一化 API Base URL。
     * 允许配置项填写到 /api/v2 或仅域名；如果没有版本路径，则默认补 /api/v2。
     */
    private function normalize_api_base_url($url) {
        $base = rtrim((string) $url, '/');
        $parsed = wp_parse_url($base);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return $base;
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '';

        if (!preg_match('~/api/v\d+~', $path)) {
            $path = rtrim($path, '/') . '/api/v2';
        }

        return $scheme . '://' . $host . $port . rtrim($path, '/');
    }
    
    /**
     * 获取错误信息
     */
    public function getError() {
        return $this->error;
    }
}
