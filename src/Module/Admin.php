<?php

declare(strict_types=1);

namespace LskyPro\Module;

final class Admin
{
    public function register(): void
    {
        \add_action('admin_menu', [$this, 'add_admin_menu']);
        \add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        \add_action('admin_footer', [$this, 'admin_footer']);

        \add_filter('plugin_action_links_' . \plugin_basename(LSKY_PRO_PLUGIN_FILE), [$this, 'add_settings_link']);
        \add_action('admin_notices', [$this, 'admin_notices']);
    }

    private function enqueue_bootstrap(): string
    {
        $bootstrapVer = '5.1.3';
        $bootstrapHandle = 'lsky-pro-bootstrap';

        \wp_enqueue_style(
            $bootstrapHandle,
            'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css',
            [],
            $bootstrapVer
        );

        \wp_enqueue_script(
            $bootstrapHandle,
            'https://cdn.bootcdn.net/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js',
            [],
            $bootstrapVer,
            true
        );

        return $bootstrapHandle;
    }

    public function add_admin_menu(): void
    {
        \add_menu_page(
            'LskyPro设置',
            'LskyPro图床',
            'manage_options',
            'lsky-pro-settings',
            [$this, 'dashboard_page'],
            'dashicons-images-alt2',
            80
        );

        $submenus = [
            ['lsky-pro-settings', 'LskyPro 概览', '概览', [$this, 'dashboard_page']],
            ['lsky-pro-batch', 'LskyPro 批量处理', '批量处理', [$this, 'batch_page']],
            ['lsky-pro-config', 'LskyPro 设置', '设置', [$this, 'settings_page']],
            ['lsky-pro-changelog', 'LskyPro 版本更新', '版本更新', [$this, 'changelog_page']],
        ];

        foreach ($submenus as $submenu) {
            \add_submenu_page(
                'lsky-pro-settings',
                (string) $submenu[1],
                (string) $submenu[2],
                'manage_options',
                (string) $submenu[0],
                $submenu[3]
            );
        }
    }

    public function admin_scripts(string $hook): void
    {
        $currentPage = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : '';
        $lskyPages = ['lsky-pro-settings', 'lsky-pro-batch', 'lsky-pro-config', 'lsky-pro-changelog'];

        $isLskyPage = (
            $hook === 'toplevel_page_lsky-pro-settings'
            || \strpos($hook, 'lsky-pro-settings_page_') === 0
            || \in_array($currentPage, $lskyPages, true)
        );

        if (!$isLskyPage) {
            return;
        }

        $bootstrapHandle = $this->enqueue_bootstrap();
        \wp_enqueue_style('lsky-pro-admin', \plugins_url('assets/css/admin-style.css', LSKY_PRO_PLUGIN_FILE));

        $baseDeps = ['jquery', $bootstrapHandle];

        switch ($currentPage) {
            case 'lsky-pro-batch':
                $batchCssPath = \trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/css/batch.css';
                $baseJsPath = \trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/js/admin/base.js';
                $batchJsPath = \trailingslashit(LSKY_PRO_PLUGIN_DIR) . 'assets/js/admin/batch.js';

                $batchCssVer = \file_exists($batchCssPath) ? (string) \filemtime($batchCssPath) : null;
                $baseJsVer = \file_exists($baseJsPath) ? (string) \filemtime($baseJsPath) : null;
                $batchJsVer = \file_exists($batchJsPath) ? (string) \filemtime($batchJsPath) : null;

                \wp_enqueue_style(
                    'lsky-pro-batch',
                    \plugins_url('assets/css/batch.css', LSKY_PRO_PLUGIN_FILE),
                    [$bootstrapHandle, 'lsky-pro-admin'],
                    $batchCssVer
                );

                \wp_enqueue_script(
                    'lsky-pro-admin-base',
                    \plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE),
                    $baseDeps,
                    $baseJsVer,
                    true
                );

                \wp_localize_script('lsky-pro-admin-base', 'lskyProData', [
                    'nonce' => \wp_create_nonce('lsky_pro_ajax'),
                    'batchNonce' => \wp_create_nonce('lsky_pro_batch'),
                    'ajaxurl' => \admin_url('admin-ajax.php'),
                ]);

                \wp_enqueue_script(
                    'lsky-pro-admin-batch',
                    \plugins_url('assets/js/admin/batch.js', LSKY_PRO_PLUGIN_FILE),
                    ['lsky-pro-admin-base'],
                    $batchJsVer,
                    true
                );
                break;

            case 'lsky-pro-settings':
                \wp_enqueue_script('lsky-pro-admin-base', \plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), $baseDeps, '1.0.1', true);
                $options = \get_option('lsky_pro_options');
                $apiUrl = (\is_array($options) && isset($options['lsky_pro_api_url'])) ? (string) $options['lsky_pro_api_url'] : '';
                \wp_localize_script('lsky-pro-admin-base', 'lskyProData', [
                    'nonce' => \wp_create_nonce('lsky_pro_ajax'),
                    'batchNonce' => \wp_create_nonce('lsky_pro_batch'),
                    'ajaxurl' => \admin_url('admin-ajax.php'),
                    'apiUrl' => $apiUrl,
                ]);
                \wp_enqueue_script('lsky-pro-admin-info', \plugins_url('assets/js/admin/info.js', LSKY_PRO_PLUGIN_FILE), ['lsky-pro-admin-base'], '1.0.1', true);
                \wp_enqueue_script('lsky-pro-admin-update', \plugins_url('assets/js/admin/update.js', LSKY_PRO_PLUGIN_FILE), ['lsky-pro-admin-base'], '1.0.1', true);
                break;

            case 'lsky-pro-config':
                \wp_enqueue_script('lsky-pro-admin-base', \plugins_url('assets/js/admin/base.js', LSKY_PRO_PLUGIN_FILE), $baseDeps, null, true);
                \wp_localize_script('lsky-pro-admin-base', 'lskyProData', [
                    'nonce' => \wp_create_nonce('lsky_pro_ajax'),
                    'ajaxurl' => \admin_url('admin-ajax.php'),
                ]);
                break;
        }
    }

    private function render_page(string $title, string $template): void
    {
        ?>
        <div class="wrap lsky-dashboard">
            <div class="lsky-header">
                <h1 class="wp-heading-inline"><?php echo \esc_html($title); ?></h1>
            </div>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="lsky-card shadow-sm">
                        <div class="lsky-card-header bg-gradient">
                            <h2 class="mb-0"><?php echo \esc_html($title); ?></h2>
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

    public function changelog_page(): void
    {
        $this->render_page('版本更新', 'templates/version-updates.php');
    }

    public function dashboard_page(): void
    {
        ?>
        <div class="wrap lsky-dashboard lsky-overview-page">
            <h1 class="wp-heading-inline">概览</h1>
            <div id="user-info">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">加载中...</span>
                    </div>
                    <p class="mt-3 text-muted">正在加载概览信息...</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function batch_page(): void
    {
        $this->render_page('批量处理', 'templates/batch-process.php');
    }

    public function settings_page(): void
    {
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
                            <?php $this->render_settings_messages(); ?>
                            <form action='options.php' method='post' class="needs-validation" novalidate>
                                <?php
                                \settings_fields('lsky_pro_options');
                                \do_settings_sections('lsky-pro-settings');
                                \submit_button('保存设置', 'primary', 'submit', true, ['class' => 'btn btn-primary btn-lg mt-3']);
                                ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_settings_messages(): void
    {
        $messages = \get_settings_errors('lsky_pro_options');
        if (empty($messages)) {
            return;
        }

        $alertTypes = [
            'error' => 'alert-danger',
            'success' => 'alert-success',
            'updated' => 'alert-success',
            'warning' => 'alert-warning',
        ];

        foreach ($messages as $m) {
            $type = isset($m['type']) ? (string) $m['type'] : 'info';
            $message = isset($m['message']) ? (string) $m['message'] : '';
            $alertClass = isset($alertTypes[$type]) ? $alertTypes[$type] : 'alert-info';

            if ($message !== '') {
                echo '<div class="alert ' . \esc_attr($alertClass) . ' alert-dismissible fade show" role="alert">';
                echo \wp_kses_post($message);
                echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                echo '</div>';
            }
        }
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function add_settings_link(array $links): array
    {
        \array_unshift($links, '<a href="admin.php?page=lsky-pro-config">设置</a>');
        return $links;
    }

    public function admin_notices(): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }

        $screen = \function_exists('get_current_screen') ? \get_current_screen() : null;
        if (!$screen || !\in_array((string) $screen->id, ['post', 'edit-post'], true)) {
            return;
        }

        $options = \get_option('lsky_pro_options');
        if (!\is_array($options) || empty($options['process_remote_images'])) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>LskyPro图床：远程图片自动处理功能未启用。如需自动处理外链图片，请在<a href="admin.php?page=lsky-pro-config">设置</a>中启用此功能。</p>';
            echo '</div>';
        }
    }

    public function admin_footer(): void
    {
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
}
