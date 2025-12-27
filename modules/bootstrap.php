<?php

if (!defined('ABSPATH')) {
    exit;
}

// 加载所有必需的类文件
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-uploader.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-remote.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-upload-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-post-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-batch.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-api.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/cron-process-images.php';
require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup.php';
require_once LSKY_PRO_PLUGIN_DIR . 'setup/setup-2.php';

// 加载拆分后的功能模块
require_once LSKY_PRO_PLUGIN_DIR . 'modules/settings.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/admin.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/media.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/ajax.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/setup-page.php';

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
