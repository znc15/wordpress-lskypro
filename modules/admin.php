<?php

if (!defined('ABSPATH')) {
    exit;
}

// 修改菜单添加函数
function lsky_pro_add_admin_menu() {
    add_menu_page(
        'LskyPro设置', // 页面标题
        'LskyPro图床', // 菜单标题
        'manage_options', // 权限
        'lsky-pro-settings', // 菜单slug
        'lsky_pro_dashboard_page', // 回调函数（概览）
        'dashicons-images-alt2', // 图标
        80 // 位置
    );

    // 子页面：概览（与顶级页同 slug，确保默认落地页不变）
    add_submenu_page(
        'lsky-pro-settings',
        'LskyPro 概览',
        '概览',
        'manage_options',
        'lsky-pro-settings',
        'lsky_pro_dashboard_page'
    );

    // 子页面：批量处理
    add_submenu_page(
        'lsky-pro-settings',
        'LskyPro 批量处理',
        '批量处理',
        'manage_options',
        'lsky-pro-batch',
        'lsky_pro_batch_page'
    );

    // 子页面：设置
    add_submenu_page(
        'lsky-pro-settings',
        'LskyPro 设置',
        '设置',
        'manage_options',
        'lsky-pro-config',
        'lsky_pro_settings_page'
    );

    // 子页面：版本更新
    add_submenu_page(
        'lsky-pro-settings',
        'LskyPro 版本更新',
        '版本更新',
        'manage_options',
        'lsky-pro-changelog',
        'lsky_pro_changelog_page'
    );
}
add_action('admin_menu', 'lsky_pro_add_admin_menu');

// 添加新的 admin_enqueue_scripts 钩子
function lsky_pro_admin_scripts($hook) {
    // 配置向导页面单独处理
    if ($hook === 'admin_page_lsky-pro-setup') {
        lsky_pro_enqueue_setup_assets();
        return;
    }

    $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    // 兼容性兜底：部分环境/主题/插件可能导致 $hook 与预期不一致，
    // 这里同时按 page 参数匹配，避免资源未加载导致“没有 UI”。
    $is_lsky_page = (
        $hook === 'toplevel_page_lsky-pro-settings'
        || strpos($hook, 'lsky-pro-settings_page_') === 0
        || in_array($current_page, array('lsky-pro-settings', 'lsky-pro-batch', 'lsky-pro-config', 'lsky-pro-changelog'), true)
    );
    if (!$is_lsky_page) return;

    // 注册并加载 Bootstrap（国内 CDN：BootCDN）
    $bootstrap_ver = '5.1.3';
    // 使用插件私有 handle，避免被其它插件以同名 handle 覆盖/注册
    $bootstrap_handle = 'lsky-pro-bootstrap';
    $bootstrap_css = 'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css';
    $bootstrap_js = 'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js';
    wp_enqueue_style(
        $bootstrap_handle,
        $bootstrap_css,
        array(),
        $bootstrap_ver
    );
    wp_enqueue_script(
        $bootstrap_handle,
        $bootstrap_js,
        array(),
        $bootstrap_ver,
        true
    );

    // 注册并加载自定义样式和脚本
    wp_enqueue_style('lsky-pro-admin', plugins_url('assets/css/admin-style.css', LSKY_PRO_PLUGIN_FILE));

    // 按页面加载脚本，避免拆分后因元素不存在导致报错
    if ($current_page === 'lsky-pro-batch') {
        // 批量处理页专用样式（与 admin-style.css 分离）
        wp_enqueue_style(
            'lsky-pro-batch',
            plugins_url('assets/css/batch.css', LSKY_PRO_PLUGIN_FILE),
            array($bootstrap_handle, 'lsky-pro-admin'),
            null
        );

        // 批量处理页脚本（拆分为多个子文件）
        wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), array('jquery', $bootstrap_handle), null, true);
        wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
            'nonce' => wp_create_nonce('lsky_pro_ajax'),
            'batchNonce' => wp_create_nonce('lsky_pro_batch'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
        wp_enqueue_script('lsky-pro-admin-batch', plugins_url('assets/js/admin/batch.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), null, true);
        return;
    }

    if ($current_page === 'lsky-pro-settings') {
        // 概览页：用户信息等（拆分为多个子文件）
        wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), array('jquery', $bootstrap_handle), null, true);
        wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
            'nonce' => wp_create_nonce('lsky_pro_ajax'),
            'batchNonce' => wp_create_nonce('lsky_pro_batch'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
        wp_enqueue_script('lsky-pro-admin-info', plugins_url('assets/js/admin/info.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), null, true);
        wp_enqueue_script('lsky-pro-admin-update', plugins_url('assets/js/admin/update.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), null, true);
        return;
    }

    if ($current_page === 'lsky-pro-config') {
        // 设置页：也需要加载脚本以支持交互功能
        wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), array('jquery', $bootstrap_handle), null, true);
        wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
            'nonce' => wp_create_nonce('lsky_pro_ajax'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
        return;
    }
}
add_action('admin_enqueue_scripts', 'lsky_pro_admin_scripts');

function lsky_pro_changelog_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>版本更新</h2>
                    </div>
                    <div class="lsky-card-body">
                        <?php include LSKY_PRO_PLUGIN_DIR . 'templates/version-updates.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// 配置向导页面资源加载函数
function lsky_pro_enqueue_setup_assets() {
    // 加载 Bootstrap（国内 CDN：BootCDN）
    $bootstrap_ver = '5.1.3';
    $bootstrap_handle = 'lsky-pro-bootstrap';
    $bootstrap_css = 'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css';
    $bootstrap_js = 'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js';
    wp_enqueue_style(
        $bootstrap_handle,
        $bootstrap_css,
        array(),
        $bootstrap_ver
    );
    wp_enqueue_script(
        $bootstrap_handle,
        $bootstrap_js,
        array(),
        $bootstrap_ver,
        true
    );

    // 加载自定义样式（保留现有样式类）
    wp_enqueue_style('lsky-pro-style', plugins_url('assets/css/style.css', LSKY_PRO_PLUGIN_FILE));

    // 配置向导交互脚本（jQuery + Bootstrap）
    wp_enqueue_script(
        'lsky-pro-setup-script',
        plugins_url('assets/js/setup.js', LSKY_PRO_PLUGIN_FILE),
        array('jquery', $bootstrap_handle),
        null,
        true
    );

    // 传递数据给 JavaScript
    wp_localize_script('lsky-pro-setup-script', 'lskyProSetupData', array(
        'nonce' => wp_create_nonce('lsky_pro_setup'),
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}

function lsky_pro_dashboard_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="row mt-4">
            <div class="col-12">
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
        </div>
    </div>
    <?php
}

function lsky_pro_batch_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>批量处理</h2>
                    </div>
                    <div class="lsky-card-body">
                        <?php include LSKY_PRO_PLUGIN_DIR . 'templates/batch-process.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function lsky_pro_settings_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card">
                    <div class="lsky-card-header">
                        <h2>设置</h2>
                    </div>
                    <div class="lsky-card-body">
                        <?php
                        // 使用 Bootstrap UI 展示设置保存提示（success/error）
                        $messages = get_settings_errors('lsky_pro_options');
                        if (!empty($messages)) {
                            foreach ($messages as $m) {
                                $type = isset($m['type']) ? (string) $m['type'] : 'info';
                                $message = isset($m['message']) ? (string) $m['message'] : '';

                                $alert_class = 'alert-info';
                                if ($type === 'error') {
                                    $alert_class = 'alert-danger';
                                } elseif ($type === 'success' || $type === 'updated') {
                                    $alert_class = 'alert-success';
                                } elseif ($type === 'warning') {
                                    $alert_class = 'alert-warning';
                                }

                                if ($message !== '') {
                                    echo '<div class="alert ' . esc_attr($alert_class) . ' alert-dismissible fade show" role="alert">';
                                    echo wp_kses_post($message);
                                    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                                    echo '</div>';
                                }
                            }
                        }
                        ?>
                        <form action='options.php' method='post' class="needs-validation" novalidate>
                            <?php
                            settings_fields('lsky_pro_options');
                            do_settings_sections('lsky-pro-settings');
                            submit_button('保存设置', 'primary', 'submit', true, array('class' => 'btn btn-primary btn-lg mt-3'));
                            ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// 添加设置链接到插件页面
function lsky_pro_add_settings_link($links) {
    $settings_link = '<a href="admin.php?page=lsky-pro-config">' . __('设置') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(LSKY_PRO_PLUGIN_FILE), 'lsky_pro_add_settings_link');

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
