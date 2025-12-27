<?php
/*
Plugin Name: LskyPro For WordPress
Plugin URI: https://github.com/znc15/wordpress-lskypro
Description: 自动将WordPress上传的图片同步到LskyPro图床
Version: 1.0.0
Author: LittleSheep
Author URI: https://www.littlesheep.cc
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('LSKY_PRO_PLUGIN_FILE', __FILE__);
define('LSKY_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));

// 加载所有必需的类文件
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-uploader.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-remote.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-upload-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-post-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-batch.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-api.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/cron-process-images.php';
require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup.php';
require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup-2.php';

// 初始化类
new LskyProUploadHandler();
new LskyProPostHandler();
new LskyProBatch();

// 修改全局变量初始化方式
function lsky_pro_init_setup() {
    global $lsky_pro_setup;
    
    // 确保类文件已加载
    if (!class_exists('LskyProSetup')) {
        require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup.php';
    }
    
    // 初始化类
    $lsky_pro_setup = new LskyProSetup();
}
add_action('plugins_loaded', 'lsky_pro_init_setup');

// 修改菜单添加函数
function lsky_pro_add_admin_menu() {
    add_menu_page(
        'LskyPro设置', // 页面标题
        'LskyPro图床', // 菜单标题
        'manage_options', // 权限
        'lsky-pro-settings', // 菜单slug
        'lsky_pro_options_page', // 回调函数
        'dashicons-images-alt2', // 图标
        80 // 位置
    );
}
// 修改钩子，从 admin_menu 改为 admin_menu
add_action('admin_menu', 'lsky_pro_add_admin_menu');

// 添加检查更新的 AJAX 处理函数
function lsky_pro_check_update() {
    check_ajax_referer('lsky_pro_ajax', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }
    
    // 获取当前插件版本
    $current_version = '1.0.0'; // 确保这里匹配你的插件版本
    
    // 获取 GitHub releases
    $response = wp_remote_get(
        'https://api.github.com/repos/znc15/wordpress-lskypro/releases/latest',
        array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ),
            'timeout' => 30,
            'sslverify' => false
        )
    );
    
    if (is_wp_error($response)) {
        wp_send_json_error('检查更新失败：' . $response->get_error_message());
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (empty($body) || !isset($body['tag_name'])) {
        wp_send_json_error('无法获取最新版本信息');
    }
    
    // 移除版本号前的 'v' 字符（如果存在）
    $latest_version = ltrim($body['tag_name'], 'v');
    
    $result = array(
        'current_version' => $current_version,
        'latest_version' => $latest_version,
        'has_update' => version_compare($latest_version, $current_version, '>'),
        'download_url' => $body['zipball_url'] ?? '',
        'release_notes' => $body['body'] ?? ''
    );
    
    wp_send_json_success($result);
}
add_action('wp_ajax_lsky_pro_check_update', 'lsky_pro_check_update');

// 添加新的 admin_enqueue_scripts 钩子
function lsky_pro_admin_scripts($hook) {
    if ('toplevel_page_lsky-pro-settings' !== $hook) {
        return;
    }
    
    // 注册并加载 Bootstrap
    wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
    wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
    
    // 注册并加载自定义样式和脚本
    wp_enqueue_style('lsky-pro-admin', plugins_url('assets/css/admin-style.css', __FILE__));
    wp_enqueue_script('lsky-pro-admin', plugins_url('assets/js/admin-script.js', __FILE__), array('jquery', 'bootstrap'), null, true);
    
    // 本地化脚本
    wp_localize_script('lsky-pro-admin', 'lskyProData', array(
        'nonce' => wp_create_nonce('lsky_pro_ajax'),
        'batchNonce' => wp_create_nonce('lsky_pro_batch'),
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}
add_action('admin_enqueue_scripts', 'lsky_pro_admin_scripts');
// 修改设置页面函数为新版本
function lsky_pro_options_page() {
    $options = get_option('lsky_pro_options');
    ?>
    <div class="wrap lsky-dashboard">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="row mt-4">
            <div class="col-12 col-md-6">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>账号信息</h2>
                    </div>
                    <div class="lsky-card-body">
                        <div id="user-info">
                            <div class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-12 col-md-6">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>批量处理</h2>
                    </div>
                    <div class="lsky-card-body">
                        <!-- 保持原有的批量处理HTML结构不变 -->
                        <?php include 'templates/batch-process.php'; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>设置</h2>
                    </div>
                    <div class="lsky-card-body">
                        <form action='options.php' method='post' class="needs-validation" novalidate>
                            <?php
                            settings_fields('lsky_pro_options');
                            do_settings_sections('lsky-pro-settings');
                            submit_button('保存设置', 'primary btn-lg mt-4');
                            ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// 修改设置验证函数
function lsky_pro_validate_settings($input) {
    $api_url = $input['lsky_pro_api_url'];
    $token = $input['lsky_pro_token'];
    
    if (!empty($api_url) && !empty($token)) {
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => rtrim($api_url, '/') . '/profile',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token
            ),
        ));
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);
        
        if ($http_code !== 200) {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_token_error',
                'Token验证失败，请检查API地址和Token是否正确',
                'error'
            );
            return get_option('lsky_pro_options');
        }
        
        $result = json_decode($response, true);
        if (!isset($result['status']) || $result['status'] !== true) {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_token_error',
                'API响应异常，请检查设置',
                'error'
            );
            return get_option('lsky_pro_options');
        }
        
        // 验证成功，显示成功消息
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_success',
            '设置已保存，Token验证成功！',
            'success'
        );
    }
    
    // 处理 Cron 密码
    if (!empty($input['cron_password'])) {
        // 如果密码有变化，则更新哈希值
        $current_hash = get_option('lsky_pro_cron_password');
        $new_hash = wp_hash_password($input['cron_password']);
        
        if ($current_hash !== $new_hash) {
            update_option('lsky_pro_cron_password', $new_hash);
        }
        
        // 从选项中移除明文密码
        unset($input['cron_password']);
    }

    return $input;
}

// 修改注册设置部分
function lsky_pro_settings_init() {
    // 注册设置
    register_setting('lsky_pro_options', 'lsky_pro_options', array(
        'type' => 'array',
        'default' => array(
            'lsky_pro_api_url' => '',
            'lsky_pro_token' => '',
            'strategy_id' => '1',
            'process_remote_images' => 0
        )
    ));

    // 添加设置区块
    add_settings_section(
        'lsky_pro_settings_section',
        '基本设置',
        'lsky_pro_settings_section_callback',
        'lsky-pro-settings'
    );

    // 添加设置字段
    $fields = array(
        array(
            'id' => 'lsky_pro_api_url',
            'title' => 'API地址',
            'callback' => 'lsky_pro_api_url_render'
        ),
        array(
            'id' => 'lsky_pro_token',
            'title' => 'Token',
            'callback' => 'lsky_pro_token_render'
        ),
        array(
            'id' => 'strategy_id',
            'title' => '存储策略ID',
            'callback' => 'lsky_pro_strategy_id_callback'
        ),
        array(
            'id' => 'process_remote_images',
            'title' => '远程图片处理',
            'callback' => 'lsky_pro_process_remote_images_callback'
        )
    );

    // 注册所有字段
    foreach ($fields as $field) {
        add_settings_field(
            $field['id'],
            $field['title'],
            $field['callback'],
            'lsky-pro-settings',
            'lsky_pro_settings_section'
        );
    }
}
add_action('admin_init', 'lsky_pro_settings_init');

// 更新设置字段渲染函数
function lsky_pro_api_url_render() {
    $options = get_option('lsky_pro_options');
    ?>
    <input type="text" name="lsky_pro_options[lsky_pro_api_url]" 
           value="<?php echo esc_attr($options['lsky_pro_api_url'] ?? ''); ?>" 
           class="regular-text" required>
    <p class="description">例如：https://your-domain.com/api/v1</p>
    <?php
}

function lsky_pro_token_render() {
    $options = get_option('lsky_pro_options');
    ?>
    <input type="text" name="lsky_pro_options[lsky_pro_token]" 
           value="<?php echo esc_attr($options['lsky_pro_token'] ?? ''); ?>" 
           class="regular-text" required>
    <p class="description">访问令牌，用于图片上传验证</p>
    <?php
}

function lsky_pro_strategy_id_callback() {
    $options = get_option('lsky_pro_options');
    $strategy_id = isset($options['strategy_id']) ? $options['strategy_id'] : '1';
    
    // 获取存储策略列表
    $uploader = new LskyProUploader();
    $response = wp_remote_get(
        rtrim($uploader->getApiUrl(), '/') . '/strategies',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $uploader->getToken()
            ),
            'timeout' => 30,
            'sslverify' => false
        )
    );
    
    if (is_wp_error($response)) {
        echo '<input type="number" name="lsky_pro_options[strategy_id]" value="' . esc_attr($strategy_id) . '" min="1">';
        echo '<p class="description" style="color: #dc3232;">获取存储策略失败</p>';
        return;
    }
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    $strategies = $result['data']['strategies'] ?? [];
    
    // 显示下拉选择框
    ?>
    <select name='lsky_pro_options[strategy_id]' id='lsky_pro_strategy_id'>
        <?php foreach ($strategies as $strategy): ?>
            <option value='<?php echo esc_attr($strategy['id']); ?>' 
                    <?php selected($strategy_id, $strategy['id']); ?>>
                <?php echo esc_html($strategy['name']); ?> 
                (ID: <?php echo esc_html($strategy['id']); ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

function lsky_pro_settings_section_callback() {
    echo '请填写LskyPro图床的API设置';
}

function lsky_pro_process_remote_images_callback() {
    $options = get_option('lsky_pro_options');
    ?>
    <label>
        <input type='checkbox' name='lsky_pro_options[process_remote_images]' 
               value="1"
               <?php checked(isset($options['process_remote_images']) && $options['process_remote_images'] == 1); ?>>
        自动处理文章中的远程图片
    </label>
    <p class="description">保存文章时，自动将远程图片上传到图床</p>
    <?php
}

// 修改文件上传处理函数
// 上传处理相关 hooks 已由 `LskyProUploadHandler` 统一注册，避免重复处理。

// 添加媒体库列表显示图床URL
function lsky_pro_media_columns($columns) {
    $columns['lsky_url'] = '图床URL';
    $columns['lsky_status'] = '图床状态';
    return $columns;
}
add_filter('manage_media_columns', 'lsky_pro_media_columns');

function lsky_pro_media_custom_column($column_name, $post_id) {
    if ($column_name == 'lsky_url') {
        $lsky_url = get_post_meta($post_id, '_lsky_pro_url', true);
        if ($lsky_url) {
            echo esc_url($lsky_url);
        }
    }
    if ($column_name == 'lsky_status') {
        $attachment_url = wp_get_attachment_url($post_id);
        if (strpos($attachment_url, get_site_url()) === false) {
            echo '<span style="color:#46b450;">✓ 已上传到图床</span>';
        } else {
            echo '<span style="color:#dc3232;">✗ 本地存储</span>';
        }
    }
}
add_action('manage_media_custom_column', 'lsky_pro_media_custom_column', 10, 2);

// 禁用 WordPress 默认图片尺寸
function lsky_pro_disable_image_sizes($sizes) {
    // 需要禁用的尺寸
    $disabled_sizes = array('thumbnail', 'medium', 'medium_large', 'large');
    
    // 从尺寸数组中移除指定尺寸
    foreach ($disabled_sizes as $size) {
        unset($sizes[$size]);
    }
    
    return $sizes;
}
add_filter('intermediate_image_sizes_advanced', 'lsky_pro_disable_image_sizes');

// 禁用缩放尺寸的生成
function lsky_pro_disable_scaled_image_size($default) {
    return false;
}
add_filter('big_image_size_threshold', 'lsky_pro_disable_scaled_image_size');

// 在插件激活时执行的函数
function lsky_pro_plugin_activation() {
    // 更新图片尺寸设置为0，实际禁用这些尺寸
    update_option('thumbnail_size_w', 0);
    update_option('thumbnail_size_h', 0);
    update_option('medium_size_w', 0);
    update_option('medium_size_h', 0);
    update_option('medium_large_size_w', 0);
    update_option('medium_large_size_h', 0);
    update_option('large_size_w', 0);
    update_option('large_size_h', 0);
}
register_activation_hook(__FILE__, 'lsky_pro_plugin_activation');

// 在插件停用时恢复默认设置
function lsky_pro_plugin_deactivation() {
    // 恢复WordPress默认的图片尺寸设置
    update_option('thumbnail_size_w', 150);
    update_option('thumbnail_size_h', 150);
    update_option('medium_size_w', 300);
    update_option('medium_size_h', 300);
    update_option('medium_large_size_w', 768);
    update_option('medium_large_size_h', 0);
    update_option('large_size_w', 1024);
    update_option('large_size_h', 1024);
}
register_deactivation_hook(__FILE__, 'lsky_pro_plugin_deactivation');

// 添加设置链接到插件页面
function lsky_pro_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=lsky-pro-settings">' . __('设置') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'lsky_pro_add_settings_link');

// 添加管理员通知
function lsky_pro_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['post', 'edit-post'])) {
        return;
    }

    $options = get_option('lsky_pro_options');
    if (empty($options['process_remote_images'])) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>LskyPro图床：远程图片自动处理功能未启用。如需自动处理外链图片，请在设置中启用此功能。</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'lsky_pro_admin_notices');

// 添加上传状态显示
function lsky_pro_admin_footer() {
    ?>
    <div id="lsky-upload-status" style="display:none; position:fixed; bottom:20px; right:20px; padding:15px; background:#fff; box-shadow:0 0 10px rgba(0,0,0,0.1); border-radius:4px; z-index:9999;">
        <div class="upload-progress">
            <span class="status-text">正在上传...</span>
            <div class="progress-bar" style="width:200px; height:5px; background:#eee; margin-top:10px;">
                <div class="progress" style="width:0%; height:100%; background:#2271b1; transition:width 0.3s;"></div>
            </div>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        // 监听媒体上传
        $(document).on('wp-upload-media-started', function() {
            $('#lsky-upload-status').show();
        });
        
        $(document).on('wp-upload-media-success', function() {
            $('#lsky-upload-status .status-text').text('上传成功！');
            $('#lsky-upload-status .progress').css('width', '100%');
            setTimeout(function() {
                $('#lsky-upload-status').fadeOut();
            }, 2000);
        });
        
        $(document).on('wp-upload-media-error', function() {
            $('#lsky-upload-status .status-text').text('上传失败，请重试');
            $('#lsky-upload-status').addClass('error');
            setTimeout(function() {
                $('#lsky-upload-status').fadeOut();
            }, 3000);
        });
    });
    </script>
    <style>
    #lsky-upload-status.error {
        background: #dc3232;
        color: #fff;
    }
    #lsky-upload-status.error .progress-bar {
        background: rgba(255,255,255,0.2);
    }
    #lsky-upload-status.error .progress {
        background: #fff;
    }
    </style>
    <?php
}
add_action('admin_footer', 'lsky_pro_admin_footer');

// 添加安装检查函数
function lsky_pro_check_installation() {
    // 检查是否在插件设置页面
    $current_screen = get_current_screen();
    if ($current_screen && $current_screen->id === 'toplevel_page_lsky-pro-settings') {
        // 检查 install.lock 文件是否存在
        if (!file_exists(LSKY_PRO_PLUGIN_DIR . 'install.lock')) {
            // 添加管理通知
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong>LskyPro图床</strong> 尚未完成配置。
                        <a href="<?php echo admin_url('admin.php?page=lsky-pro-setup'); ?>" class="button button-primary">开始配置向导</a>
                    </p>
                </div>
                <?php
            });
            
            // 重定向到设置页面
            wp_redirect(admin_url('admin.php?page=lsky-pro-setup'));
            exit;
        }
    }
}
add_action('current_screen', 'lsky_pro_check_installation');

// 配置向导页面回调函数
function lsky_pro_setup_page() {
   
    global $lsky_pro_setup;
    
    if (isset($lsky_pro_setup) && method_exists($lsky_pro_setup, 'render_setup_page')) {
        $lsky_pro_setup->render_setup_page();
    } else {
        echo '<div class="notice notice-error"><p>无法找到 LskyProSetup 类或 render_setup_page 方法。</p></div>';
        
        // 调试信息
        echo '<div style="background: #f8f9fa; padding: 15px; margin-top: 20px; border-left: 4px solid #0073aa;">';
        echo '<h3>调试信息</h3>';
        echo '<p>PHP 版本: ' . phpversion() . '</p>';
        echo '<p>WordPress 版本: ' . get_bloginfo('version') . '</p>';
        echo '<p>插件目录: ' . LSKY_PRO_PLUGIN_DIR . '</p>';
        echo '<p>setup.php 文件路径: ' . LSKY_PRO_PLUGIN_DIR . 'setup/setup.php</p>';
        echo '<p>setup.php 文件是否存在: ' . (file_exists(LSKY_PRO_PLUGIN_DIR . 'setup/setup.php') ? '是' : '否') . '</p>';
        
        // 尝试手动加载类
        echo '<p>尝试手动加载 LskyProSetup 类:</p>';
        if (file_exists(LSKY_PRO_PLUGIN_DIR . 'setup/setup.php')) {
            require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup.php';
            if (class_exists('LskyProSetup')) {
                echo '<p style="color: green;">LskyProSetup 类已成功加载!</p>';
                $setup = new LskyProSetup();
                if (method_exists($setup, 'render_setup_page')) {
                    echo '<p style="color: green;">render_setup_page 方法存在!</p>';
                    echo '<p>尝试调用 render_setup_page 方法:</p>';
                    $setup->render_setup_page();
                } else {
                    echo '<p style="color: red;">render_setup_page 方法不存在!</p>';
                }
            } else {
                echo '<p style="color: red;">LskyProSetup 类加载失败!</p>';
                
                // 显示文件内容的前几行
                $file_content = file_get_contents(LSKY_PRO_PLUGIN_DIR . 'setup/setup.php');
                echo '<p>文件内容预览:</p>';
                echo '<pre style="background: #f0f0f0; padding: 10px; max-height: 300px; overflow: auto;">';
                echo htmlspecialchars(substr($file_content, 0, 500)) . '...';
                echo '</pre>';
            }
        } else {
            echo '<p style="color: red;">setup.php 文件不存在!</p>';
        }
        
        echo '</div>';
    }
    
    echo '</div>';
}

// 添加设置向导页面
function lsky_pro_add_setup_page() {
    add_submenu_page(
        null,                       // 不显示在任何菜单下
        'LskyPro 配置向导',         // 页面标题
        'LskyPro 配置向导',         // 菜单标题
        'manage_options',           // 所需权限
        'lsky-pro-setup',          // 页面 slug
        'lsky_pro_setup_page'      // 回调函数
    );
}
add_action('admin_menu', 'lsky_pro_add_setup_page'); 