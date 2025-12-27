<?php

if (!defined('ABSPATH')) {
    exit;
}

// 添加检查更新的 AJAX 处理函数
function lsky_pro_check_update() {
    check_ajax_referer('lsky_pro_ajax', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    // 获取当前插件版本
    $current_version = '1.0.0'; // 确保这里匹配你的插件版本

    // 获取 GitHub releases
    $response = wp_remote_get(
        'https://api.github.com/repos/znc15/wordpress-lskypro/releases/latest',
        array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ),
            'timeout' => 30,
            'sslverify' => false
        )
    );

    if (is_wp_error($response)) {
        wp_send_json_error('检查更新失败：' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body) || !isset($body['tag_name'])) {
        wp_send_json_error('无法获取最新版本信息');
    }

    // 移除版本号前的 'v' 字符（如果存在）
    $latest_version = ltrim($body['tag_name'], 'v');

    $result = array(
        'current_version' => $current_version,
        'latest_version' => $latest_version,
        'has_update' => version_compare($latest_version, $current_version, '>'),
        'download_url' => $body['zipball_url'] ?? '',
        'release_notes' => $body['body'] ?? ''
    );

    wp_send_json_success($result);
}
add_action('wp_ajax_lsky_pro_check_update', 'lsky_pro_check_update');

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

// 添加 Token 获取的 AJAX 处理
function lsky_pro_get_token_ajax() {
    check_ajax_referer('lsky_pro_get_token', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }

    $api_url = sanitize_url($_POST['api_url']);
    $email = sanitize_email($_POST['email']);
    $password = $_POST['password'];

    $response = wp_remote_post($api_url . '/tokens', array(
        'headers' => array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array(
            'email' => $email,
            'password' => $password
        ))
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }

    $result = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($result) || (isset($result['status']) && $result['status'] !== true && $result['status'] !== 'success')) {
        wp_send_json_error(array('message' => $result['message'] ?? '未知错误'));
    }

    wp_send_json_success(array('token' => $result['data']['token']));
}
add_action('wp_ajax_lsky_pro_get_token', 'lsky_pro_get_token_ajax');
