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
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('抱歉，您没有权限访问此页面。'));
        }
        
        // 检查是否已经完全安装
        if (file_exists($this->install_lock)) {
            ?>
            <script>
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=lsky-pro-settings')); ?>';
            </script>
            <?php
            return;
        }
        
        // 检查基础设置是否已完成
        $options = get_option('lsky_pro_options');
        if (!$options) {
            // 如果没有任何设置，不要跳转，让 setup.php 处理跳转
            return;
        }
        
        // 获取PHP路径和cron脚本路径
        $php_path = 'php';
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $cron_script = $plugin_dir . 'includes/cron-process-images.php';

        // 确保路径格式正确（特别是在Windows系统上）
        $cron_script = str_replace('\\', '/', $cron_script);

        // 生成初始命令
        $initial_command = $php_path . ' ' . $cron_script;
        
        // 获取最后运行时间
        $last_run = get_option('lsky_pro_cron_last_run');
        $last_run_formatted = $last_run ? human_time_diff($last_run, time()) . '前' : '从未执行';
        
        // 引入样式和脚本
        wp_enqueue_style('lsky-pro-setup-2', plugins_url('assets/css/setup-2.css', dirname(__FILE__)));
        wp_enqueue_script('lsky-pro-setup-2', plugins_url('assets/js/setup-2.js', dirname(__FILE__)), array('jquery'), null, true);
        
        // 创建 nonce
        $cron_status_nonce = wp_create_nonce('lsky_pro_cron_status');
        $cron_password_nonce = wp_create_nonce('lsky_pro_cron_password');
        
        // 传递到 JavaScript
        wp_localize_script('lsky-pro-setup-2', 'lskyProSetup2Data', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'cronStatusNonce' => $cron_status_nonce,
            'cronPasswordNonce' => $cron_password_nonce,
            'cronCommand' => $initial_command,
            'nonce' => wp_create_nonce('lsky_pro_setup_2_complete'),
            'settingsUrl' => admin_url('admin.php?page=lsky-pro-settings'),
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
        
        // 将路径传递给JavaScript
        ?>
        <div id="app">
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

                        <!-- 密码设置区域 -->
                        <div class="password-section">
                            <div class="password-section-header">
                                <span class="dashicons dashicons-lock"></span>
                                <h3>设置 Cron 密码</h3>
                            </div>
                            
                            <p class="password-description">此密码用于验证 Cron 任务的执行权限，确保只有授权的请求才能执行计划任务。</p>
                            
                            <div class="password-input-area">
                                <div class="password-input-wrapper">
                                    <input 
                                        type="password" 
                                        id="cronPassword" 
                                        v-model="cronPassword" 
                                        placeholder="请输入 Cron 密码" 
                                        :class="{'has-error': passwordError}"
                                    >
                                    
                                    <div class="password-strength" v-if="cronPassword">
                                        <div class="password-strength-bar" :class="passwordStrengthClass"></div>
                                    </div>
                                    <div class="password-strength-text" v-if="cronPassword" :class="passwordStrengthClass">
                                        {{ passwordStrengthText }}
                                    </div>
                                </div>
                                
                                <button 
                                    @click="saveCronPassword" 
                                    class="save-password-btn" 
                                    :disabled="!cronPassword || savingPassword"
                                >
                                    <span class="dashicons dashicons-saved" v-if="passwordSaved"></span>
                                    <span class="dashicons dashicons-update spin" v-else-if="savingPassword"></span>
                                    <span v-else>保存密码</span>
                                </button>
                            </div>
                            
                            <div class="password-feedback success" v-if="passwordSaved">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <span>密码设置成功</span>
                            </div>
                            
                            <div class="password-feedback error" v-if="passwordError">
                                <span class="dashicons dashicons-warning"></span>
                                <span>{{ passwordError }}</span>
                            </div>
                        </div>

                        <!-- 图片处理任务区域 -->
                        <div class="image-task-section">
                            <div class="image-task-header">
                                <span class="dashicons dashicons-format-image"></span>
                                <h3>图片处理任务</h3>
                            </div>
                            
                            <p class="image-task-description">为了确保图片处理任务能够正常运行，您需要在系统中设置以下计划任务：</p>
                            
                            <div class="command-block">
                                <div class="command-input-wrapper">
                                    <div class="command-input" id="cronCommand">
                                        <div class="command-text">{{ cronCommand }}</div>
                                    </div>
                                    <div class="copy-feedback" :class="{'show': showCopyFeedback}">已复制到剪贴板</div>
                                </div>
                                
                                <button @click="copyCommand" class="copy-btn">
                                    复制命令
                                </button>
                            </div>
                            
                            <button @click="checkStatus" class="check-status-btn" :disabled="checking">
                                <span class="dashicons dashicons-update" :class="{'spin': checking}"></span>
                                {{ checking ? '检测中...' : '检测运行状态' }}
                            </button>
                        </div>

                        <!-- 命令行终端显示区域 -->
                        <div v-if="statusResult" class="terminal-card">
                            <div class="terminal-header">
                                <div class="terminal-buttons">
                                    <span class="terminal-button red"></span>
                                    <span class="terminal-button yellow"></span>
                                    <span class="terminal-button green"></span>
                                </div>
                                <div class="terminal-title">系统状态检测</div>
                            </div>
                            <div class="terminal-body">
                                <div class="terminal-line">
                                    <span class="terminal-prompt">$</span>
                                    <span class="terminal-command">check_cron_status</span>
                                </div>
                                <div class="terminal-line">
                                    <span v-if="statusResult.status === 'error'" class="terminal-error">{{ statusResult.message }}</span>
                                    <span v-else class="terminal-success">{{ statusResult.message }}</span>
                                </div>
                                <div class="terminal-line" v-if="statusResult.details">
                                    <span class="terminal-details">{{ statusResult.details }}</span>
                                </div>
                                <div class="terminal-line" v-if="statusResult.lastRun">
                                    <span class="terminal-prompt">$</span>
                                    <span class="terminal-command">last_execution</span>
                                </div>
                                <div class="terminal-line" v-if="statusResult.lastRun">
                                    <span class="terminal-output">{{ statusResult.lastRun }}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="section-divider"></div>
                        
                        <h3>
                            <span class="dashicons dashicons-info-outline"></span>
                            说明
                        </h3>
                        
                        <div class="info-card">
                            <ul>
                                <li>此计划任务每天执行一次（可以根据不同情况设置）</li>
                                <li>请将此命令添加到服务器的 crontab 中，宝塔面板在计划任务中添加</li>
                                <li>请在复制命令前输入密码，保存后再复制</li>
                                <li>如果您使用的是共享主机，请联系您的主机提供商设置计划任务</li>
                                <li>如果计划任务执行失败，请检查密码是否正确</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <div class="alert-content">
                                <strong>注意：</strong>如果不设置计划任务，可能会影响图片同步功能的正常运行
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button class="button button-primary" 
                                    id="completeSetup" 
                                    @click="completeSetup">
                                我已完成设置
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 修改 Toast 组件 -->
            <Transition name="slide-fade">
                <div v-if="toast.show" 
                     class="lsky-toast"
                     :class="toast.type">
                    <svg v-if="toast.type === 'error'"
                         viewBox="0 0 1024 1024"
                         class="toast-icon">
                        <path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896zm0 393.664L407.936 353.6a38.4 38.4 0 1 0-54.336 54.336L457.664 512 353.6 616.064a38.4 38.4 0 1 0 54.336 54.336L512 566.336 616.064 670.4a38.4 38.4 0 1 0 54.336-54.336L566.336 512 670.4 407.936a38.4 38.4 0 1 0-54.336-54.336L512 457.664z"/>
                    </svg>
                    <svg v-else
                         viewBox="0 0 1024 1024"
                         class="toast-icon">
                        <path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896zm-55.808 536.384-99.52-99.584a38.4 38.4 0 1 0-54.336 54.336l126.72 126.72a38.272 38.272 0 0 0 54.336 0l262.4-262.464a38.4 38.4 0 1 0-54.272-54.336L456.192 600.384z"/>
                    </svg>
                    <div class="toast-content">
                        <span class="toast-title">{{ toast.title }}</span>
                        <span class="toast-message">{{ toast.message }}</span>
                    </div>
                </div>
            </Transition>
        </div>
        <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
        <script>
        var lskyProSetup2Data = {
            cronStatusNonce: '<?php echo esc_js($cron_status_nonce); ?>',
            cronLogsNonce: '<?php echo esc_js(wp_create_nonce('lsky_pro_cron_logs')); ?>'
        };
        var pluginCronPath = '<?php echo esc_js($cron_script); ?>';
        var phpPath = '<?php echo esc_js($php_path); ?>';
        var initialCommand = '<?php echo esc_js($initial_command); ?>';
        </script>
        <?php
    }
    
    public function handle_setup_2_complete() {
        // 添加调试日志
        error_log('Setup 2 complete handler started');
        
        // 验证权限
        if (!current_user_can('manage_options')) {
            error_log('Setup 2 complete: 权限验证失败');
            wp_send_json_error(array(
                'message' => '权限不足'
            ));
            return;
        }
        
        // 验证 nonce
        if (!check_ajax_referer('lsky_pro_setup_2_complete', 'nonce', false)) {
            error_log('Setup 2 complete: nonce 验证失败');
            wp_send_json_error(array(
                'message' => '安全验证失败'
            ));
            return;
        }
        
        // 确保目录可写
        $plugin_dir = dirname(dirname(__FILE__));
        error_log('Plugin directory: ' . $plugin_dir);
        error_log('Install lock path: ' . $this->install_lock);
        
        if (!is_writable($plugin_dir)) {
            error_log('Setup 2 complete: 目录不可写 - ' . $plugin_dir);
            wp_send_json_error(array(
                'message' => '插件目录不可写，请检查权限',
                'debug' => array(
                    'dir' => $plugin_dir,
                    'writable' => is_writable($plugin_dir)
                )
            ));
            return;
        }
        
        // 创建安装锁定文件
        $result = @file_put_contents($this->install_lock, date('Y-m-d H:i:s'));
        
        if ($result === false) {
            $error = error_get_last();
            error_log('Setup 2 complete: 创建锁定文件失败 - ' . json_encode($error));
            wp_send_json_error(array(
                'message' => '无法创建安装锁定文件: ' . ($error ? $error['message'] : '未知错误'),
                'debug' => array(
                    'path' => $this->install_lock,
                    'writable' => is_writable(dirname($this->install_lock)),
                    'exists' => file_exists($this->install_lock),
                    'php_error' => $error
                )
            ));
            return;
        }
        
        error_log('Setup 2 complete: 成功创建锁定文件');
        
        // 写入成功后，确保返回正确的重定向 URL
        $redirect_url = admin_url('admin.php?page=lsky-pro-settings');
        
        wp_send_json_success(array(
            'message' => '设置完成',
            'redirect' => $redirect_url,
            'debug' => array(
                'current_url' => admin_url(),
                'redirect_to' => $redirect_url
            )
        ));
    }

    // 添加 Cron 测试处理方法
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
        
        // 检查最近2小时内是否有运行记录
        if ($last_run && ($current_time - $last_run) < 7200) {
            wp_send_json_success(array(
                'status' => 'success',
                'message' => 'Cron 运行正常',
                'lastRun' => human_time_diff($last_run, $current_time) . '前',
                'lastRunTimestamp' => $last_run
            ));
            return;
        }
        
        // 检查日志文件
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
        
        // 如果没有最近的运行记录
        wp_send_json_error(array(
            'status' => 'error',
            'message' => 'Cron 似乎没有正常运行',
            'details' => '未检测到最近2小时内的运行记录',
            'lastRun' => $last_run ? human_time_diff($last_run, $current_time) . '前' : '从未执行',
            'lastRunTimestamp' => $last_run ?: 0
        ));
    }

    // 在 Cron 执行时记录时间
    public function handle_cron_run() {
        update_option('lsky_pro_cron_last_run', time());
        
        // 同时写入日志
        $log_file = WP_CONTENT_DIR . '/lsky-pro-cron.log';
        $log_content = date('Y-m-d H:i:s') . " - Cron task executed\n";
        file_put_contents($log_file, $log_content, FILE_APPEND);
    }

    // 添加 Cron 测试事件处理
    public function handle_test_cron_event($option_name) {
        update_option($option_name, 'completed');
    }

    // 添加处理 Cron 密码保存的函数
    public function handle_save_cron_password() {
        // 验证 nonce
        if (!check_ajax_referer('lsky_pro_cron_password', 'nonce', false)) {
            wp_send_json_error(array('message' => '安全验证失败'));
            return;
        }
        
        // 验证权限
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

new LskyProSetup2(); 