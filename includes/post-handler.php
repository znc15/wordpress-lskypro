<?php
declare(strict_types=1);

/**
 * 文章处理类
 */
class LskyProPostHandler {
    private $remote;
    private $processing = array(); // 用于跟踪正在处理的文章
    
    public function __construct() {
        $this->remote = new LskyProRemote();
        
        // 添加保存文章时的钩子，优先级设为较低以确保在其他操作后执行
        add_action('save_post', array($this, 'handle_post_save'), 999, 3);
        
        // 移除其他钩子，统一由 save_post 处理
        // add_action('publish_post', array($this, 'handle_post_publish'), 10, 2);
        // add_action('draft_to_publish', array($this, 'handle_post_status_change'), 10, 1);
        // add_action('pending_to_publish', array($this, 'handle_post_status_change'), 10, 1);
    }
    
    /**
     * 处理文章保存
     */
    public function handle_post_save($post_id, $post, $update) {
        // 防止自动保存和修订版本触发处理
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if ($post->post_type !== 'post') return;
        
        // 防止重复处理
        if (isset($this->processing[$post_id])) {
            error_log("LskyPro: 跳过正在处理中的文章 {$post_id}");
            return;
        }
        
        // 标记文章正在处理
        $this->processing[$post_id] = true;
        
        error_log("LskyPro: 文章保存触发处理 - ID: {$post_id}, 状态: {$post->post_status}");
        
        // 获取选项设置
        $options = get_option('lsky_pro_options');
        if (empty($options['process_remote_images'])) {
            error_log('LskyPro: 远程图片处理未启用');
            unset($this->processing[$post_id]);
            return;
        }
        
        // 处理文章中的远程图片
        try {
            $result = $this->remote->process_post_images($post_id);
            if ($result) {
                $results = $this->remote->get_results();
                error_log("LskyPro: 文章 {$post_id} 处理完成 - 成功: {$results['processed']}, 失败: {$results['failed']}");
                
                // 如果有成功处理的图片，添加通知
                if ($results['processed'] > 0) {
                    add_action('admin_notices', function() use ($results) {
                        echo '<div class="notice notice-success is-dismissible">';
                        echo '<p>LskyPro：成功处理 ' . $results['processed'] . ' 张远程图片</p>';
                        echo '</div>';
                    });
                }
                
                // 如果有失败的图片，添加通知
                if ($results['failed'] > 0) {
                    add_action('admin_notices', function() use ($results) {
                        echo '<div class="notice notice-warning is-dismissible">';
                        echo '<p>LskyPro：' . $results['failed'] . ' 张图片处理失败</p>';
                        echo '</div>';
                    });
                }
            } else {
                error_log("LskyPro: 文章 {$post_id} 处理失败 - " . $this->remote->getError());
            }
        } catch (Exception $e) {
            error_log("LskyPro: 文章处理异常 - " . $e->getMessage());
        }
        
        // 处理完成后移除标记
        unset($this->processing[$post_id]);
    }
}
