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
    private $last_uploaded_photo_id = null;
    private $upload_log_context = array();

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
     * 设置上传日志上下文（例如：由哪篇文章触发）。
     * 该上下文会被写入 logs/upload.log 与 logs/error.log。
     */
    public function setUploadLogContext($context) {
        $this->upload_log_context = is_array($context) ? $context : array();
    }

    public function clearUploadLogContext() {
        $this->upload_log_context = array();
    }

    public function setUploadLogContextFromPost($post_id, $source = '') {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            $this->upload_log_context = array();
            return;
        }

        $title = '';
        $url = '';
        if (function_exists('get_the_title')) {
            $title = (string) get_the_title($post_id);
        }
        if (function_exists('get_permalink')) {
            $url = (string) get_permalink($post_id);
        }

        $this->upload_log_context = array(
            'trigger' => 'post',
            'source' => is_string($source) ? $source : '',
            'post_id' => $post_id,
            'post_title' => $title,
            'post_url' => $url,
        );
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

        if (!isset($body['status']) || $body['status'] !== 'success') {
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

        if (!isset($data['status']) || $data['status'] !== 'success') {
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
            $this->logError($file_path, $this->error, $this->upload_log_context);
            return false;
        }

        // 每次上传前重置，避免外部复用实例时拿到旧值。
        $this->last_uploaded_photo_id = null;
        
        // 获取存储ID（OpenAPI: storage_id）
        $options = get_option('lsky_pro_options');
        $storage_id = 0;
        if (isset($options['storage_id'])) {
            $storage_id = intval($options['storage_id']);
        }

        // 全局默认相册（可选）：仅当 >0 时随上传携带 album_id
        $album_id = 0;
        if (isset($options['album_id'])) {
            $album_id = absint($options['album_id']);
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
            $this->logError($file_path, $this->error, $this->upload_log_context);
            return false;
        }

        // 记录图片信息
        $this->logImageInfo($file_path, $image_info);

        // 准备上传
        $cfile = new CURLFile($file_path, $image_info['mime_type'], basename($file_path));

        $upload_url = rtrim((string)$this->api_url, '/') . '/upload';
        $is_public = true;

        // 记录本次请求参数（脱敏），用于失败时回传。
        $this->setUploadRequestContext(array(
            'url' => $upload_url,
            'method' => 'POST',
            'trigger' => $this->upload_log_context,
            'fields' => array(
                'storage_id' => (int) $storage_id,
                'is_public' => $is_public ? 1 : 0,
                'album_id' => $album_id > 0 ? (int) $album_id : null,
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

        if ($album_id > 0) {
            $post_data['album_id'] = (string) $album_id;
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
                $this->error . sprintf('；errno=%d；请求：%s；curlinfo：%s', $curl_errno, $upload_url, substr($info_json, 0, 800)),
                $this->upload_log_context
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
                $this->error . sprintf('；请求：%s；HTTP：%d', $upload_url, $http_code),
                $this->upload_log_context
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
                $this->error . sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet),
                $this->upload_log_context
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
                $this->error . sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet),
                $this->upload_log_context
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        // 检查API响应（仅接受新版：status=success）
        if (!isset($result['status']) || $result['status'] !== 'success') {
            $this->error = '上传失败: ' . ($result['message'] ?? '未知错误');
            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet),
                $this->upload_log_context
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }
            return false;
        }
        
        // 获取图片URL（v2：优先从 data.links 中挑选可用 URL；若为相对路径则转为绝对 URL）。
        $image_url = '';

        $origin = '';
        $api_parts = wp_parse_url((string) $this->api_url);
        if (is_array($api_parts) && !empty($api_parts['scheme']) && !empty($api_parts['host'])) {
            $origin = $api_parts['scheme'] . '://' . $api_parts['host'];
            if (!empty($api_parts['port'])) {
                $origin .= ':' . $api_parts['port'];
            }
        }

        $normalize_url = function ($candidate) use ($origin) {
            if (!is_string($candidate)) {
                return '';
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                return '';
            }
            if (preg_match('#^https?://#i', $candidate)) {
                return $candidate;
            }
            if ($origin !== '' && str_starts_with($candidate, '/')) {
                return $origin . $candidate;
            }
            return '';
        };

        if (isset($result['data']['links']) && is_array($result['data']['links'])) {
            // 先按常见 key 优先级取值
            $preferred_keys = array('url', 'original_url', 'raw_url', 'public_url', 'direct_url', 'download_url', 'html');
            foreach ($preferred_keys as $k) {
                if (!array_key_exists($k, $result['data']['links'])) {
                    continue;
                }
                $candidate = $normalize_url($result['data']['links'][$k]);
                if ($candidate !== '') {
                    $image_url = $candidate;
                    break;
                }
            }

            // 再兜底：遍历 links 任意 string 值，取第一个可用 URL
            if ($image_url === '') {
                foreach ($result['data']['links'] as $v) {
                    $candidate = $normalize_url($v);
                    if ($candidate !== '') {
                        $image_url = $candidate;
                        break;
                    }
                }
            }
        }

        // 部分实现可能直接给 data.url / data.public_url
        if ($image_url === '' && isset($result['data']['url'])) {
            $image_url = $normalize_url($result['data']['url']);
        }
        if ($image_url === '' && isset($result['data']['public_url'])) {
            $image_url = $normalize_url($result['data']['public_url']);
        }

        // 允许外部通过 filter 指定 URL（仍然只在 v2 success 后）。
        $image_url = (string) apply_filters('lsky_pro_uploaded_image_url', $image_url, $result);

        // 尝试获取图片ID（用于后续删除：DELETE /user/photos）。
        // 不强依赖字段存在，避免影响现有上传流程。
        $photo_id = $result['data']['id'] ?? null;
        if ($photo_id === null && isset($result['data']['photo']['id'])) {
            $photo_id = $result['data']['photo']['id'];
        }
        if ($photo_id !== null && is_numeric($photo_id)) {
            $photo_id = (int) $photo_id;
            if ($photo_id > 0) {
                $this->last_uploaded_photo_id = $photo_id;
            }
        }

        if ($image_url === '') {
            $this->error = '无法获取图片URL';

            $data_keys = '';
            if (isset($result['data']) && is_array($result['data'])) {
                $data_keys = implode(',', array_map('strval', array_keys($result['data'])));
            }
            $links_keys = '';
            if (isset($result['data']['links']) && is_array($result['data']['links'])) {
                $links_keys = implode(',', array_map('strval', array_keys($result['data']['links'])));
            }

            $this->logError(
                $file_path,
                $this->error . sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet),
                $this->upload_log_context
            );
            $ctx = $this->formatLastRequestContextForError();
            if ($ctx !== '') {
                $this->error .= '；请求参数：' . $ctx;
            }

            // 追加便于排查的信息（避免太长，仅展示字段名与响应片段）。
            if ($data_keys !== '') {
                $this->error .= '；data字段：' . $data_keys;
            }
            if ($links_keys !== '') {
                $this->error .= '；links字段：' . $links_keys;
            }
            $this->error .= '；响应片段：' . $response_snippet;
            return false;
        }
        
        // 记录成功
        $this->logSuccess(basename($file_path), $image_url, $this->upload_log_context);
        $this->debug_log('上传成功，图片URL: ' . $image_url);
        
        return $image_url;
    }

    /**
     * 获取最近一次上传成功返回的图片ID（若接口返回）。
     */
    public function getLastUploadedPhotoId() {
        return $this->last_uploaded_photo_id;
    }

    /**
     * 删除图片（v2）：DELETE /user/photos，body 为图片ID数组。
     * 成功通常返回 204。
     */
    public function delete_photos($photo_ids) {
        if (!is_array($photo_ids)) {
            $photo_ids = array($photo_ids);
        }

        $ids = array();
        foreach ($photo_ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return true;
        }

        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            return false;
        }

        $url = rtrim((string) $this->api_url, '/') . '/user/photos';
        $body = wp_json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            $body = '[]';
        }

        $response = wp_remote_request(
            $url,
            array(
                'method' => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . (string) $this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ),
                'body' => $body,
                'timeout' => 30,
                'sslverify' => false,
            )
        );

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        if ($http_code === 204) {
            return true;
        }

        // 兼容部分实现返回 200/2xx + JSON。
        if ($http_code >= 200 && $http_code < 300) {
            $raw = (string) wp_remote_retrieve_body($response);
            if (trim($raw) === '') {
                return true;
            }

            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['status']) && $data['status'] === 'success') {
                return true;
            }

            return true;
        }

        $this->error = '删除失败，HTTP ' . $http_code;
        return false;
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

        // v2：通过 /group 获取 storages
        $group = $this->get_group_info();
        if ($group === false) {
            return false;
        }

        $storages = $group['data']['storages'] ?? null;
        if (!is_array($storages)) {
            return array();
        }

        return $storages;
    }

    /**
     * 获取相册列表（GET /user/albums）
     *
     * @param int $page
     * @param int $per_page
     * @param string|null $q
     * @return array|false
     */
    public function get_albums($page = 1, $per_page = 100, $q = null) {
        $options = get_option('lsky_pro_options');
        $api_url = $options['lsky_pro_api_url'] ?? '';
        $token = $options['lsky_pro_token'] ?? '';

        if ($api_url === '' || $token === '') {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $page = (int) $page;
        if ($page <= 0) {
            $page = 1;
        }

        $per_page = (int) $per_page;
        if ($per_page <= 0) {
            $per_page = 100;
        }

        $q = is_string($q) ? trim($q) : '';

        $cache_key = 'lsky_pro_albums_' . md5($api_url . '|' . $token . '|' . $page . '|' . $per_page . '|' . $q);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $url = rtrim($api_url, '/') . '/user/albums';
        $args = array(
            'page' => $page,
            'per_page' => $per_page,
        );
        if ($q !== '') {
            $args['q'] = $q;
        }
        $url = add_query_arg($args, $url);

        $response = wp_remote_get(
            $url,
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

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            $snippet = substr(trim($raw_body), 0, 300);
            $this->error = '获取相册列表失败：响应解析失败（HTTP ' . $http_code . '）' . ($snippet !== '' ? '；响应片段：' . $snippet : '');
            return false;
        }

        if ($http_code !== 200) {
            $msg = $data['message'] ?? 'HTTP ' . $http_code;
            $this->error = '获取相册列表失败：' . $msg;
            return false;
        }

        if (!isset($data['status']) || $data['status'] !== 'success') {
            $this->error = $data['message'] ?? '获取相册列表失败';
            return false;
        }

        // 缓存 10 分钟
        set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    /**
     * 获取全部相册（自动分页合并）
     *
     * @param string|null $q
     * @param int $per_page
     * @return array|false 返回相册 item 数组
     */
    public function get_all_albums($q = null, $per_page = 100) {
        $options = get_option('lsky_pro_options');
        $api_url = $options['lsky_pro_api_url'] ?? '';
        $token = $options['lsky_pro_token'] ?? '';

        if ($api_url === '' || $token === '') {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $per_page = (int) $per_page;
        if ($per_page <= 0) {
            $per_page = 100;
        }

        $q = is_string($q) ? trim($q) : '';
        $cache_key = 'lsky_pro_albums_all_' . md5($api_url . '|' . $token . '|' . $per_page . '|' . $q);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $first = $this->get_albums(1, $per_page, $q);
        if ($first === false) {
            return false;
        }

        $extract_items = function ($resp) {
            if (!is_array($resp) || !isset($resp['data']) || !is_array($resp['data'])) {
                return array();
            }
            // 常见分页结构：data.data
            if (isset($resp['data']['data']) && is_array($resp['data']['data'])) {
                return $resp['data']['data'];
            }
            // 有些实现可能直接把列表放在 data
            $data = $resp['data'];
            $is_list = array_keys($data) === range(0, count($data) - 1);
            if ($is_list) {
                return $data;
            }
            // 兜底：其它可能的 key
            if (isset($resp['data']['albums']) && is_array($resp['data']['albums'])) {
                return $resp['data']['albums'];
            }
            return array();
        };

        $extract_last_page = function ($resp) {
            if (!is_array($resp) || !isset($resp['data']) || !is_array($resp['data'])) {
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

        $albums = $extract_items($first);
        if (!is_array($albums)) {
            $albums = array();
        }

        $last_page = (int) $extract_last_page($first);
        if ($last_page < 1) {
            $last_page = 1;
        }

        // 安全阈值，避免接口异常导致无限分页。
        $max_pages = 50;
        if ($last_page > $max_pages) {
            $last_page = $max_pages;
        }

        for ($p = 2; $p <= $last_page; $p++) {
            $resp = $this->get_albums($p, $per_page, $q);
            if ($resp === false) {
                return false;
            }

            $page_albums = $extract_items($resp);
            if (is_array($page_albums) && !empty($page_albums)) {
                $albums = array_merge($albums, $page_albums);
            }
        }

        // 若接口返回 success 但结构无法解析/或确实为空：给出可见提示。
        if (empty($albums)) {
            $has_data = (is_array($first) && isset($first['data']) && is_array($first['data']));
            if ($has_data) {
                $keys = implode(',', array_keys($first['data']));
                $this->error = '未获取到任何相册（data keys：' . $keys . '）';
            } else {
                $this->error = '未获取到任何相册（返回结构异常）';
            }

            // 空结果不要长时间缓存，避免刚新建相册后长时间看不到。
            set_transient($cache_key, $albums, 60);
            return $albums;
        }

        set_transient($cache_key, $albums, 10 * MINUTE_IN_SECONDS);
        return $albums;
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
