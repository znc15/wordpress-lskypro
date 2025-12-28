<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 处理AJAX请求
 */
function lsky_pro_ajax_get_info() {
    check_ajax_referer('lsky_pro_ajax', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    $api = new LskyProApi();
    $user_info = $api->get_user_info();
    if ($user_info === false) {
        wp_send_json_error($api->getError());
    }

    $strategies = $api->get_strategies();
    $strategies_error = null;
    if ($strategies === false) {
        $strategies = array();
        $strategies_error = $api->getError();
    }

    wp_send_json_success(array(
        'user_info' => $user_info,
        'strategies' => $strategies,
        'strategies_error' => $strategies_error
    ));
}
add_action('wp_ajax_lsky_pro_get_info', 'lsky_pro_ajax_get_info');

function lsky_pro_process_post_images() {
    check_ajax_referer('lsky_pro_ajax', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('权限不足');
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error('无效的文章ID');
    }

    $remote = new LskyProRemote();
    $result = $remote->process_post_images($post_id);

    if ($result === false) {
        wp_send_json_error($remote->getError());
    }

    wp_send_json_success($remote->get_results());
}
add_action('wp_ajax_lsky_pro_process_post_images', 'lsky_pro_process_post_images');
