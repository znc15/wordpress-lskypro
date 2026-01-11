<?php

if (!defined('ABSPATH')) {
    exit;
}

function lsky_pro_force_migrate_options_to_v2() {
    $options = get_option('lsky_pro_options');
    if (!is_array($options)) {
        return;
    }

    $changed = false;

    if (isset($options['strategy_id'])) {
        $options['storage_id'] = (string) absint($options['strategy_id']);
        $changed = true;
    }

    if (isset($options['storage_id'])) {
        $storage_id = absint($options['storage_id']);
        if ($storage_id <= 0) {
            $options['storage_id'] = '1';
            $changed = true;
        } else {
            $options['storage_id'] = (string) $storage_id;
        }
    }

    foreach (array('strategy_id', 'permission', 'expired_at') as $legacy_key) {
        if (array_key_exists($legacy_key, $options)) {
            unset($options[$legacy_key]);
            $changed = true;
        }
    }

    if ($changed) {
        update_option('lsky_pro_options', $options);
    }
}

// 强制迁移：尽早执行，避免后续逻辑继续读取旧字段。
lsky_pro_force_migrate_options_to_v2();

// 加载所有必需的类文件
require_once LSKY_PRO_PLUGIN_DIR . 'includes/uploader.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/upload-exclusions.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/remote.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/upload-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/post-handler.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/batch.php';
require_once LSKY_PRO_PLUGIN_DIR . 'includes/api.php';

// 加载拆分后的功能模块
require_once LSKY_PRO_PLUGIN_DIR . 'modules/settings.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/admin.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/media.php';
require_once LSKY_PRO_PLUGIN_DIR . 'modules/ajax.php';

// 初始化类
new LskyProUploadHandler();
new LskyProPostHandler();
new LskyProBatch();
