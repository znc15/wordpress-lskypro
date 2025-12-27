<?php
declare(strict_types=1);

/**
 * 处理文件上传的类
 */
class LskyProUploadHandler {
    /**
     * 初始化上传处理器
     */
    public function __construct() {
        add_filter('wp_handle_upload', array($this, 'handle_upload'), 10, 1);
        add_filter('wp_handle_upload_prefilter', array($this, 'pre_upload'));
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('wp_calculate_image_srcset', '__return_false');
        add_filter('wp_generate_attachment_metadata', array($this, 'attachment_metadata'), 10, 2);
        add_filter('wp_image_editors', array($this, 'disable_image_editors'));
    }

    /**
     * 处理文件上传
     */
    public function handle_upload($file_array) {
        error_log('处理上传文件: ' . print_r($file_array, true));
        
        if (!preg_match('!^image/!', $file_array['type'])) {
            return $file_array;
        }
        
        try {
            $uploader = new LskyProUploader();
            $image_url = $uploader->upload($file_array['file']);
            
            if ($image_url) {
                error_log('图床上传成功: ' . $image_url);
                
                // 保存原始文件路径
                $original_file = $file_array['file'];
                
                // 修改文件数组
                $file_array['url'] = $image_url;
                $file_array['file'] = $image_url;
                
                // 删除本地文件
                if (file_exists($original_file)) {
                    @unlink($original_file);
                    error_log('删除本地文件: ' . $original_file);
                }
                
                // 触发上传成功事件
                do_action('lsky_pro_upload_success', $image_url);
                
                // 添加成功通知
                add_action('admin_notices', function() use ($image_url) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p>图片已成功上传到图床！URL: ' . esc_url($image_url) . '</p>';
                    echo '</div>';
                });
            } else {
                $error_message = (string) $uploader->getError();
                if ($error_message === '') {
                    $error_message = '上传失败: 未知错误';
                }

                error_log('图床上传失败: ' . $error_message);
                
                // 触发上传失败事件
                do_action('lsky_pro_upload_error', $error_message);
                
                // 添加错误通知
                add_action('admin_notices', function() use ($uploader) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p>图床上传失败：' . esc_html($uploader->getError()) . '</p>';
                    echo '</div>';
                });

                // 让 WordPress 上传流程感知失败，并在媒体库/编辑器里显示错误。
                $file_array['error'] = '图床上传失败：' . $error_message;
            }
        } catch (Exception $e) {
            error_log('上传处理异常: ' . $e->getMessage());
            
            // 触发上传异常事件
            do_action('lsky_pro_upload_exception', $e);

            // 同样把异常回传给上传流程，避免“看起来成功但其实失败”。
            $file_array['error'] = '图床上传异常：' . $e->getMessage();
        }
        
        return $file_array;
    }

    /**
     * 上传前处理
     */
    public function pre_upload($file) {
        error_log('预处理上传文件: ' . print_r($file, true));
        return $file;
    }

    /**
     * 过滤附件URL
     */
    public function filter_attachment_url($url, $post_id) {
        $lsky_url = get_post_meta($post_id, '_lsky_pro_url', true);
        return $lsky_url ? $lsky_url : $url;
    }

    /**
     * 处理附件元数据
     */
    public function attachment_metadata($metadata, $attachment_id) {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (strpos($file, 'http') === 0) {
            update_post_meta($attachment_id, '_lsky_pro_url', $file);
        }
        return $metadata;
    }

    /**
     * 禁用图片编辑器
     */
    public function disable_image_editors() {
        return array();
    }
}
