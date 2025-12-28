<?php

if (!defined('ABSPATH')) {
    exit;
}

// 兼容入口：保持 modules/bootstrap.php 中 require_once 路径不变
require_once __DIR__ . '/batch/avatar.php';
require_once __DIR__ . '/batch/media.php';
require_once __DIR__ . '/batch/post.php';
require_once __DIR__ . '/batch/reset.php';
require_once __DIR__ . '/batch/main.php';
