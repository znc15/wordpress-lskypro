<?php
/**
 * 上传图片到图床
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/uploader/logging.php';
require_once __DIR__ . '/uploader/requirements.php';
require_once __DIR__ . '/uploader/image.php';
require_once __DIR__ . '/uploader/request-context.php';

class LskyProUploader {
    private $api_url;
    private $token;
    private $error;
    private $log_dir;
    private $requirements = array();
    private $last_request_context = array();

    use LskyProUploaderLoggingTrait;
    use LskyProUploaderRequirementsTrait;
    use LskyProUploaderImageTrait;
    use LskyProUploaderRequestContextTrait;
    
    public function __construct() {
        $options = get_option('lsky_pro_options');
        $this->api_url = $options['lsky_pro_api_url'] ?? '';
        $this->token = $options['lsky_pro_token'] ?? '';
        
        // 设置日志目录
        $this->log_dir = plugin_dir_path(dirname(__FILE__)) . 'logs';
        $this->initializeLogDirectory();
        
        // 检查环境要求
        $this->checkRequirements();
    }
    
    /**
     * 获取用户信息
     */
    public function get_user_info() {
        $options = get_option('lsky_pro_options');
        if (empty($options['lsky_pro_api_url']) || empty($options['lsky_pro_token'])) {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $response = wp_remote_get(
            rtrim($options['lsky_pro_api_url'], '/') . '/user/profile',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $options['lsky_pro_token']
                ),
                'timeout' => 30,
                'sslverify' => false
            )
        );

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            $this->error = '获取用户信息失败：响应解析失败';
            return false;
        }

        if (isset($body['status']) && $body['status'] !== true && $body['status'] !== 'success') {
            $this->error = $body['message'] ?? '获取用户信息失败';
            return false;
        }

        return $body['data'];
    }
    
    /**
     * 获取错误信息
     */
    public function getError() {
        return $this->error;
    }

    /**
     * 获取当前组信息（v2: /group），用于 storages 与上传限制。
     * 做短缓存避免频繁请求。
     */
    public function get_group_info() {
        $options = get_option('lsky_pro_options');
        $api_url = $options['lsky_pro_api_url'] ?? '';
        $token = $options['lsky_pro_token'] ?? '';

        if ($api_url === '' || $token === '') {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $cache_key = 'lsky_pro_group_' . md5($api_url . '|' . $token);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            rtrim($api_url, '/') . '/group',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ),
                'timeout' => 30,
                'sslverify' => false,
            )
        );

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $this->error = '获取组信息失败：响应解析失败';
            return false;
        }

        if (isset($data['status']) && $data['status'] !== true && $data['status'] !== 'success') {
            $this->error = $data['message'] ?? '获取组信息失败';
            return false;
        }

        // 缓存 10 分钟
        set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    /**
     * 允许的文件扩展名（来源：/group.data.group.options.allow_file_types）
     */
    public function get_allowed_file_types() {
        $group = $this->get_group_info();
        if ($group === false) {
            return array();
        }

        $types = $group['data']['group']['options']['allow_file_types'] ?? array();
        if (!is_array($types)) {
            return array();
        }

        $normalized = array();
        foreach ($types as $t) {
            $t = strtolower(trim((string) $t));
            if ($t !== '') {
                $normalized[] = $t;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * 最大上传大小（bytes），来源：/group.data.group.options.max_upload_size（文档示例为 KB）。
     */
    public function get_max_upload_size_bytes() {
        $group = $this->get_group_info();
        if ($group === false) {
            return 0;
        }

        $kb = $group['data']['group']['options']['max_upload_size'] ?? 0;
        $kb = (int) $kb;
        if ($kb <= 0) {
            return 0;
        }
        return $kb * 1024;
    }
    
    public function upload($file_path) {
        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            $this->logError($file_path, $this->error);
            return false;
        }
        
        // 获取存储ID（OpenAPI: storage_id；兼容旧配置 strategy_id）
        $options = get_option('lsky_pro_options');
        $storage_id = 0;
        if (isset($options['storage_id'])) {
            $storage_id = intval($options['storage_id']);
        } elseif (isset($options['strategy_id'])) {
            $storage_id = intval($options['strategy_id']);
        }

        // 若未设置或设置了无效 id，则尝试从 /group.storages 自动选择一个可用 id。
        $storages = $this->get_strategies();
        if (is_array($storages) && !empty($storages)) {
            $allowed_ids = array();
            foreach ($storages as $s) {
                if (is_array($s) && isset($s['id'])) {
                    $allowed_ids[] = (int) $s['id'];
                }
            }

            $allowed_ids = array_values(array_unique(array_filter($allowed_ids)));
            if (!empty($allowed_ids)) {
                if ($storage_id <= 0 || !in_array($storage_id, $allowed_ids, true)) {
                    $storage_id = $allowed_ids[0];
                }
            }
        }

        // 最后兜底
        if ($storage_id <= 0) {
            $storage_id = 1;
        }
        
        // 检查图片文件
        $image_info = $this->checkImageFile($file_path);
        if ($image_info === false) {
            $this->debug_log('图片检查失败: ' . $this->error);
            $this->logError($file_path, $this->error);
            return false;
        }

        // 记录图片信息
        $this->logImageInfo($file_path, $image_info);

        // 准备上传
        $cfile = new CURLFile($file_path, $image_info['mime_type'], basename($file_path));

        $upload_url = rtrim((string)$this->api_url, '/') . '/upload';
        $expired_at = isset($options['expired_at']) ? sanitize_text_field($options['expired_at']) : '';

        // 兼容旧设置 permission(1=公开,0=私有) => is_public(bool)
        $permission = isset($options['permission']) ? intval($options['permission']) : 1;
        $is_public = $permission === 1;

        // 记录本次请求参数（脱敏），用于失败时回传。
        $this->setUploadRequestContext(array(
            'url' => $upload_url,
            'method' => 'POST',
            'fields' => array(
                'storage_id' => (int) $storage_id,
                'is_public' => $is_public ? 1 : 0,
                'expired_at' => $expired_at !== '' ? $expired_at : null,
                // 不回传 token 值，避免泄露；仅标记是否携带。
                'token_present' => 1,
            ),
            'file' => array(
                'name' => basename($file_path),
                'mime' => $image_info['mime_type'],
                'size' => $image_info['size'],
                'width' => $image_info['width'],
                'height' => $image_info['height'],
            ),
        ));

        $post_data = array(
            'file' => $cfile,
            'storage_id' => $storage_id,
            'is_public' => $is_public ? '1' : '0',
        );
        if ($expired_at !== '') {
            $post_data['expired_at'] = $expired_at;
        }

        $this->debug_log('准备发送请求到: ' . $upload_url);

        $response = false;
        $err = '';
        $curl_errno = 0;
        $http_code = 0;
        $curl_info = array();

        // 对于 Recv failure: Connection was reset 等偶发网络错误，做轻量重试。
        $max_attempts = (int) apply_filters('lsky_pro_upload_curl_attempts', 3);
        if ($max_attempts < 1) {
            $max_attempts = 1;
        }

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $upload_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => 'gzip,deflate',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'User-Agent: WordPress/LskyPro-Uploader',
                    // v2 鉴权：使用 Bearer Token（否则服务端可能识别为游客）
                    'Authorization: Bearer ' . (string) $this->token,
                    // 禁用 Expect: 100-continue，避免部分环境/网关下连接被提前断开
                    'Expect:'
                ),
                CURLOPT_SSL_VERIFYPEER => $this->should_verify_ssl(),
                CURLOPT_SSL_VERIFYHOST => $this->should_verify_ssl() ? 2 : 0,
                CURLOPT_VERBOSE => (defined('WP_DEBUG') && WP_DEBUG),
            ));

            $response = curl_exec($curl);
            $curl_errno = (int) curl_errno($curl);
            $err = (string) curl_error($curl);
            $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_info = (array) curl_getinfo($curl);

            curl_close($curl);

            // 无 cURL 错误就不需要重试。
            if ($curl_errno === 0 && $err === '') {
                break;
            }

            // 仅对典型瞬断类错误重试。
            $retryable_errno = array(
                56, // CURLE_RECV_ERROR: Recv failure: Connection was reset
                55, // CURLE_SEND_ERROR
                52, // CURLE_GOT_NOTHING
                28, // CURLE_OPERATION_TIMEDOUT
                7,  // CURLE_COULDNT_CONNECT
                35, // CURLE_SSL_CONNECT_ERROR
            );
            $should_retry = in_array($curl_errno, $retryable_errno, true);

            if (!$should_retry || $attempt >= $max_attempts) {
                break;
            }

            // 简单退避，避免立刻重连撞上同一条不稳定链路。
            usleep($attempt === 1 ? 300000 : 800000);
        }
        
        // 记录请求信息（仅在 debug 模式输出到 PHP error_log）
        $this->debug_log('请求响应状态码: ' . $http_code);
        $this->debug_log('请求响应内容(截断): ' . substr((string)$response, 0, 800));
        
        // 处理错误
        if ($curl_errno !== 0 || $err !== '') {
            $this->error = 'CURL错误: ' . ($err !== '' ? $err : ('errno=' . $curl_errno));

            $info_subset = array(
                'url' => $curl_info['url'] ?? '',
                'http_code' => $curl_info['http_code'] ?? $http_code,
                'content_type' => $curl_info['content_type'] ?? '',
                'primary_ip' => $curl_info['primary_ip'] ?? '',
                'primary_port' => $curl_info['primary_port'] ?? '',
                'local_ip' => $curl_info['local_ip'] ?? '',
                'local_port' => $curl_info['local_port'] ?? '',
                'ssl_verify_result' => $curl_info['ssl_verify_result'] ?? null,
                'http_version' => $curl_info['http_version'] ?? null,
                'redirect_count' => $curl_info['redirect_count'] ?? null,
                'total_time' => $curl_info['total_time'] ?? null,
                'namelookup_time' => $curl_info['namelookup_time'] ?? null,
                'connect_time' => $curl_info['connect_time'] ?? null,
                'appconnect_time' => $curl_info['appconnect_time'] ?? null,
                'starttransfer_time' => $curl_info['starttransfer_time'] ?? null,
            );
            $info_json = wp_json_encode($info_subset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($info_json)) {
                $info_json = '';
            }

            $this->logError(
                $file_path,
                $this->error . sprintf('；errno=%d；请求：%s；curlinfo：%s', $curl_errno, $upload_url, substr($info_json, 0, 800))
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        if (empty($response)) {
            $this->error = '服务器没有响应';
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；HTTP：%d', $upload_url, $http_code)
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }

        $response_snippet = substr((string)$response, 0, 500);
        
        // 解析响应
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = '无效的API响应格式';
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet)
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }

        // 检查HTTP状态码（OpenAPI: 200）
        if ($http_code !== 200) {
            $error_message = isset($result['message']) ? $result['message'] : '未知错误';
            $this->error = sprintf('HTTP错误(%d): %s', $http_code, $error_message);
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet)
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        // 检查API响应（兼容 status 为 bool 或字符串）
        $status_ok = false;
        if (isset($result['status'])) {
            if ($result['status'] === true || $result['status'] === 1 || $result['status'] === 'true' || $result['status'] === 'success') {
                $status_ok = true;
            }
        }
        if (!$status_ok) {
            $this->error = '上传失败: ' . ($result['message'] ?? '未知错误');
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet)
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        // 获取图片URL（兼容旧/新字段）
        $image_url = $result['data']['links']['url'] ?? '';
        if ($image_url === '' && isset($result['data']['public_url'])) {
            $image_url = (string) $result['data']['public_url'];
        }
        if ($image_url === '' && isset($result['data']['url'])) {
            $image_url = (string) $result['data']['url'];
        }
        if ($image_url === '') {
            $image_url = false;
        }
        if (!$image_url) {
            $this->error = '无法获取图片URL';
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet)
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        // 记录成功
        $this->logSuccess(basename($file_path), $image_url);
        $this->debug_log('上传成功，图片URL: ' . $image_url);
        
        return $image_url;
    }

    /**
     * 获取存储策略列表
     */
    public function get_strategies() {
        $options = get_option('lsky_pro_options');
        if (empty($options['lsky_pro_api_url']) || empty($options['lsky_pro_token'])) {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        // v2 推荐：通过 /group 获取 storages
        $group = $this->get_group_info();
        if ($group !== false) {
            $storages = $group['data']['storages'] ?? null;
            if (is_array($storages)) {
                return $storages;
            }
        }

        $base = rtrim($options['lsky_pro_api_url'], '/');
        $endpoints = array(
            '/strategies',
            '/storages',
            '/storage/strategies',
        );

        $last_error = '';

        foreach ($endpoints as $endpoint) {
            $response = wp_remote_get(
                $base . $endpoint,
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $options['lsky_pro_token'],
                        'Accept' => 'application/json',
                    ),
                    'timeout' => 30,
                    'sslverify' => false
                )
            );

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code === 404) {
                $last_error = '接口不存在：' . $endpoint;
                continue;
            }

            if (!is_array($data)) {
                $last_error = '响应解析失败：' . $endpoint;
                continue;
            }

            if (isset($data['status']) && $data['status'] !== true && $data['status'] !== 'success') {
                $last_error = $data['message'] ?? ('获取存储策略失败：' . $endpoint);
                continue;
            }

            // 兼容多种返回结构
            if (isset($data['data']['strategies']) && is_array($data['data']['strategies'])) {
                return $data['data']['strategies'];
            }
            if (isset($data['data']['storages']) && is_array($data['data']['storages'])) {
                return $data['data']['storages'];
            }
            if (isset($data['data']) && is_array($data['data'])) {
                return $data['data'];
            }

            // 若返回成功但没有列表字段，视为“无数据”，直接返回空数组。
            return array();
        }

        $this->error = $last_error !== '' ? $last_error : '获取存储策略失败';
        return false;
    }

    /**
     * 获取 API URL
     */
    public function getApiUrl() {
        $options = get_option('lsky_pro_options');
        return $options['lsky_pro_api_url'] ?? '';
    }

    /**
     * 获取 Token
     */
    public function getToken() {
        $options = get_option('lsky_pro_options');
        return $options['lsky_pro_token'] ?? '';
    }
}
