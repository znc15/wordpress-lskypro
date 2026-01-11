<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 插件主配置
delete_option('lsky_pro_options');