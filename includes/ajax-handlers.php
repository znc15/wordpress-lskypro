<?php
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
    $strategies = $api->get_strategies();
    
    if ($user_info === false || $strategies === false) {
        wp_send_json_error($api->getError());
    }
    
    wp_send_json_success(array(
        'user_info' => $user_info,
        'strategies' => $strategies
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
    if (!isset($result['status']) || $result['status'] !== true) {
        wp_send_json_error(array('message' => $result['message'] ?? '未知错误'));
    }
    
    wp_send_json_success(array('token' => $result['data']['token']));
}
add_action('wp_ajax_lsky_pro_get_token', 'lsky_pro_get_token_ajax'); 