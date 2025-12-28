<?php

if (!defined('ABSPATH')) {
    exit;
}

// 添加安装检查函数
function lsky_pro_check_installation() {
    // 检查是否在插件设置页面
    $current_screen = get_current_screen();
    if ($current_screen) {
        // 避免在配置向导页自身触发重定向
        if (strpos($current_screen->id, 'admin_page_lsky-pro-setup') === 0) {
            return;
        }

        // 覆盖顶级页与所有子页面
        $is_lsky_admin = (
            $current_screen->id === 'toplevel_page_lsky-pro-settings'
            || strpos($current_screen->id, 'lsky-pro-settings_page_') === 0
        );

        if (!$is_lsky_admin) {
            return;
        }

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
