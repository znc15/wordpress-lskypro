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
        $updated = false;
        
        // 获取已处理的图片URL映射
        $processed_urls = get_post_meta($post_id, '_lsky_pro_processed_urls', true) ?: array();
        
        if (preg_match_all($pattern, $content, $matches)) {
            error_log("LskyPro: 在文章 {$post_id} 中找到 " . count($matches[2]) . " 个图片");
            
            foreach ($matches[2] as $url) {
                error_log("LskyPro: 处理图片URL: {$url}");
                
                // 检查是否已经有对应的图床URL
                if (isset($processed_urls[$url])) {
                    error_log("LskyPro: 找到已处理图片的图床地址: {$processed_urls[$url]}");
                    if ($url !== $processed_urls[$url]) {
                        $content = str_replace($url, $processed_urls[$url], $content);
                        $updated = true;
                        error_log("LskyPro: 替换为图床地址");
                    }
                    continue;
                }
                
                // 处理外链图片（不是本站的图片且不是图床的图片）
                if (strpos($url, $site_url) === false && !$this->is_lsky_url($url)) {
                    error_log("LskyPro: 检测到外链图片，准备上传: {$url}");
                    
                    // 下载并上传远程图片
                    $new_url = $this->process_remote_image($url);
                    if ($new_url) {
                        error_log("LskyPro: 图片上传成功，新URL: {$new_url}");
                        $content = str_replace($url, $new_url, $content);
                        $processed_urls[$url] = $new_url; // 保存URL映射关系
                        $this->processed++;
                        $updated = true;
                    } else {
                        error_log("LskyPro: 图片处理失败: " . $this->error);
                        $this->failed++;
                    }
                } else {
                    error_log("LskyPro: 跳过本站或图床图片: {$url}");
                    $processed_urls[$url] = $url;
                }
            }
        } else {
            error_log("LskyPro: 文章 {$post_id} 中未找到需要处理的图片");
        }
        
        // 如果有更新，保存文章内容和已处理列表
        if ($updated) {
            error_log("LskyPro: 更新文章 {$post_id} 内容");
            
            // 更新文章内容
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
            
            // 保存URL映射关系
            update_post_meta($post_id, '_lsky_pro_processed_urls', $processed_urls);
            
            error_log("LskyPro: 文章处理完成 - 成功: {$this->processed}, 失败: {$this->failed}");
        }
        
        return true;
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
