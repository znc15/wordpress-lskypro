<?php
/**
 * 处理历史文章中的图片
 */
class LskyProBatch {
    private $uploader;
    private $processed = 0;
    private $success = 0;
    private $failed = 0;
    private $batch_size = 10; // 每批处理的文章数
    
    public function __construct() {
        $this->uploader = new LskyProUploader();
        
        // 注册 AJAX 处理器
        add_action('wp_ajax_lsky_pro_process_media_batch', array($this, 'handle_ajax'));
        add_action('wp_ajax_lsky_pro_process_post_batch', array($this, 'handle_ajax'));
    }
    
    /**
     * 处理AJAX请求
     */
    public function handle_ajax() {
        check_ajax_referer('lsky_pro_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $action = $_POST['action'];
        
        // 重置计数器
        $this->processed = 0;
        $this->success = 0;
        $this->failed = 0;
        
        if ($action === 'lsky_pro_process_media_batch') {
            $result = $this->process_media_batch();
        } else if ($action === 'lsky_pro_process_post_batch') {
            $result = $this->process_batch();
        } else {
            wp_send_json_error(array('message' => '无效的操作类型'));
            return;
        }
        
        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => '处理失败'));
        }
    }
    
    /**
     * 处理媒体库图片
     */
    private function process_media_batch() {
        global $wpdb;
        
        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.guid, pm.meta_value as lsky_url 
                FROM {$wpdb->posts} p 
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lsky_pro_url'
                WHERE p.post_type = 'attachment' 
                AND p.post_mime_type LIKE 'image/%'
                LIMIT %d",
                $this->batch_size
            )
        );
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type LIKE 'image/%'"
        );
        
        $processed_items = array();
        foreach ($attachments as $attachment) {
            $this->processed++;
            
            // 检查是否已经处理过
            if (!empty($attachment->lsky_url)) {
                $this->success++;
                $processed_items[] = array(
                    'success' => true,
                    'original' => basename($attachment->guid),
                    'new_url' => $attachment->lsky_url,
                    'status' => 'already_processed'
                );
                continue;
            }
            
            $file = get_attached_file($attachment->ID);
            if ($file && file_exists($file)) {
                try {
                    $new_url = $this->uploader->upload($file);
                    if ($new_url) {
                        update_post_meta($attachment->ID, '_lsky_pro_url', $new_url);
                        $this->success++;
                        $processed_items[] = array(
                            'success' => true,
                            'original' => basename($file),
                            'new_url' => $new_url,
                            'status' => 'newly_processed'
                        );
                    } else {
                        $this->failed++;
                        $processed_items[] = array(
                            'success' => false,
                            'original' => basename($file),
                            'error' => $this->uploader->getError(),
                            'status' => 'failed'
                        );
                    }
                } catch (Exception $e) {
                    $this->failed++;
                    $processed_items[] = array(
                        'success' => false,
                        'original' => basename($file),
                        'error' => $e->getMessage(),
                        'status' => 'failed'
                    );
                }
            }
        }
        
        return array(
            'processed' => $this->processed,
            'success' => $this->success,
            'failed' => $this->failed,
            'total' => $total,
            'completed' => count($attachments) < $this->batch_size,
            'processed_items' => $processed_items,
            'message' => sprintf(
                '已处理 %d 张图片，成功 %d 张，失败 %d 张',
                $this->processed,
                $this->success,
                $this->failed
            )
        );
    }
    
    /**
     * 处理文章中的图片
     */
    private function process_batch() {
        global $wpdb;
        
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content, post_title 
                FROM {$wpdb->posts} 
                WHERE post_type IN ('post', 'page') 
                AND post_status = 'publish' 
                AND post_content LIKE '%<img%'
                LIMIT %d",
                $this->batch_size
            )
        );
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type IN ('post', 'page') 
            AND post_status = 'publish' 
            AND post_content LIKE '%<img%'"
        );
        
        $processed_items = array();
        foreach ($posts as $post) {
            $this->processed++;
            $content = $post->post_content;
            $pattern = '/<img[^>]+src=([\'"])((?:http|https):\/\/[^>]+?)\1[^>]*>/i';
            
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[2] as $url) {
                    // 检查是否已经是图床URL
                    if (strpos($url, parse_url(get_option('lsky_pro_options')['lsky_pro_api_url'], PHP_URL_HOST)) !== false) {
                        $processed_items[] = array(
                            'success' => true,
                            'original' => $url,
                            'new_url' => $url,
                            'status' => 'already_processed'
                        );
                        continue;
                    }
                    
                    try {
                        $temp_file = $this->download_remote_image($url);
                        if (!$temp_file) {
                            $this->failed++;
                            $processed_items[] = array(
                                'success' => false,
                                'original' => $url,
                                'error' => '下载图片失败',
                                'status' => 'failed'
                            );
                            continue;
                        }
                        
                        $new_url = $this->uploader->upload($temp_file);
                        if ($new_url) {
                            $content = str_replace($url, $new_url, $content);
                            $this->success++;
                            $processed_items[] = array(
                                'success' => true,
                                'original' => $url,
                                'new_url' => $new_url,
                                'status' => 'newly_processed'
                            );
                        } else {
                            $this->failed++;
                            $processed_items[] = array(
                                'success' => false,
                                'original' => $url,
                                'error' => $this->uploader->getError(),
                                'status' => 'failed'
                            );
                        }
                        
                        @unlink($temp_file);
                    } catch (Exception $e) {
                        $this->failed++;
                        $processed_items[] = array(
                            'success' => false,
                            'original' => $url,
                            'error' => $e->getMessage(),
                            'status' => 'failed'
                        );
                    }
                }
            }
            
            if ($content !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $content
                ));
            }
        }
        
        return array(
            'processed' => $this->processed,
            'success' => $this->success,
            'failed' => $this->failed,
            'total' => $total,
            'completed' => count($posts) < $this->batch_size,
            'processed_items' => $processed_items,
            'message' => sprintf(
                '已处理 %d 篇文章中的图片，成功 %d 张，失败 %d 张',
                $this->processed,
                $this->success,
                $this->failed
            )
        );
    }
    
    /**
     * 下载远程图片
     */
    private function download_remote_image($url) {
        $tmp_dir = wp_upload_dir()['basedir'] . '/temp';
        if (!file_exists($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }
        
        $temp_file = $tmp_dir . '/' . uniqid('batch_') . '_' . basename($url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        if (file_put_contents($temp_file, $image_data) === false) {
            return false;
        }
        
        return $temp_file;
    }
}

// 初始化批量处理类
function lsky_pro_init_batch() {
    new LskyProBatch();
}
add_action('init', 'lsky_pro_init_batch'); 