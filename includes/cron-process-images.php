<?php
// 定时任务/批量处理脚本已移除（setup-2 与 cron 相关功能已删除）。
// 保留空壳文件用于兼容旧的系统计划任务命令；如仍在调用，请移除服务器上的相关计划任务。

if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "LskyPro: cron-process-images.php 已废弃，请删除旧计划任务命令。\n");
    exit(1);
}

if (!defined('ABSPATH')) {
    exit;
}