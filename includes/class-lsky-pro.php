<?php

class LskyPro {
    public function __construct() {
        // 添加 AJAX 处理函数
        add_action('wp_ajax_lsky_pro_check_cron_status', array($this, 'handle_check_cron_status'));
        add_action('wp_ajax_lsky_pro_get_cron_logs', array($this, 'handle_get_cron_logs'));
        add_action('wp_ajax_lsky_pro_save_cron_password', array($this, 'handle_save_cron_password'));
        
        // 添加初始化 action
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts() {
        // 确保只在插件页面加载
        if (isset($_GET['page']) && $_GET['page'] === 'lsky-pro-setup') {
            wp_localize_script('lsky-pro-setup-2', 'lskyProSetup2Data', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'cronStatusNonce' => wp_create_nonce('lsky_pro_cron_status'),
                'cronLogsNonce' => wp_create_nonce('lsky_pro_cron_logs')
            ));
        }
    }

    // 处理检查计划任务状态的请求
    public function handle_check_cron_status() {
        // 验证 nonce
        if (!check_ajax_referer('lsky_pro_cron_status', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => '安全验证失败'
            ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '权限不足'
            ));
            return;
        }

        try {
            // 获取 cron 状态
            $status = get_option('lsky_pro_cron_status', array());
            $last_run = get_option('lsky_pro_cron_last_run');

            if (!$last_run) {
                wp_send_json_error(array(
                    'message' => '计划任务尚未执行'
                ));
                return;
            }

            // 检查最后运行时间
            $time_diff = time() - $last_run;
            
            if ($time_diff > 7200) { // 2小时
                wp_send_json_error(array(
                    'message' => '计划任务已超过2小时未执行',
                    'last_run' => human_time_diff($last_run, time()) . '前'
                ));
                return;
            }

            wp_send_json_success(array(
                'message' => '计划任务运行正常',
                'last_run' => human_time_diff($last_run, time()) . '前',
                'status' => $status
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => '检查失败：' . $e->getMessage()
            ));
        }
    }

    // 处理获取日志的请求
    public function handle_get_cron_logs() {
        // 添加调试信息
        error_log('Received get_cron_logs request');
        error_log('POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(array('message' => 'Nonce is missing'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'lsky_pro_cron_logs')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        try {
            $log_file = WP_CONTENT_DIR . '/lsky-pro-cron.log';
            $logs = array();
            
            if (file_exists($log_file)) {
                $lines = array_slice(array_filter(file($log_file)), -10);
                $logs = array_map(function($line) {
                    return array(
                        'time' => date('H:i:s'),
                        'message' => trim($line),
                        'type' => 'info'
                    );
                }, $lines);
            }
            
            wp_send_json_success(array(
                'logs' => $logs
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => '获取日志失败：' . $e->getMessage()
            ));
        }
    }

    public function handle_save_cron_password() {
        if (!check_ajax_referer('lsky_pro_cron_password', 'nonce', false)) {
            wp_send_json_error(array('message' => '安全验证失败'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
            return;
        }
        
        $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
        
        if (empty($password)) {
            wp_send_json_error(array('message' => '密码不能为空'));
            return;
        }
        
        // 使用 WordPress 的密码哈希函数
        $hashed_password = wp_hash_password($password);
        
        // 保存到数据库
        if (update_option('lsky_pro_cron_password', $hashed_password)) {
            wp_send_json_success(array('message' => '密码设置成功'));
        } else {
            wp_send_json_error(array('message' => '密码保存失败'));
        }
    }
} 