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
        return $this->make_request('GET', '/profile');
    }
    
    /**
     * 获取存储策略列表
     */
    public function get_strategies() {
        return $this->make_request('GET', '/strategies');
    }
    
    /**
     * 发送API请求
     */
    private function make_request($method, $endpoint) {
        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $base_url = $this->normalize_v1_base_url($this->api_url);

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

        return $result;
    }

    /**
     * 旧版本接口统一使用 /api/v1。
     * 允许配置项填写到 /api/v2 或仅域名，这里会自动归一化。
     */
    private function normalize_v1_base_url($url) {
        $base = rtrim((string)$url, '/');
        $parsed = wp_parse_url($base);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return $base;
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '';

        if (preg_match('~/api/v\d+~', $path)) {
            $path = preg_replace('~/api/v\d+~', '/api/v1', $path, 1);
        } else {
            $path = rtrim($path, '/') . '/api/v1';
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