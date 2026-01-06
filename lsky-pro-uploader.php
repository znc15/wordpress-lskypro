<?php
/*
Plugin Name: LskyPro For WordPress
Plugin URI: https://www.littlesheep.cc
Description: 自动将WordPress上传的图片同步到LskyPro图床
Version: 1.0.1
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

// 加载拆分后的模块（类文件/钩子注册等都在 bootstrap 内）
require_once LSKY_PRO_PLUGIN_DIR . 'modules/bootstrap.php';
