<?php

if (!defined('ABSPATH')) {
    exit;
}

// 添加媒体库列表显示图床URL
function lsky_pro_media_columns($columns) {
    $columns['lsky_url'] = '图床URL';
    $columns['lsky_status'] = '图床状态';
    return $columns;
}
add_filter('manage_media_columns', 'lsky_pro_media_columns');

function lsky_pro_media_custom_column($column_name, $post_id) {
    if ($column_name == 'lsky_url') {
        $lsky_url = get_post_meta($post_id, '_lsky_pro_url', true);
        if ($lsky_url) {
            echo esc_url($lsky_url);
        }
    }
    if ($column_name == 'lsky_status') {
        $attachment_url = wp_get_attachment_url($post_id);
        if (strpos($attachment_url, get_site_url()) === false) {
            echo '<span style="color:#46b450;">✓ 已上传到图床</span>';
        } else {
            echo '<span style="color:#dc3232;">✗ 本地存储</span>';
        }
    }
}
add_action('manage_media_custom_column', 'lsky_pro_media_custom_column', 10, 2);

// 禁用 WordPress 默认图片尺寸
function lsky_pro_disable_image_sizes($sizes) {
    // 需要禁用的尺寸
    $disabled_sizes = array('thumbnail', 'medium', 'medium_large', 'large');

    // 从尺寸数组中移除指定尺寸
    foreach ($disabled_sizes as $size) {
        unset($sizes[$size]);
    }

    return $sizes;
}
add_filter('intermediate_image_sizes_advanced', 'lsky_pro_disable_image_sizes');

// 禁用缩放尺寸的生成
function lsky_pro_disable_scaled_image_size($default) {
    return false;
}
add_filter('big_image_size_threshold', 'lsky_pro_disable_scaled_image_size');

// 在插件激活时执行的函数
function lsky_pro_plugin_activation() {
    // 更新图片尺寸设置为0，实际禁用这些尺寸
    update_option('thumbnail_size_w', 0);
    update_option('thumbnail_size_h', 0);
    update_option('medium_size_w', 0);
    update_option('medium_size_h', 0);
    update_option('medium_large_size_w', 0);
    update_option('medium_large_size_h', 0);
    update_option('large_size_w', 0);
    update_option('large_size_h', 0);
}
register_activation_hook(LSKY_PRO_PLUGIN_FILE, 'lsky_pro_plugin_activation');

// 在插件停用时恢复默认设置
function lsky_pro_plugin_deactivation() {
    // 恢复WordPress默认的图片尺寸设置
    update_option('thumbnail_size_w', 150);
    update_option('thumbnail_size_h', 150);
    update_option('medium_size_w', 300);
    update_option('medium_size_h', 300);
    update_option('medium_large_size_w', 768);
    update_option('medium_large_size_h', 0);
    update_option('large_size_w', 1024);
    update_option('large_size_h', 1024);
}
register_deactivation_hook(LSKY_PRO_PLUGIN_FILE, 'lsky_pro_plugin_deactivation');
