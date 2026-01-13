<?php
/*
Plugin Name: LskyPro For WordPress
Plugin URI: https://www.littlesheep.cc
Description: 自动将WordPress上传的图片同步到LskyPro图床
Version: 1.1.0
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

// PSR-4 autoloader: LskyPro\\* => /src/*
spl_autoload_register(static function (string $class): void {
    $prefix = 'LskyPro\\';
    $baseDir = __DIR__ . '/src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relative) . '.php';
    $file = $baseDir . $relativePath;

    if (is_file($file)) {
        require $file;
    }
});

\LskyPro\Plugin::init();