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
        return $this->get_group();
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

        $base_url = rtrim((string) $this->api_url, '/');

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

        if (isset($result['status']) && $result['status'] === 'success') {
            return $result;
        }

        $this->error = isset($result['message']) ? (string) $result['message'] : 'API响应异常';
        return false;
    }
    
    /**
     * 获取错误信息
     */
    public function getError() {
        return $this->error;
    }
}
