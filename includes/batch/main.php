<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('LskyProBatch', false)) {
    class LskyProBatch {
        private $uploader;
        private $processed = 0;
        private $success = 0;
        private $failed = 0;
        private $batch_size = 10; // 每批处理的文章数

        /**
         * 头像附件标记 meta key（1 表示为头像，批处理跳过）
         */
        private $avatar_meta_key = '_lsky_pro_is_avatar';

        /**
         * 通用：批处理跳过标记 meta key（1 表示跳过）
         */
        private $batch_skip_meta_key = '_lsky_pro_batch_skip';

        /**
         * 文件类型标记：0=非限制文件，1=限制文件（例如头像）
         */
        private $type_meta_key = '_lsky_pro_type';

        /**
         * 文章批处理完成标记 meta key（1 表示该文章已完成批处理）
         */
        private $post_done_meta_key = '_lsky_pro_post_batch_done';

        use LskyProBatch_Avatar;
        use LskyProBatch_Media;
        use LskyProBatch_Post;
        use LskyProBatch_Reset;

        public function __construct() {
            $this->uploader = new LskyProUploader();

            // 注册 AJAX 处理器
            add_action('wp_ajax_lsky_pro_process_media_batch', array($this, 'handle_ajax'));
            add_action('wp_ajax_lsky_pro_process_post_batch', array($this, 'handle_ajax'));
            add_action('wp_ajax_lsky_pro_reset_post_batch', array($this, 'handle_reset_post_batch'));
            add_action('wp_ajax_lsky_pro_reset_media_batch', array($this, 'handle_reset_media_batch'));
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
    }
}
