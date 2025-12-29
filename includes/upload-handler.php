<?php
declare(strict_types=1);

/**
 * 处理文件上传的类
 */
class LskyProUploadHandler {
    public function __construct() {
        add_filter('wp_handle_upload', array($this, 'handle_upload'), 10, 1);
        add_filter('wp_handle_upload_prefilter', array($this, 'pre_upload'));
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('wp_calculate_image_srcset', '__return_false');
        add_filter('wp_generate_attachment_metadata', array($this, 'attachment_metadata'), 10, 2);
        add_filter('wp_image_editors', array($this, 'disable_image_editors'));
        add_action('delete_attachment', array($this, 'handle_delete_attachment'), 10, 1);
    }

    private function debug_log($message, $context = null) {
        if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
            return;
        }

        if ($context !== null) {
            $message .= ' ' . print_r($context, true);
        }

        error_log('[LskyPro] ' . (string) $message);
    }

    /**
     * 写入错误日志到插件日志文件
     */
    private function log_error($filename, $error) {
        $log_dir = LSKY_PRO_PLUGIN_DIR . 'logs';
        $log_file = $log_dir . '/error.log';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $log_message = sprintf(
            "[%s] 失败：%s - 错误：%s（本地文件已保留）\n",
            date('Y-m-d H:i:s'),
            basename($filename),
            $error
        );

        file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    }

    private function add_admin_notice($type, $message) {
        $type = $type === 'error' ? 'error' : 'success';
        $message = (string) $message;

        add_action('admin_notices', function () use ($type, $message) {
            $class = $type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
            echo '<div class="' . esc_attr($class) . '">';
            echo '<p>' . wp_kses_post($message) . '</p>';
            echo '</div>';
        });
    }

    private function get_uploaded_photo_id_transient_key($image_url) {
        return 'lsky_pro_uploaded_photo_id_' . md5((string) $image_url);
    }

    private function normalize_file_path(string $path): string {
        $p = $path;
        if (function_exists('wp_normalize_path')) {
            $p = wp_normalize_path($p);
        }
        return $p;
    }

    private function get_pending_upload_transient_key(string $local_file_path): string {
        $p = $this->normalize_file_path(trim($local_file_path));
        return 'lsky_pro_pending_upload_' . md5($p);
    }

    /**
     * 处理文件上传
     */
    public function handle_upload($file_array) {
        $this->debug_log('handle_upload', $file_array);

        $mime_type = isset($file_array['type']) ? (string) $file_array['type'] : '';
        if ($mime_type === '' || !preg_match('!^image/!', $mime_type)) {
            return $file_array;
        }

        $local_file_path = isset($file_array['file']) ? (string) $file_array['file'] : '';
        if ($local_file_path === '') {
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
                $this->debug_log('excluded: skip upload to lsky');
                return $file_array;
            }
        }
        
        try {
            $uploader = new LskyProUploader();
            $image_url = $uploader->upload($local_file_path);
            
            if ($image_url) {
                $this->debug_log('upload success: ' . $image_url);

                $photo_id = $uploader->getLastUploadedPhotoId();
                $pending = array(
                    'url' => (string) $image_url,
                    'photo_id' => null,
                    'created_at' => time(),
                );

                if (is_numeric($photo_id)) {
                    $photo_id = (int) $photo_id;
                    if ($photo_id > 0) {
                        $pending['photo_id'] = $photo_id;
                    }
                }

                // 不改写 WordPress 的本地文件路径字段，避免影响附件元数据生成与特色图渲染。
                // 在尚未拿到 attachment_id 的阶段，用本地绝对路径作为 key 暂存图床信息。
                set_transient($this->get_pending_upload_transient_key($local_file_path), $pending, 2 * HOUR_IN_SECONDS);

                do_action('lsky_pro_upload_success', $image_url);

                $this->add_admin_notice('success', '图片已成功上传到图床！URL: ' . esc_url($image_url));
            } else {
                $error_message = (string) $uploader->getError();
                if ($error_message === '') {
                    $error_message = '上传失败: 未知错误';
                }

                $this->debug_log('upload failed: ' . $error_message);
                $this->log_error($local_file_path, $error_message);

                do_action('lsky_pro_upload_error', $error_message);

                $this->add_admin_notice('error', '图床上传失败（图片已保留在本地）：' . esc_html($error_message));

                // 不设置 $file_array['error']，让 WordPress 本地上传正常完成
                // 图床上传失败时降级为仅保留本地文件，避免"吞封面"问题
            }
        } catch (Exception $e) {
            $this->debug_log('upload exception: ' . $e->getMessage());
            $this->log_error($local_file_path, '异常：' . $e->getMessage());

            do_action('lsky_pro_upload_exception', $e);

            $this->add_admin_notice('error', '图床上传异常（图片已保留在本地）：' . esc_html($e->getMessage()));

            // 不设置 $file_array['error']，让 WordPress 本地上传正常完成
            // 图床上传异常时降级为仅保留本地文件，避免"吞封面"问题
        }
        
        return $file_array;
    }

    /**
     * 上传前处理
     */
    public function pre_upload($file) {
        $this->debug_log('pre_upload', $file);
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
        if (function_exists('lsky_pro_apply_pending_exclusion_for_attachment')) {
            lsky_pro_apply_pending_exclusion_for_attachment((int) $attachment_id);
        }

        // 优先从“按本地路径暂存”的上传结果落库，避免依赖 _wp_attached_file 的字符串形态。
        $attached_file_path = function_exists('get_attached_file') ? (string) get_attached_file((int) $attachment_id) : '';
        if ($attached_file_path !== '' && function_exists('get_transient')) {
            $pending_key = $this->get_pending_upload_transient_key($attached_file_path);
            $pending = get_transient($pending_key);
            if (is_array($pending)) {
                $new_url = isset($pending['url']) ? trim((string) $pending['url']) : '';
                if ($new_url !== '') {
                    update_post_meta($attachment_id, '_lsky_pro_url', $new_url);
                }

                $photo_id = isset($pending['photo_id']) ? $pending['photo_id'] : null;
                if (is_numeric($photo_id)) {
                    $photo_id = (int) $photo_id;
                    if ($photo_id > 0) {
                        update_post_meta($attachment_id, '_lsky_pro_photo_id', $photo_id);
                    }
                }

                if (function_exists('delete_transient')) {
                    delete_transient($pending_key);
                }

                return $metadata;
            }
        }

        // 兼容旧逻辑：若某些历史版本/外部逻辑把 _wp_attached_file 写成了 URL，仍按 URL 回填。
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file !== '' && strpos($file, 'http') === 0) {
            update_post_meta($attachment_id, '_lsky_pro_url', $file);

            $key = $this->get_uploaded_photo_id_transient_key($file);
            $photo_id = function_exists('get_transient') ? get_transient($key) : false;
            if (is_numeric($photo_id)) {
                $photo_id = (int) $photo_id;
                if ($photo_id > 0) {
                    update_post_meta($attachment_id, '_lsky_pro_photo_id', $photo_id);
                }
            }
            if (function_exists('delete_transient')) {
                delete_transient($key);
            }
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
            $this->debug_log('delete remote exception: ' . $e->getMessage());
        }
    }

    /**
     * 禁用图片编辑器
     */
    public function disable_image_editors() {
        return array();
    }
}
