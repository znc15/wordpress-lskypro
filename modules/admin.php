<?php

if (!defined('ABSPATH')) {
    exit;
}

function lsky_pro_enqueue_bootstrap() {
    $bootstrap_ver = '5.1.3';
    $bootstrap_handle = 'lsky-pro-bootstrap';

    wp_enqueue_style(
        $bootstrap_handle,
        'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css',
        array(),
        $bootstrap_ver
    );

    wp_enqueue_script(
        $bootstrap_handle,
        'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js',
        array(),
        $bootstrap_ver,
        true
    );

    return $bootstrap_handle;
}

function lsky_pro_add_admin_menu() {
    add_menu_page(
        'LskyPro设置',
        'LskyPro图床',
        'manage_options',
        'lsky-pro-settings',
        'lsky_pro_dashboard_page',
        'dashicons-images-alt2',
        80
    );

    $submenus = array(
        array('lsky-pro-settings', 'LskyPro 概览', '概览', 'lsky_pro_dashboard_page'),
        array('lsky-pro-batch', 'LskyPro 批量处理', '批量处理', 'lsky_pro_batch_page'),
        array('lsky-pro-config', 'LskyPro 设置', '设置', 'lsky_pro_settings_page'),
        array('lsky-pro-changelog', 'LskyPro 版本更新', '版本更新', 'lsky_pro_changelog_page')
    );

    foreach ($submenus as $submenu) {
        add_submenu_page(
            'lsky-pro-settings',
            $submenu[1],
            $submenu[2],
            'manage_options',
            $submenu[0],
            $submenu[3]
        );
    }
}
add_action('admin_menu', 'lsky_pro_add_admin_menu');

function lsky_pro_admin_scripts($hook) {
    if ($hook === 'admin_page_lsky-pro-setup') {
        lsky_pro_enqueue_setup_assets();
        return;
    }

    $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $lsky_pages = array('lsky-pro-settings', 'lsky-pro-batch', 'lsky-pro-config', 'lsky-pro-changelog');

    $is_lsky_page = (
        $hook === 'toplevel_page_lsky-pro-settings'
        || strpos($hook, 'lsky-pro-settings_page_') === 0
        || in_array($current_page, $lsky_pages, true)
    );

    if (!$is_lsky_page) return;

    $bootstrap_handle = lsky_pro_enqueue_bootstrap();
    wp_enqueue_style('lsky-pro-admin', plugins_url('assets/css/admin-style.css', LSKY_PRO_PLUGIN_FILE));

    $base_deps = array('jquery', $bootstrap_handle);

    switch ($current_page) {
        case 'lsky-pro-batch':
            $batch_css_path = trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/css/batch.css';
            $base_js_path = trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/js/admin/base.js';
            $batch_js_path = trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/js/admin/batch.js';

            $batch_css_ver = file_exists($batch_css_path) ? (string) filemtime($batch_css_path) : null;
            $base_js_ver = file_exists($base_js_path) ? (string) filemtime($base_js_path) : null;
            $batch_js_ver = file_exists($batch_js_path) ? (string) filemtime($batch_js_path) : null;

            wp_enqueue_style('lsky-pro-batch', plugins_url('assets/css/batch.css', LSKY_PRO_PLUGIN_FILE), array($bootstrap_handle, 'lsky-pro-admin'), $batch_css_ver);
            wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), $base_deps, $base_js_ver, true);
            wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
                'nonce' => wp_create_nonce('lsky_pro_ajax'),
                'batchNonce' => wp_create_nonce('lsky_pro_batch'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            wp_enqueue_script('lsky-pro-admin-batch', plugins_url('assets/js/admin/batch.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), $batch_js_ver, true);
            break;

        case 'lsky-pro-settings':
            wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), $base_deps, '1.1.0', true);
            wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
                'nonce' => wp_create_nonce('lsky_pro_ajax'),
                'batchNonce' => wp_create_nonce('lsky_pro_batch'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            wp_enqueue_script('lsky-pro-admin-info', plugins_url('assets/js/admin/info.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), '1.1.0', true);
            wp_enqueue_script('lsky-pro-admin-update', plugins_url('assets/js/admin/update.js', LSKY_PRO_PLUGIN_FILE), array('lsky-pro-admin-base'), '1.1.0', true);
            break;

        case 'lsky-pro-config':
            wp_enqueue_script('lsky-pro-admin-base', plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), $base_deps, null, true);
            wp_localize_script('lsky-pro-admin-base', 'lskyProData', array(
                'nonce' => wp_create_nonce('lsky_pro_ajax'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            break;
    }
}
add_action('admin_enqueue_scripts', 'lsky_pro_admin_scripts');

function lsky_pro_enqueue_setup_assets() {
    $bootstrap_handle = lsky_pro_enqueue_bootstrap();
    wp_enqueue_style('lsky-pro-style', plugins_url('assets/css/style.css', LSKY_PRO_PLUGIN_FILE));
    wp_enqueue_script('lsky-pro-setup-script', plugins_url('assets/js/setup.js', LSKY_PRO_PLUGIN_FILE), array('jquery', $bootstrap_handle), null, true);
    wp_localize_script('lsky-pro-setup-script', 'lskyProSetupData', array(
        'nonce' => wp_create_nonce('lsky_pro_setup'),
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}

function lsky_pro_render_page($title, $template) {
    ?>
    <div class="wrap lsky-dashboard">
        <div class="lsky-header">
            <h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
        </div>
        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card shadow-sm">
                    <div class="lsky-card-header bg-gradient">
                        <h2 class="mb-0"><?php echo esc_html($title); ?></h2>
                    </div>
                    <div class="lsky-card-body">
                        <?php include LSKY_PRO_PLUGIN_DIR . $template; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function lsky_pro_changelog_page() {
    lsky_pro_render_page('版本更新', 'templates/version-updates.php');
}

function lsky_pro_dashboard_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <div class="lsky-header">
            <h1 class="wp-heading-inline">账号信息</h1>
        </div>
        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card shadow-sm">
                    <div class="lsky-card-header bg-gradient">
                        <h2 class="mb-0">账号信息</h2>
                    </div>
                    <div class="lsky-card-body">
                        <div id="user-info">
                            <div class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                                <p class="mt-3 text-muted">正在加载账号信息...</p>
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
    lsky_pro_render_page('批量处理', 'templates/batch-process.php');
}

function lsky_pro_settings_page() {
    ?>
    <div class="wrap lsky-dashboard">
        <div class="lsky-header">
            <h1 class="wp-heading-inline">设置</h1>
        </div>
        <div class="row mt-4">
            <div class="col-12">
                <div class="lsky-card shadow-sm">
                    <div class="lsky-card-header bg-gradient">
                        <h2 class="mb-0">插件设置</h2>
                    </div>
                    <div class="lsky-card-body">
                        <?php lsky_pro_render_settings_messages(); ?>
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

function lsky_pro_render_settings_messages() {
    $messages = get_settings_errors('lsky_pro_options');
    if (empty($messages)) return;

    $alert_types = array(
        'error' => 'alert-danger',
        'success' => 'alert-success',
        'updated' => 'alert-success',
        'warning' => 'alert-warning'
    );

    foreach ($messages as $m) {
        $type = isset($m['type']) ? (string) $m['type'] : 'info';
        $message = isset($m['message']) ? (string) $m['message'] : '';
        $alert_class = isset($alert_types[$type]) ? $alert_types[$type] : 'alert-info';

        if ($message !== '') {
            echo '<div class="alert ' . esc_attr($alert_class) . ' alert-dismissible fade show" role="alert">';
            echo wp_kses_post($message);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
        }
    }
}

function lsky_pro_add_settings_link($links) {
    array_unshift($links, '<a href="admin.php?page=lsky-pro-config">设置</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(LSKY_PRO_PLUGIN_FILE), 'lsky_pro_add_settings_link');

function lsky_pro_admin_notices() {
    if (!current_user_can('manage_options')) return;

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['post', 'edit-post'])) return;

    $options = get_option('lsky_pro_options');
    if (empty($options['process_remote_images'])) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>LskyPro图床：远程图片自动处理功能未启用。如需自动处理外链图片，请在<a href="admin.php?page=lsky-pro-config">设置</a>中启用此功能。</p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'lsky_pro_admin_notices');

function lsky_pro_admin_footer() {
    ?>
    <div id="lsky-upload-status" class="lsky-upload-status" style="display:none;">
        <div class="upload-progress">
            <span class="status-text">正在上传...</span>
            <div class="progress-bar-wrapper">
                <div class="progress-bar-fill"></div>
            </div>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        const $status = $('#lsky-upload-status');
        const $text = $status.find('.status-text');
        const $progress = $status.find('.progress-bar-fill');

        $(document).on('wp-upload-media-started', function() {
            $status.removeClass('error').show();
            $text.text('正在上传...');
            $progress.css('width', '0%');
        });

        $(document).on('wp-upload-media-success', function() {
            $text.text('上传成功！');
            $progress.css('width', '100%');
            setTimeout(() => $status.fadeOut(), 2000);
        });

        $(document).on('wp-upload-media-error', function() {
            $text.text('上传失败，请重试');
            $status.addClass('error');
            setTimeout(() => $status.fadeOut(), 3000);
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'lsky_pro_admin_footer');
