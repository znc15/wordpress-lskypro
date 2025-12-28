<?php
if (!defined('ABSPATH')) {
    exit;
}

class LskyProSetup {
    private $plugin_dir;
    private $install_lock;
    private $error_message;
    
    public function __construct() {
        $this->plugin_dir = plugin_dir_path(dirname(__FILE__));
        $this->install_lock = $this->plugin_dir . 'install.lock';
        
        // 将表单处理移到 admin_init 钩子
        add_action('admin_init', array($this, 'handle_setup_submission'));
        
        // 添加 AJAX 处理程序
        add_action('wp_ajax_lsky_pro_setup', array($this, 'handle_ajax_setup'));
    }
    
    public function handle_setup_submission() {
        // 检查是否有表单提交
        if (!isset($_POST['setup_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['setup_nonce'], 'lsky_pro_setup')) {
            add_settings_error(
                'lsky_pro_setup',
                'nonce_error',
                '安全验证失败',
                'error'
            );
            return;
        }
        
        if (!current_user_can('manage_options')) {
            add_settings_error(
                'lsky_pro_setup',
                'permission_error',
                '权限不足',
                'error'
            );
            return;
        }
        
        $api_url = rtrim(sanitize_url($_POST['api_url']), '/');
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        if (empty($api_url)) {
            add_settings_error(
                'lsky_pro_setup',
                'api_url_error',
                '请输入 API 地址',
                'error'
            );
            return;
        }

        if (!preg_match('~/api/v2$~', $api_url)) {
            add_settings_error(
                'lsky_pro_setup',
                'api_url_error',
                'API 地址必须以 /api/v2 结尾，例如：https://img.example.com/api/v2',
                'error'
            );
            return;
        }
        
        $options = array(
            'lsky_pro_api_url' => $api_url,
        );

        if (empty($token)) {
            add_settings_error(
                'lsky_pro_setup',
                'token_error',
                '请输入 Token',
                'error'
            );
            return;
        }
        $options['lsky_pro_token'] = $token;
        
        // 验证 Token
        $verify_response = wp_remote_get($api_url . '/user/profile', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $options['lsky_pro_token']
            )
        ));
        
        if (is_wp_error($verify_response)) {
            add_settings_error(
                'lsky_pro_setup',
                'token_verify_error',
                'Token 验证失败：' . $verify_response->get_error_message(),
                'error'
            );
            return;
        }
        
        $verify_result = json_decode(wp_remote_retrieve_body($verify_response), true);
        if (!is_array($verify_result) || !isset($verify_result['status']) || $verify_result['status'] !== 'success') {
            add_settings_error(
                'lsky_pro_setup',
                'token_verify_error',
                'Token 验证失败：' . ($verify_result['message'] ?? '未知错误'),
                'error'
            );
            return;
        }
        
        // 保存配置
        update_option('lsky_pro_options', $options);
        
        // 设置重定向标记
        set_transient('lsky_pro_show_setup_2', true, 30);
        
        // 添加成功消息
        add_settings_error(
            'lsky_pro_setup',
            'setup_success',
            '配置保存成功！',
            'success'
        );
    }
    
    public function render_setup_page() {
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('抱歉，您没有权限访问此页面。'));
        }
        
        // 检查基础设置是否已完成
        $options = get_option('lsky_pro_options');
        $required_fields = array('lsky_pro_api_url', 'lsky_pro_token');
        $is_configured = true;
        
        if ($options) {
            foreach ($required_fields as $field) {
                if (empty($options[$field])) {
                    $is_configured = false;
                    break;
                }
            }
        } else {
            $is_configured = false;
        }
        
        // 如果基础设置已完成但还没有install.lock，跳转到setup-2
        if ($is_configured) {
            ?>
            <script>
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=lsky-pro-setup-2')); ?>';
            </script>
            <?php
            return;
        }

        // 检查是否已经完全安装（有install.lock文件）
        if (file_exists($this->install_lock)) {
            ?>
            <script>
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=lsky-pro-config')); ?>';
            </script>
            <?php
            return;
        }
        
        // 生成 nonce
        $nonce = wp_create_nonce('lsky_pro_setup');
        
        // 显示错误和通知
        settings_errors('lsky_pro_setup');
        
        // 检查重定向
        if (get_transient('lsky_pro_setup_redirect')) {
            delete_transient('lsky_pro_setup_redirect');
            wp_redirect(admin_url('admin.php?page=lsky-pro-config&setup=complete'));
            exit;
        }
        ?>

        <script>
            // 兼容旧逻辑：部分脚本可能读取这些变量
            window.lskyProNonce = '<?php echo esc_js($nonce); ?>';
            var lskyProNonce = '<?php echo esc_js($nonce); ?>';
            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        </script>

        <div class="wrap lsky-pro-setup-wrap">
            <div class="lsky-pro-card">
                <h2 class="lsky-pro-title">LskyPro 图床配置</h2>

                <form id="lsky-pro-setup-form" class="mt-3" autocomplete="off">
                    <input type="hidden" id="lsky-pro-nonce" value="<?php echo esc_attr($nonce); ?>">

                    <div class="lsky-pro-form-group">
                        <label for="lsky-api-url">API 地址</label>
                        <input
                            type="url"
                            class="lsky-pro-input"
                            id="lsky-api-url"
                            name="api_url"
                            placeholder="例如: https://img.example.com/api/v2"
                            autocomplete="url"
                            required
                        >
                        <p class="lsky-pro-description">必须填写到 /api/v2 结尾</p>
                    </div>

                    <div id="lsky-paid-fields" class="lsky-pro-fields-container">
                        <div class="lsky-pro-form-group">
                            <label for="lsky-token">Token</label>
                            <input
                                type="text"
                                class="lsky-pro-input"
                                id="lsky-token"
                                name="token"
                                placeholder="请输入您的授权Token"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="lsky-pro-submit">
                        <button type="submit" class="btn btn-primary btn-lg w-100" id="lsky-setup-submit">
                            <span class="btn-text">保存配置</span>
                            <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                        </button>
                    </div>
                </form>

                <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
                    <div id="lsky-setup-toast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="toast-header">
                            <strong class="me-auto" id="lsky-setup-toast-title">提示</strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                        <div class="toast-body" id="lsky-setup-toast-body"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function is_installed() {
        return file_exists($this->install_lock);
    }

    public function handle_form_submission() {
        error_log('Received POST data: ' . print_r($_POST, true));
        
        // 验证 nonce 是否有效
        if (!wp_verify_nonce($_POST['setup_nonce'], 'lsky_pro_setup')) {
            wp_send_json_error('安全验证失败');

            return;
        }
        
        // 继续处理表单...
    }

    // 添加 AJAX 处理方法
    public function handle_ajax_setup() {
        // 验证 nonce
        if (!check_ajax_referer('lsky_pro_setup', 'setup_nonce', false)) {
            wp_send_json_error('安全验证失败');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }
        
        $api_url = rtrim(sanitize_url($_POST['api_url']), '/');
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        if (empty($api_url)) {
            wp_send_json_error('请输入 API 地址');
            return;
        }

        if (!preg_match('~/api/v2$~', $api_url)) {
            wp_send_json_error('API 地址必须以 /api/v2 结尾，例如：https://img.example.com/api/v2');
            return;
        }
        
        $options = array(
            'lsky_pro_api_url' => $api_url,
        );

        if (empty($token)) {
            wp_send_json_error('请输入 Token');
            return;
        }
        $options['lsky_pro_token'] = $token;
        
        // 保存配置
        update_option('lsky_pro_options', $options);
        
        // 设置临时标记
        set_transient('lsky_pro_show_setup_2', true, 30);
        
        // 返回重定向信息
        wp_send_json_success(array(
            'message' => '基础配置保存成功！即将进入计划任务配置...',
            'redirect' => admin_url('admin.php?page=lsky-pro-setup-2')
        ));
    }
}

new LskyProSetup(); 