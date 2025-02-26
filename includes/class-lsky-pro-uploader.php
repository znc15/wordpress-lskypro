<?php
/**
 * 上传图片到图床
 */
if (!defined('ABSPATH')) {
    exit;
}

class LskyProUploader {
    private $api_url;
    private $token;
    private $error;
    private $log_dir;
    private $requirements = array();
    
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
    
    private function initializeLogDirectory() {
        // 如果目录不存在，创建它
        if (!file_exists($this->log_dir)) {
            if (!wp_mkdir_p($this->log_dir)) {
                error_log('无法创建日志目录: ' . $this->log_dir);
                return false;
            }
        }
        
        // 确保目录可写
        if (!is_writable($this->log_dir)) {
            chmod($this->log_dir, 0755);
            if (!is_writable($this->log_dir)) {
                error_log('日志目录不可写: ' . $this->log_dir);
                return false;
            }
        }
        
        // 创建 .htaccess 文件
        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }
        
        // 创建 index.php 文件
        $index_file = $this->log_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
        
        // 初始化日志文件
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
            rtrim($options['lsky_pro_api_url'], '/') . '/profile',
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
        if (!isset($body['status']) || $body['status'] !== true) {
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
     * 检查环境要求
     */
    private function checkRequirements() {
        // 检查PHP版本
        $this->requirements['php_version'] = array(
            'name' => 'PHP版本',
            'required' => '7.2.0',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '7.2.0', '>='),
            'error' => 'PHP版本必须 >= 7.2.0'
        );
        
        // 检查必要的PHP扩展
        $required_extensions = array(
            'curl' => '必须安装CURL扩展',
            'json' => '必须安装JSON扩展',
            'fileinfo' => '必须安装FileInfo扩展'
        );
        
        foreach ($required_extensions as $ext => $error) {
            $this->requirements[$ext] = array(
                'name' => strtoupper($ext) . '扩展',
                'required' => true,
                'current' => extension_loaded($ext),
                'status' => extension_loaded($ext),
                'error' => $error
            );
        }
        
        // 检查PHP配置
        $php_configs = array(
            'file_uploads' => array(
                'name' => '文件上传',
                'required' => true,
                'error' => '必须启用文件上传功能'
            ),
            'upload_max_filesize' => array(
                'name' => '最大上传大小',
                'required' => '10M',
                'error' => '建议设置不小于10M'
            ),
            'post_max_size' => array(
                'name' => '最大POST大小',
                'required' => '10M',
                'error' => '建议设置不小于10M'
            ),
            'max_execution_time' => array(
                'name' => '最大执行时间',
                'required' => 30,
                'error' => '建议设置不小于30秒'
            ),
            'memory_limit' => array(
                'name' => '内存限制',
                'required' => '128M',
                'error' => '建议设置不小于128M'
            )
        );
        
        foreach ($php_configs as $key => $config) {
            $current = ini_get($key);
            $this->requirements['php_' . $key] = array(
                'name' => $config['name'],
                'required' => $config['required'],
                'current' => $current,
                'status' => $this->checkPhpValue($current, $config['required']),
                'error' => $config['error']
            );
        }
    }
    
    /**
     * 检查PHP配置值
     */
    private function checkPhpValue($current, $required) {
        // 如果是布尔值要求
        if (is_bool($required)) {
            return filter_var($current, FILTER_VALIDATE_BOOLEAN) === $required;
        }
        
        // 如果是数字要求
        if (is_numeric($required)) {
            return intval($current) >= intval($required);
        }
        
        // 如果是内存大小要求（如：128M, 10M等）
        $current_bytes = $this->convertToBytes($current);
        $required_bytes = $this->convertToBytes($required);
        
        return $current_bytes >= $required_bytes;
    }
    
    /**
     * 转换PHP大小表示到字节数
     */
    private function convertToBytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * 获取环境检查结果
     */
    public function getRequirements() {
        return $this->requirements;
    }
    
    /**
     * 检查环境是否满足要求
     */
    public function checkEnvironment() {
        $errors = array();
        foreach ($this->requirements as $key => $requirement) {
            if (!$requirement['status']) {
                $errors[] = $requirement['error'];
            }
        }
        
        if (!empty($errors)) {
            $this->error = "环境检查失败：\n" . implode("\n", $errors);
            return false;
        }
        
        return true;
    }
    
    public function upload($file_path) {
        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            return false;
        }
        
        // 获取存储策略ID
        $options = get_option('lsky_pro_options');
        $strategy_id = isset($options['strategy_id']) ? intval($options['strategy_id']) : 1;
        
        // 检查图片文件
        $image_info = $this->checkImageFile($file_path);
        if ($image_info === false) {
            error_log('图片检查失败: ' . $this->error);
            return false;
        }

        // 记录图片信息
        $this->logImageInfo($file_path, $image_info);

        // 准备上传
        $cfile = new CURLFile($file_path, $image_info['mime_type'], basename($file_path));
        
        // 确保 strategy_id 是整数
        error_log('使用存储策略ID: ' . $strategy_id);
        
        $post_data = array(
            'file' => $cfile,
            'strategy_id' => (int)$strategy_id // 确保是整数
        );
        
        error_log('准备发送请求到: ' . $this->api_url . '/upload');
        
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => rtrim($this->api_url, '/') . '/upload',
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
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'User-Agent: WordPress/LskyPro-Uploader'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => true
        ));
        
        // 执行请求
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);
        
        // 记录请求信息
        error_log('请求响应状态码: ' . $http_code);
        error_log('请求响应内容: ' . $response);
        
        // 处理错误
        if ($err) {
            $this->error = 'CURL错误: ' . $err;
            return false;
        }
        
        if (empty($response)) {
            $this->error = '服务器没有响应';
            return false;
        }
        
        // 解析响应
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = '无效的API响应格式';
            return false;
        }
        
        // 检查API响应
        if (!isset($result['status']) || $result['status'] !== true) {
            $this->error = '上传失败: ' . ($result['message'] ?? '未知错误');
            return false;
        }
        
        // 获取图片URL
        $image_url = $result['data']['links']['url'] ?? false;
        if (!$image_url) {
            $this->error = '无法获取图片URL';
            return false;
        }
        
        // 记录成功
        $this->logSuccess(basename($file_path), $image_url);
        error_log('上传成功，图片URL: ' . $image_url);
        
        return $image_url;
    }
    
    private function getMimeType($file_path) {
        // 首选方法：使用 fileinfo 扩展
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            return $mime_type;
        }
        
        // 备选方法1：使用 mime_content_type 函数
        if (function_exists('mime_content_type')) {
            return mime_content_type($file_path);
        }
        
        // 备选方法2：根据文件扩展名判断
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
        );
        
        return isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
    }
    
    /**
     * 检查图片文件的有效性
     */
    private function checkImageFile($file_path) {
        // 检查文件是否存在和可读性
        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->error = '文件不存在或不可读: ' . $file_path;
            return false;
        }

        // 检查文件大小
        $filesize = filesize($file_path);
        if ($filesize === false || $filesize === 0) {
            $this->error = '无效的文件大小';
            return false;
        }

        // 检查文件大小限制（默认20MB）
        $max_size = 20 * 1024 * 1024;
        if ($filesize > $max_size) {
            $this->error = sprintf('文件大小超过限制: %s (最大: %s)', 
                $this->formatFileSize($filesize), 
                $this->formatFileSize($max_size)
            );
            return false;
        }

        // 获取图片信息
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            $this->error = '无效的图片文件';
            return false;
        }

        // 允许的MIME类型
        $allowed_types = array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/tiff',
            'image/bmp',
            'image/x-icon',
            'image/vnd.adobe.photoshop',
            'image/webp'
        );

        // 允许的文件扩展名
        $allowed_extensions = array(
            'jpeg', 'jpg', 'png', 'gif', 'tif', 'tiff', 
            'bmp', 'ico', 'psd', 'webp'
        );

        // 检查文件扩展名
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            $this->error = '不支持的文件扩展名: ' . $ext;
            return false;
        }

        // 检查MIME类型
        $mime_type = $image_info['mime'];
        if (!in_array($mime_type, $allowed_types)) {
            $this->error = '不支持的文件类型: ' . $mime_type;
            return false;
        }

        return array(
            'mime_type' => $mime_type,
            'width' => $image_info[0],
            'height' => $image_info[1],
            'size' => $filesize,
            'extension' => $ext
        );
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize($size) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * 记录图片信息
     */
    private function logImageInfo($file_path, $image_info) {
        error_log(sprintf(
            '图片信息: %s (类型: %s, 尺寸: %dx%d, 大小: %s)',
            basename($file_path),
            $image_info['mime_type'],
            $image_info['width'],
            $image_info['height'],
            $this->formatFileSize($image_info['size'])
        ));
    }

    private function try_upload($file_path) {
        // 基本检查
        if (empty($this->api_url) || empty($this->token)) {
            $this->error = '未配置API地址或Token';
            return false;
        }
        
        // 获取存储策略ID
        $options = get_option('lsky_pro_options');
        $strategy_id = isset($options['strategy_id']) ? intval($options['strategy_id']) : 1;
        
        // 检查图片文件
        $image_info = $this->checkImageFile($file_path);
        if ($image_info === false) {
            error_log('图片检查失败: ' . $this->error);
            return false;
        }

        // 记录图片信息
        $this->logImageInfo($file_path, $image_info);

        // 准备上传
        $cfile = new CURLFile($file_path, $image_info['mime_type'], basename($file_path));
        $post_data = array(
            'file' => $cfile,
            'strategy_id' => $strategy_id
        );
        
        error_log('准备发送请求到: ' . $this->api_url . '/upload，使用存储策略ID: ' . $strategy_id);
        
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => rtrim($this->api_url, '/') . '/upload',
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
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'User-Agent: WordPress/LskyPro-Uploader',
                'Connection: Keep-Alive'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => true
        ));
        
        // 执行请求
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);
        
        // 处理错误
        if ($err) {
            $this->error = 'CURL错误: ' . $err;
            return false;
        }
        
        if (empty($response)) {
            $this->error = '服务器没有响应';
            return false;
        }
        
        // 解析响应
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = '无效的API响应格式';
            return false;
        }
        
        // 检查HTTP状态码
        if ($http_code !== 200) {
            $error_message = isset($result['message']) ? $result['message'] : '未知错误';
            $this->error = sprintf('HTTP错误(%d): %s', $http_code, $error_message);
            return false;
        }
        
        // 检查API响应
        if (!isset($result['status']) || $result['status'] !== true) {
            $this->error = '上传失败: ' . ($result['message'] ?? '未知错误');
            return false;
        }
        
        // 获取图片URL
        $image_url = $result['data']['links']['url'] ?? false;
        if (!$image_url) {
            $this->error = '无法获取图片URL';
            return false;
        }
        
        // 记录成功
        $this->logSuccess(basename($file_path), $image_url);
        error_log('上传成功，图片URL: ' . $image_url);
        
        return $image_url;
    }
    
    private function logSuccess($filename, $url) {
        $log_file = $this->log_dir . '/upload.log';
        $log_message = sprintf(
            "[%s] 成功：%s => %s\n",
            date('Y-m-d H:i:s'),
            $filename,
            $url
        );
        
        // 添加调试信息
        error_log('尝试写入成功日志: ' . $log_file);
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
        
        // 添加调试信息
        error_log('尝试写入错误日志: ' . $log_file);
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

    /**
     * 获取存储策略列表
     */
    public function get_strategies() {
        $options = get_option('lsky_pro_options');
        if (empty($options['lsky_pro_api_url']) || empty($options['lsky_pro_token'])) {
            $this->error = '请先配置 API 地址和 Token';
            return false;
        }

        $response = wp_remote_get(
            rtrim($options['lsky_pro_api_url'], '/') . '/strategies',
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

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['status']) || $data['status'] !== true) {
            $this->error = $data['message'] ?? '获取存储策略失败';
            return false;
        }

        return $data['data']['strategies'] ?? array();
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