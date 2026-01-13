<?php

declare(strict_types=1);

namespace LskyPro\Module;

use LskyPro\Api;
use LskyPro\Remote;

final class Ajax
{
    public function register(): void
    {
        \add_action('wp_ajax_lsky_pro_get_info', [$this, 'get_info']);
        \add_action('wp_ajax_lsky_pro_process_post_images', [$this, 'process_post_images']);
    }

    public function get_info(): void
    {
        \check_ajax_referer('lsky_pro_ajax', 'nonce');

        if (!\current_user_can('manage_options')) {
            \wp_send_json_error('权限不足');
        }

        $api = new Api();
        $userInfo = $api->get_user_info();
        if ($userInfo === false) {
            \wp_send_json_error($api->getError());
        }

        $strategies = $api->get_strategies();
        $strategiesError = null;
        if ($strategies === false) {
            $strategies = [];
            $strategiesError = $api->getError();
        }

        \wp_send_json_success([
            'user_info' => $userInfo,
            'strategies' => $strategies,
            'strategies_error' => $strategiesError,
        ]);
    }

    public function process_post_images(): void
    {
        \check_ajax_referer('lsky_pro_ajax', 'nonce');

        if (!\current_user_can('edit_posts')) {
            \wp_send_json_error('权限不足');
        }

        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($postId <= 0) {
            \wp_send_json_error('无效的文章ID');
        }

        $remote = new Remote();
        $result = $remote->process_post_images($postId);
        if ($result === false) {
            \wp_send_json_error($remote->getError());
        }

        \wp_send_json_success($remote->get_results());
    }
}
