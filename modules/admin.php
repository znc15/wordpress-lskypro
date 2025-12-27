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
        'lsky_pro_options_page', // 回调函数
        'dashicons-images-alt2', // 图标
        80 // 位置
    );
}
add_action('admin_menu', 'lsky_pro_add_admin_menu');

// 添加新的 admin_enqueue_scripts 钩子
function lsky_pro_admin_scripts($hook) {
    if ('toplevel_page_lsky-pro-settings' !== $hook) {
        return;
    }

    // 注册并加载 Bootstrap
    wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
    wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);

    // 注册并加载自定义样式和脚本
    wp_enqueue_style('lsky-pro-admin', plugins_url('assets/css/admin-style.css', LSKY_PRO_PLUGIN_FILE));
    wp_enqueue_script('lsky-pro-admin', plugins_url('assets/js/admin-script.js', LSKY_PRO_PLUGIN_FILE), array('jquery', 'bootstrap'), null, true);

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
                        <?php include LSKY_PRO_PLUGIN_DIR . 'templates/batch-process.php'; ?>
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

// 添加设置链接到插件页面
function lsky_pro_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=lsky-pro-settings">' . __('设置') . '</a>';
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
