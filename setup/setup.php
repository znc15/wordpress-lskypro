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
        
        $api_url = sanitize_url($_POST['api_url']);
        $account_type = sanitize_text_field($_POST['account_type']);
        
        if (empty($api_url)) {
            add_settings_error(
                'lsky_pro_setup',
                'api_url_error',
                '请输入 API 地址',
                'error'
            );
            return;
        }
        
        $options = array(
            'lsky_pro_api_url' => rtrim($api_url, '/'),
        );
        
        if ($account_type === 'paid') {
            $token = sanitize_text_field($_POST['token']);
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
        } else if ($account_type === 'free') {
            $email = sanitize_email($_POST['email']);
            $password = $_POST['password'];
            
            if (empty($email) || empty($password)) {
                add_settings_error(
                    'lsky_pro_setup',
                    'credentials_error',
                    '请输入邮箱和密码',
                    'error'
                );
                return;
            }

            // 记录请求信息（不包含密码）
            if (WP_DEBUG) {
                error_log('LskyPro Token Request - API URL: ' . $api_url);
                error_log('LskyPro Token Request - Email: ' . $email);
            }

            // 修改 API 路径，确保使用正确的 API 端点
            $token_url = rtrim($api_url, '/') . '/tokens';
            
            $request_body = json_encode(array(
                'email' => $email,
                'password' => $password
            ));

            $response = wp_remote_post($token_url, array(
                'headers' => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ),
                'body' => $request_body,
                'method' => 'POST',
                'timeout' => 30
            ));
            
            if (WP_DEBUG) {
                error_log('LskyPro Token Request URL: ' . $token_url);
            }
            
            if (is_wp_error($response)) {
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '获取 Token 失败：' . $response->get_error_message(),
                    'error'
                );
                if (WP_DEBUG) {
                    error_log('LskyPro Token Error: ' . $response->get_error_message());
                }
                return;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if (WP_DEBUG) {
                error_log('LskyPro Response Status: ' . $status_code);
                error_log('LskyPro Response Body: ' . $body);
            }
            
            if ($status_code !== 200) {
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '获取 Token 失败：服务器返回状态码 ' . $status_code,
                    'error'
                );
                return;
            }
            
            $result = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '解析响应失败：' . json_last_error_msg(),
                    'error'
                );
                return;
            }
            
            if (!isset($result['status'])) {
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '获取 Token 失败：响应格式错误',
                    'error'
                );
                if (WP_DEBUG) {
                    error_log('LskyPro Invalid Response Format: ' . print_r($result, true));
                }
                return;
            }
            
            if ($result['status'] !== true) {
                $error_message = isset($result['message']) ? $result['message'] : '未知错误';
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '获取 Token 失败：' . $error_message,
                    'error'
                );
                if (WP_DEBUG) {
                    error_log('LskyPro Token Error Response: ' . print_r($result, true));
                }
                return;
            }
            
            if (!isset($result['data']['token'])) {
                add_settings_error(
                    'lsky_pro_setup',
                    'token_error',
                    '获取 Token 失败：响应中没有 token',
                    'error'
                );
                return;
            }
            
            $options['lsky_pro_token'] = $result['data']['token'];
        }
        
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
        if (!is_array($verify_result) || (isset($verify_result['status']) && $verify_result['status'] !== true && $verify_result['status'] !== 'success')) {
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
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=lsky-pro-settings')); ?>';
            </script>
            <?php
            return;
        }
        
        // 生成 nonce
        $nonce = wp_create_nonce('lsky_pro_setup');
        
        ?>
        <script>
            // 传递给 JavaScript
            window.lskyProNonce = '<?php echo $nonce; ?>';
        </script>
        <?php
        
        // 加载Vue 3和Element Plus的CDN资源
        wp_enqueue_script('vue', 'https://cdn.jsdelivr.net/npm/vue@3.3.4/dist/vue.global.prod.js', array(), null, true);
        wp_enqueue_script('element-plus', 'https://cdn.jsdelivr.net/npm/element-plus', array('vue'), null, true);
        wp_enqueue_style('element-plus-css', 'https://cdn.jsdelivr.net/npm/element-plus/dist/index.css');
        wp_enqueue_script('element-plus-icons', 'https://cdn.jsdelivr.net/npm/@element-plus/icons-vue', array('vue', 'element-plus'), null, true);
        
        // 修改自定义脚本和样式的加载路径
        wp_enqueue_script('lsky-pro-script', plugin_dir_url(dirname(__FILE__)) . 'assets/js/script.js', array('jquery', 'vue', 'element-plus', 'element-plus-icons'), null, true);
        wp_enqueue_style('lsky-pro-style', plugin_dir_url(dirname(__FILE__)) . 'assets/css/style.css');
        
        // 加载 Animate.css
        wp_enqueue_style('animate-css', 'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css');
        
        // 显示错误和通知
        settings_errors('lsky_pro_setup');
        
        // 检查重定向
        if (get_transient('lsky_pro_setup_redirect')) {
            delete_transient('lsky_pro_setup_redirect');
            wp_redirect(admin_url('admin.php?page=lsky-pro-settings&setup=complete'));
            exit;
        }
        ?>
        
        <script>
            var lskyProNonce = '<?php echo esc_js($nonce); ?>';
            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        </script>
        <div id="lsky-pro-setup">
            <input type="hidden" id="lsky-pro-nonce" value="<?php echo esc_attr($nonce); ?>">
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
        
        $api_url = sanitize_url($_POST['api_url']);
        $account_type = sanitize_text_field($_POST['account_type']);
        
        if (empty($api_url)) {
            wp_send_json_error('请输入 API 地址');
            return;
        }
        
        $options = array(
            'lsky_pro_api_url' => rtrim($api_url, '/'),
        );
        
        if ($account_type === 'paid') {
            $token = sanitize_text_field($_POST['token']);
            if (empty($token)) {
                wp_send_json_error('请输入 Token');
                return;
            }
            $options['lsky_pro_token'] = $token;
        } else if ($account_type === 'free') {
            $email = sanitize_email($_POST['email']);
            $password = $_POST['password'];
            
            if (empty($email) || empty($password)) {
                wp_send_json_error('请输入邮箱和密码');
                return;
            }

            // 获取 Token
            $token_url = rtrim($api_url, '/') . '/tokens';
            $response = wp_remote_post($token_url, array(
                'headers' => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'email' => $email,
                    'password' => $password
                )),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                wp_send_json_error('获取 Token 失败：' . $response->get_error_message());
                return;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                wp_send_json_error('获取 Token 失败：服务器返回状态码 ' . $status_code);
                return;
            }
            
            $result = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($result['status']) || $result['status'] !== true) {
                wp_send_json_error('获取 Token 失败：' . ($result['message'] ?? '未知错误'));
                return;
            }
            
            if (!isset($result['data']['token'])) {
                wp_send_json_error('获取 Token 失败：响应中没有 token');
                return;
            }
            
            $options['lsky_pro_token'] = $result['data']['token'];
        }
        
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