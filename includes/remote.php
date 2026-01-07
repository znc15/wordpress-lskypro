<?php
declare(strict_types=1);

/**
 * 远程图片处理类
 */
class LskyProRemote {
    private $uploader;
    private $error;
    private $processed = 0;
    private $failed = 0;
    
    public function __construct() {
        $this->uploader = new LskyProUploader();
    }
    
    /**
     * 处理文章内容中的远程图片
     */
    public function process_post_images($post_id) {
        // 尽量避免因处理时间过长导致中途超时。
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            $this->error = '无效的文章ID';
            return false;
        }

        // Post 级锁：避免 save_post/wp_update_post 递归触发或并发重复处理。
        $lock_meta_key = '_lsky_pro_remote_processing_lock';
        $lock_ttl_seconds = 10 * 60;
        $now = time();
        $existing_lock = get_post_meta($post_id, $lock_meta_key, true);
        if (is_numeric($existing_lock)) {
            $existing_lock = (int) $existing_lock;
            if ($existing_lock > 0 && ($now - $existing_lock) < $lock_ttl_seconds) {
                $this->error = '文章正在处理远程图片，请稍后重试';
                error_log("LskyPro: 文章 {$post_id} 正在处理中，跳过本次处理");
                return false;
            }
        }

        // 清理过期锁并尝试加锁。
        delete_post_meta($post_id, $lock_meta_key);
        if (!add_post_meta($post_id, $lock_meta_key, (string) $now, true)) {
            // 可能存在并发竞争；再次读取确认。
            $this->error = '文章正在处理远程图片，请稍后重试';
            error_log("LskyPro: 文章 {$post_id} 加锁失败，可能并发处理中");
            return false;
        }

        try {
        // 注入“由哪篇文章触发”的上传日志上下文（写入 logs/upload.log 与 logs/error.log）。
        if ($this->uploader && method_exists($this->uploader, 'setUploadLogContextFromPost')) {
            $this->uploader->setUploadLogContextFromPost($post_id, 'post_remote_images');
        }

        $content = get_post_field('post_content', $post_id);
        if (empty($content)) {
            $this->error = '文章内容为空';
            error_log("LskyPro: 文章 {$post_id} 内容为空");
            return false;
        }

        error_log("LskyPro: 开始处理文章 {$post_id} 的远程图片");

        // 匹配所有图片标签
        $pattern = '/<img[^>]+src=([\'\"])(https?:\/\/[^>]+?)\1[^>]*>/i';
        $site_url = get_site_url();
        $uploads = wp_upload_dir();
        $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        $updated = false;
        
        // 获取已处理的图片URL映射
        $processed_urls = get_post_meta($post_id, '_lsky_pro_processed_urls', true);
        if (!is_array($processed_urls)) {
            $processed_urls = array();
        }

        // 增量持久化映射：避免中途超时导致“已上传但未落库”，重试重复上传。
        $persist_processed_urls = function() use ($post_id, &$processed_urls) {
            update_post_meta($post_id, '_lsky_pro_processed_urls', $processed_urls);
        };
        
        if (preg_match_all($pattern, $content, $matches)) {
            error_log("LskyPro: 在文章 {$post_id} 中找到 " . count($matches[2]) . " 个图片");
            
            foreach ($matches[2] as $url) {
                error_log("LskyPro: 处理图片URL: {$url}");

                // 去掉 query/hash，便于匹配附件与文件。
                $url_clean = (string) $url;
                $parsed = wp_parse_url($url_clean);
                if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host']) && !empty($parsed['path'])) {
                    $url_clean = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
                }
                
                // 检查是否已经有对应的图床URL
                if (isset($processed_urls[$url_clean])) {
                    error_log("LskyPro: 找到已处理图片的图床地址: {$processed_urls[$url_clean]}");
                    if ($url !== $processed_urls[$url_clean]) {
                        $content = str_replace($url, $processed_urls[$url_clean], $content);
                        $updated = true;
                        error_log("LskyPro: 替换为图床地址");
                    }
                    continue;
                }
                
                // 1) 处理本站媒体图片（先本地编辑，保存文章时替换到图床）
                if (strpos($url_clean, $site_url) !== false && !$this->is_lsky_url($url_clean) && $baseurl !== '' && strpos($url_clean, $baseurl) === 0) {
                    error_log("LskyPro: 检测到本站媒体图片，准备上传: {$url_clean}");

                    $new_url = $this->process_local_media_image($url_clean);
                    if ($new_url) {
                        error_log("LskyPro: 本站媒体图片上传成功，新URL: {$new_url}");
                        $content = str_replace($url, $new_url, $content);
                        $processed_urls[$url_clean] = $new_url;
                        $persist_processed_urls();
                        $this->processed++;
                        $updated = true;
                    } else {
                        error_log("LskyPro: 本站媒体图片处理失败: " . $this->error);
                        $this->failed++;
                    }

                    continue;
                }

                // 2) 处理外链图片（不是本站的图片且不是图床的图片）
                if (strpos($url_clean, $site_url) === false && !$this->is_lsky_url($url_clean)) {
                    error_log("LskyPro: 检测到外链图片，准备上传: {$url}");
                    
                    // 下载并上传远程图片
                    $new_url = $this->process_remote_image($url);
                    if ($new_url) {
                        error_log("LskyPro: 图片上传成功，新URL: {$new_url}");
                        $content = str_replace($url, $new_url, $content);
                        $processed_urls[$url_clean] = $new_url; // 保存URL映射关系
                        $persist_processed_urls();
                        $this->processed++;
                        $updated = true;
                    } else {
                        error_log("LskyPro: 图片处理失败: " . $this->error);
                        $this->failed++;
                    }
                } else {
                    error_log("LskyPro: 跳过本站或图床图片: {$url}");
                    $processed_urls[$url_clean] = $url;
                    $persist_processed_urls();
                }
            }
        } else {
            error_log("LskyPro: 文章 {$post_id} 中未找到需要处理的图片");
        }

        // 关键：即使内容没有发生替换（例如重复图片/已是图床图片），也要把映射写入数据库。
        // 否则下次遇到同样的 URL 可能会再次判断/处理，或因未落库导致状态不一致。
        $persist_processed_urls();
        
        // 如果有更新，保存文章内容和已处理列表
        if ($updated) {
            error_log("LskyPro: 更新文章 {$post_id} 内容");

            // wp_update_post 会再次触发 save_post 等钩子
            // 写入或清空封面/特色图等自定义字段。二次更新时没有这些字段，会导致用户手动填写的外链封面丢失。
            // 因此改为直接更新 posts 表并清理缓存。
            global $wpdb;
            $modified = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
            $modified_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

            $updated_rows = $wpdb->update(
                $wpdb->posts,
                array(
                    'post_content' => $content,
                    'post_modified' => $modified,
                    'post_modified_gmt' => $modified_gmt,
                ),
                array('ID' => $post_id),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($updated_rows === false) {
                error_log("LskyPro: 更新文章 {$post_id} 失败 - 数据库更新失败");
            } else {
                if (function_exists('clean_post_cache')) {
                    clean_post_cache($post_id);
                }
            }

            error_log("LskyPro: 文章处理完成 - 成功: {$this->processed}, 失败: {$this->failed}");
        }
        
        return true;

        } finally {
            if ($this->uploader && method_exists($this->uploader, 'clearUploadLogContext')) {
                $this->uploader->clearUploadLogContext();
            }
            // 无论成功/失败都释放锁，避免阻塞后续处理。
            delete_post_meta($post_id, $lock_meta_key);
        }
    }

    /**
     * 处理本站媒体库图片（上传原图到图床并记录到附件 meta）。
     */
    private function process_local_media_image($url) {
        $attachment_id = attachment_url_to_postid($url);
        $file_path = '';

        if (is_numeric($attachment_id) && (int) $attachment_id > 0) {
            $attachment_id = (int) $attachment_id;

            // 若附件已上传过图床，直接复用，避免重复上传。
            $existing_url = get_post_meta($attachment_id, '_lsky_pro_url', true);
            if (is_string($existing_url) && $existing_url !== '') {
                return $existing_url;
            }

            $file_path = get_attached_file($attachment_id);
        }

        if (!is_string($file_path) || $file_path === '' || !file_exists($file_path)) {
            // 兜底：通过 uploads baseurl -> basedir 映射。
            $uploads = wp_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
            $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            if ($baseurl !== '' && $basedir !== '' && strpos($url, $baseurl) === 0) {
                $relative = substr($url, strlen($baseurl));
                $relative = ltrim($relative, '/');
                $file_path = rtrim($basedir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            }
        }

        if (!is_string($file_path) || $file_path === '' || !file_exists($file_path)) {
            $this->error = '无法定位本地文件路径';
            return false;
        }

        // 复用上传排除规则：例如头像/站点图标应保持本地链接。
        if (function_exists('lsky_pro_should_upload_to_lsky')) {
            $should_upload = lsky_pro_should_upload_to_lsky(
                array(
                    'file_path' => $file_path,
                    'mime_type' => '',
                    'attachment_id' => (isset($attachment_id) && is_int($attachment_id) && $attachment_id > 0) ? $attachment_id : null,
                    'source' => 'post_local_media',
                ),
                array(
                    'doing_ajax' => function_exists('wp_doing_ajax') ? wp_doing_ajax() : false,
                    'action' => isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '',
                    'context' => isset($_REQUEST['context']) ? sanitize_key((string) $_REQUEST['context']) : '',
                    'referer' => function_exists('wp_get_referer') ? (string) wp_get_referer() : '',
                    'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                )
            );

            if (!$should_upload) {
                // 返回原 URL，表示“不替换为图床地址”。
                return $url;
            }
        }

        $new_url = $this->uploader->upload($file_path);
        if (!$new_url) {
            $this->error = $this->uploader->getError();
            return false;
        }

        // 若能定位到附件，则记录 meta，便于媒体库状态/删除联动。
        if (isset($attachment_id) && is_int($attachment_id) && $attachment_id > 0) {
            update_post_meta($attachment_id, '_lsky_pro_url', $new_url);
            $photo_id = $this->uploader->getLastUploadedPhotoId();
            if (is_numeric($photo_id)) {
                $photo_id = (int) $photo_id;
                if ($photo_id > 0) {
                    update_post_meta($attachment_id, '_lsky_pro_photo_id', $photo_id);
                }
            }
        }

        return $new_url;
    }
    
    /**
     * 检查URL是否为图床URL
     */
    private function is_lsky_url($url) {
        $options = get_option('lsky_pro_options');
        $api_url = $options['lsky_pro_api_url'] ?? '';
        
        if (empty($api_url)) {
            error_log("LskyPro: 未配置图床API URL");
            return false;
        }
        
        $api_domain = parse_url($api_url, PHP_URL_HOST);
        $url_domain = parse_url($url, PHP_URL_HOST);
        
        $is_lsky = $api_domain === $url_domain;
        error_log("LskyPro: 检查URL {$url} 是否为图床URL: " . ($is_lsky ? '是' : '否'));
        
        return $is_lsky;
    }
    
    /**
     * 处理单个远程图片
     */
    private function process_remote_image($url) {
        error_log("LskyPro: 开始处理远程图片: {$url}");
        
        // 下载图片到临时文件
        $temp_file = $this->download_image($url);
        if (!$temp_file) {
            error_log("LskyPro: 下载图片失败: " . $this->error);
            return false;
        }
        
        error_log("LskyPro: 图片下载成功，临时文件: {$temp_file}");
        
        // 上传到图床
        $new_url = $this->uploader->upload($temp_file);
        
        // 清理临时文件
        @unlink($temp_file);
        error_log("LskyPro: 清理临时文件: {$temp_file}");
        
        if (!$new_url) {
            $this->error = $this->uploader->getError();
            error_log("LskyPro: 上传到图床失败: " . $this->error);
            return false;
        }
        
        error_log("LskyPro: 上传到图床成功，新URL: {$new_url}");
        return $new_url;
    }
    
    /**
     * 下载远程图片
     */
    private function download_image($url) {
        $tmp_dir = wp_upload_dir()['basedir'] . '/temp';
        if (!file_exists($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }
        
        $temp_file = $tmp_dir . '/' . uniqid('remote_') . '_' . basename(parse_url($url, PHP_URL_PATH));
        error_log("LskyPro: 下载图片 {$url} 到临时文件 {$temp_file}");
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ));
        
        if (is_wp_error($response)) {
            $this->error = '下载远程图片失败: ' . $response->get_error_message();
            error_log("LskyPro: " . $this->error);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->error = "下载远程图片失败: HTTP {$response_code}";
            error_log("LskyPro: " . $this->error);
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        if (file_put_contents($temp_file, $image_data) === false) {
            $this->error = '保存临时文件失败: ' . $temp_file;
            error_log("LskyPro: " . $this->error);
            return false;
        }
        
        error_log("LskyPro: 图片下载成功");
        return $temp_file;
    }
    
    /**
     * 获取处理结果
     */
    public function get_results() {
        return array(
            'processed' => $this->processed,
            'failed' => $this->failed
        );
    }
    
    /**
     * 获取错误信息
     */
    public function getError() {
        return $this->error;
    }
}
