<?php

// 兼容文件：AJAX 处理已统一迁移到 modules/ajax.php
// 保留该文件，避免旧代码/引用路径失效。

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LSKY_PRO_PLUGIN_DIR')) {
    require_once LSKY_PRO_PLUGIN_DIR . 'modules/ajax.php';
}