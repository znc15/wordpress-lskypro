<?php
if (!defined('ABSPATH')) {
    exit;
}

class LskyProSetup2 {
    private $plugin_dir;
    private $install_lock;

    public function __construct() {
        $this->plugin_dir = plugin_dir_path(dirname(__FILE__));
        $this->install_lock = $this->plugin_dir . 'install.lock';

        add_action('admin_menu', array($this, 'add_setup_2_page'));
        add_action('wp_ajax_lsky_pro_setup_2_complete', array($this, 'handle_setup_2_complete'));
        add_action('wp_ajax_lsky_pro_test_cron', array($this, 'handle_test_cron'));
        add_action('lsky_pro_test_cron_event', array($this, 'handle_test_cron_event'));
        add_action('lsky_pro_cron_task', array($this, 'handle_cron_run'));
        add_action('wp_ajax_lsky_pro_save_cron_password', array($this, 'handle_save_cron_password'));
    }

    public function add_setup_2_page() {
        add_submenu_page(
            null,
            'Lsky Pro 计划任务配置',
            '计划任务配置',
            'manage_options',
            'lsky-pro-setup-2',
            array($this, 'render_setup_2_page')
        );
    }

    public function render_setup_2_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('抱歉，您没有权限访问此页面。'));
        }

        if (file_exists($this->install_lock)) {
            ?>
            <script>
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=lsky-pro-config')); ?>';
            </script>
            <?php
            return;
        }

        $options = get_option('lsky_pro_options');
        if (!$options) {
            return;
        }

        $php_path = 'php';
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $cron_script = $plugin_dir . 'includes/cron-process-images.php';
        $cron_script = str_replace('\\', '/', $cron_script);
        $initial_command = $php_path . ' ' . $cron_script;

        $last_run = get_option('lsky_pro_cron_last_run');
        $last_run_formatted = $last_run ? human_time_diff($last_run, time()) . '前' : '从未执行';

        wp_enqueue_style('lsky-pro-setup-2', plugins_url('assets/css/setup-2.css', dirname(__FILE__)));
        wp_enqueue_script('lsky-pro-setup-2', plugins_url('assets/js/setup-2.js', dirname(__FILE__)), array('jquery'), null, true);

        $cron_status_nonce = wp_create_nonce('lsky_pro_cron_status');
        $cron_password_nonce = wp_create_nonce('lsky_pro_cron_password');

        wp_localize_script('lsky-pro-setup-2', 'lskyProSetup2Data', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'cronStatusNonce' => $cron_status_nonce,
            'cronPasswordNonce' => $cron_password_nonce,
            'cronCommand' => $initial_command,
            'nonce' => wp_create_nonce('lsky_pro_setup_2_complete'),
            'settingsUrl' => admin_url('admin.php?page=lsky-pro-config'),
            'cronTestNonce' => wp_create_nonce('lsky_pro_test_cron'),
            'cronLogsNonce' => wp_create_nonce('lsky_pro_cron_logs'),
            'lastRun' => $last_run_formatted,
            'lastRunTimestamp' => $last_run,
            'i18n' => array(
                'savingPassword' => '正在保存...',
                'savePassword' => '保存密码',
                'passwordSaved' => '密码保存成功',
                'passwordError' => '密码保存失败'
            )
        ));

        ?>
        <div class="wrap">
            <div class="lsky-setup-container">
                <div class="setup-card">
                    <div class="card-header">
                        <h2>
                            <span class="dashicons dashicons-admin-generic"></span>
                            配置 Lsky Pro 计划任务
                        </h2>
                    </div>

                    <div class="card-body">
                        <div class="alert alert-info">
                            为了确保图片定期同步，您需要在系统中设置以下计划任务
                        </div>

                        <div class="password-section">
                            <div class="password-section-header">
                                <span class="dashicons dashicons-lock"></span>
                                <h3>设置 Cron 密码</h3>
                            </div>
                            <div class="password-form">
                                <div class="password-input-group">
                                    <input type="password" id="lsky-cron-password" placeholder="请输入 Cron 访问密码" class="password-input">
                                    <button type="button" class="toggle-password" id="lsky-toggle-password">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                </div>
                                <div class="password-actions">
                                    <button type="button" class="button button-primary" id="lsky-save-cron-password">保存密码</button>
                                    <span class="password-status" id="lsky-password-saved" style="display:none;">
                                        <span class="dashicons dashicons-yes"></span>
                                        密码保存成功
                                    </span>
                                </div>
                                <div class="password-error" id="lsky-password-error" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="command-section">
                            <h3>
                                <span class="dashicons dashicons-terminal"></span>
                                计划任务命令
                            </h3>

                            <div class="command-box">
                                <code class="command-text" id="lsky-cron-command"><?php echo esc_html($initial_command); ?></code>
                                <button type="button" class="copy-button" id="lsky-copy-command">
                                    <span class="dashicons dashicons-admin-page"></span>
                                    复制
                                </button>
                            </div>

                            <div class="setup-steps">
                                <h4>设置步骤：</h4>
                                <ol>
                                    <li>复制上面的命令</li>
                                    <li>打开您的服务器控制面板</li>
                                    <li>找到计划任务/定时任务设置</li>
                                    <li>添加新任务并粘贴命令</li>
                                    <li>设置执行间隔（推荐 5-10 分钟）</li>
                                </ol>
                            </div>
                        </div>

                        <div class="status-section">
                            <h3>
                                <span class="dashicons dashicons-chart-line"></span>
                                运行状态
                            </h3>

                            <div class="status-card">
                                <div class="status-info">
                                    <p>
                                        <strong>上次运行：</strong>
                                        <span id="lsky-last-run"><?php echo esc_html($last_run_formatted); ?></span>
                                    </p>
                                </div>

                                <button type="button" class="button" id="lsky-test-cron">检测运行状态</button>
                            </div>

                            <div id="lsky-cron-status" class="status-result" style="display:none;"></div>
                        </div>

                        <div class="logs-section">
                            <h3>
                                <span class="dashicons dashicons-list-view"></span>
                                运行日志
                            </h3>

                            <button type="button" class="button" id="lsky-refresh-logs">刷新日志</button>

                            <div class="logs-container" id="lsky-logs" style="display:none;"></div>
                            <p class="no-logs" id="lsky-no-logs">暂无日志</p>
                        </div>

                        <div class="alert alert-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <div class="alert-content">
                                <strong>注意：</strong>如果不设置计划任务，可能会影响图片同步功能的正常运行
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="button button-primary" id="completeSetup">我已完成设置</button>
                        </div>

                        <div class="notice notice-success" id="lsky-setup2-notice" style="display:none;"></div>
                        <div class="notice notice-error" id="lsky-setup2-error" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_setup_2_complete() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
            return;
        }

        if (!check_ajax_referer('lsky_pro_setup_2_complete', 'nonce', false)) {
            wp_send_json_error(array('message' => '安全验证失败'));
            return;
        }

        $plugin_dir = dirname(dirname(__FILE__));
        if (!is_writable($plugin_dir)) {
            wp_send_json_error(array('message' => '插件目录不可写，请检查权限'));
            return;
        }

        $result = @file_put_contents($this->install_lock, date('Y-m-d H:i:s'));
        if ($result === false) {
            $error = error_get_last();
            wp_send_json_error(array('message' => '无法创建安装锁定文件: ' . ($error ? $error['message'] : '未知错误')));
            return;
        }

        wp_send_json_success(array(
            'message' => '设置完成',
            'redirect' => admin_url('admin.php?page=lsky-pro-config')
        ));
    }

    public function handle_test_cron() {
        if (!check_ajax_referer('lsky_pro_test_cron', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => '无效的安全验证',
                'lastRun' => '未知'
            ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => '权限不足',
                'lastRun' => '未知'
            ));
            return;
        }

        $last_run = get_option('lsky_pro_cron_last_run');
        $current_time = time();

        if ($last_run && ($current_time - $last_run) < 7200) {
            wp_send_json_success(array(
                'status' => 'success',
                'message' => 'Cron 运行正常',
                'lastRun' => human_time_diff($last_run, $current_time) . '前',
                'lastRunTimestamp' => $last_run
            ));
            return;
        }

        $log_file = WP_CONTENT_DIR . '/lsky-pro-cron.log';
        if (file_exists($log_file)) {
            $log_time = filemtime($log_file);
            if (($current_time - $log_time) < 7200) {
                wp_send_json_success(array(
                    'status' => 'success',
                    'message' => 'Cron 运行正常',
                    'lastRun' => human_time_diff($log_time, $current_time) . '前',
                    'lastRunTimestamp' => $log_time
                ));
                return;
            }
        }

        wp_send_json_error(array(
            'status' => 'error',
            'message' => 'Cron 似乎没有正常运行',
            'details' => '未检测到最近2小时内的运行记录',
            'lastRun' => $last_run ? human_time_diff($last_run, $current_time) . '前' : '从未执行',
            'lastRunTimestamp' => $last_run ?: 0
        ));
    }

    public function handle_cron_run() {
        update_option('lsky_pro_cron_last_run', time());

        $log_file = WP_CONTENT_DIR . '/lsky-pro-cron.log';
        $log_content = date('Y-m-d H:i:s') . " - Cron task executed\n";
        file_put_contents($log_file, $log_content, FILE_APPEND);
    }

    public function handle_test_cron_event($option_name) {
        update_option($option_name, 'completed');
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

        $hashed_password = wp_hash_password($password);
        if (update_option('lsky_pro_cron_password', $hashed_password)) {
            wp_send_json_success(array('message' => '密码设置成功'));
        } else {
            wp_send_json_error(array('message' => '密码保存失败'));
        }
    }
}

new LskyProSetup2();