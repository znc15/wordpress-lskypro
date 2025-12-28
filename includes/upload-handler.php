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

        // 删除附件时，同时删除远端图床图片（基于保存的图片ID）。
        add_action('delete_attachment', array($this, 'handle_delete_attachment'), 10, 1);
    }

    private function get_uploaded_photo_id_transient_key($image_url) {
        return 'lsky_pro_uploaded_photo_id_' . md5((string) $image_url);
    }

    /**
     * 处理文件上传
     */
    public function handle_upload($file_array) {
        error_log('处理上传文件: ' . print_r($file_array, true));
        
        if (!preg_match('!^image/!', $file_array['type'])) {
            return $file_array;
        }

        // 某些场景（例如站点图标、用户头像）必须保留本地文件与本地 URL。
        // 命中排除规则时，直接跳过图床同步。
        if (function_exists('lsky_pro_should_upload_to_lsky')) {
            $should_upload = lsky_pro_should_upload_to_lsky(
                array(
                    'file_path' => isset($file_array['file']) ? (string) $file_array['file'] : '',
                    'mime_type' => isset($file_array['type']) ? (string) $file_array['type'] : '',
                    'attachment_id' => null,
                    'source' => 'upload',
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
                error_log('LskyPro: 命中排除规则，跳过图床上传');
                return $file_array;
            }
        }
        
        try {
            $uploader = new LskyProUploader();
            $image_url = $uploader->upload($file_array['file']);
            
            if ($image_url) {
                error_log('图床上传成功: ' . $image_url);

                // 保存图片ID（如果上传响应包含），用于后续删除远端图片。
                $photo_id = $uploader->getLastUploadedPhotoId();
                if (is_numeric($photo_id)) {
                    $photo_id = (int) $photo_id;
                    if ($photo_id > 0) {
                        set_transient($this->get_uploaded_photo_id_transient_key($image_url), $photo_id, 2 * HOUR_IN_SECONDS);
                    }
                }
                
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

            $key = $this->get_uploaded_photo_id_transient_key($file);
            $photo_id = get_transient($key);
            if (is_numeric($photo_id)) {
                $photo_id = (int) $photo_id;
                if ($photo_id > 0) {
                    update_post_meta($attachment_id, '_lsky_pro_photo_id', $photo_id);
                }
            }
            delete_transient($key);
        }
        return $metadata;
    }

    /**
     * 删除附件时删除远端图片。
     */
    public function handle_delete_attachment($attachment_id) {
        $photo_id = get_post_meta($attachment_id, '_lsky_pro_photo_id', true);
        $photo_id = absint($photo_id);
        if ($photo_id <= 0) {
            return;
        }

        try {
            $uploader = new LskyProUploader();
            $uploader->delete_photos(array($photo_id));
        } catch (Exception $e) {
            // 删除流程不阻塞 WordPress 本地删除。
        }
    }

    /**
     * 禁用图片编辑器
     */
    public function disable_image_editors() {
        return array();
    }
}
