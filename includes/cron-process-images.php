<?php
// 图片处理任务类
// 确保是从WordPress环境中调用
if (!defined('ABSPATH')) {
    // 正确定位wp-load.php文件
    $wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
    
    if (!file_exists($wp_load_path)) {
        // 尝试向上一级目录查找
        $wp_load_path = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
    }
    
    if (!file_exists($wp_load_path)) {
        die("无法找到 WordPress 环境，请确保脚本位置正确\n");
    }
    
    require_once($wp_load_path);
}

// 设置执行时间限制
set_time_limit(0);
ini_set('memory_limit', '256M');

class LskyProImageProcessor {
    private $results;
    private $remote;
    private $start_time;
    
    public function __construct() {
        $this->start_time = time();
        // 确保必要的类文件已加载
        require_once(LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-remote.php');
        require_once(LSKY_PRO_PLUGIN_DIR . 'includes/class-lsky-pro-uploader.php');
        
        $this->remote = new LskyProRemote();
        $this->results = array(
            'total' => 0,
            'processed' => 0,
            'failed' => 0,
            'details' => array()
        );
    }
    
    public function process() {
        try {
            // 记录开始状态
            update_option('lsky_pro_cron_status', array(
                'start_time' => $this->start_time,
                'status' => 'running',
                'message' => '任务正在执行中'
            ));
            
            // 记录开始时间
            update_option('lsky_pro_cron_last_run', $this->start_time);
            
            // 写入日志
            $log_file = WP_CONTENT_DIR . '/lsky-pro-cron.log';
            $log_content = date('Y-m-d H:i:s') . " - Cron task started\n";
            file_put_contents($log_file, $log_content, FILE_APPEND);
            
            echo "LskyPro: 开始处理外链图片\n";
            
            // 获取选项设置
            $options = get_option('lsky_pro_options');
            if (empty($options['process_remote_images'])) {
                echo "LskyPro: 远程图片处理未启用\n";
                return false;
            }
            
            // 获取所有已发布的文章
            $posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'DESC'
            ));
            
            $this->results['total'] = count($posts);
            echo "找到 {$this->results['total']} 篇文章需要处理\n";
            
            foreach ($posts as $post) {
                echo "\n处理文章: {$post->post_title} (ID: {$post->ID})\n";
                
                try {
                    $result = $this->remote->process_post_images($post->ID);
                    if ($result) {
                        $results = $this->remote->get_results();
                        $this->results['processed'] += $results['processed'];
                        $this->results['failed'] += $results['failed'];
                        
                        $this->results['details'][] = array(
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'status' => 'success',
                            'processed' => $results['processed'],
                            'failed' => $results['failed']
                        );
                        
                        echo "处理完成 - 成功: {$results['processed']}, 失败: {$results['failed']}\n";
                    } else {
                        $this->results['details'][] = array(
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'status' => 'error',
                            'error' => $this->remote->getError()
                        );
                        
                        echo "处理失败 - " . $this->remote->getError() . "\n";
                    }
                } catch (Exception $e) {
                    $this->results['details'][] = array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    );
                    
                    echo "处理异常 - " . $e->getMessage() . "\n";
                }
            }
            
            $this->output_results();
            
            // 处理完成后更新状态
            update_option('lsky_pro_cron_status', array(
                'start_time' => $this->start_time,
                'end_time' => time(),
                'status' => 'completed',
                'message' => sprintf(
                    '处理完成，共处理 %d 篇文章，%d 张图片成功，%d 张图片失败',
                    $this->results['total'],
                    $this->results['processed'],
                    $this->results['failed']
                ),
                'results' => $this->results
            ));
            
            // 记录完成状态
            $log_content = date('Y-m-d H:i:s') . " - Cron task completed successfully\n";
            file_put_contents($log_file, $log_content, FILE_APPEND);
            
            return true;
        } catch (Exception $e) {
            // 更新错误状态
            update_option('lsky_pro_cron_status', array(
                'start_time' => $this->start_time,
                'end_time' => time(),
                'status' => 'error',
                'message' => $e->getMessage(),
                'error_details' => array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                )
            ));
            
            // 记录错误
            $log_content = date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
            file_put_contents($log_file, $log_content, FILE_APPEND);
            
            return false;
        }
    }
    
    private function output_results() {
        $output = sprintf(
            "\n处理完成\n总计: %d 篇文章\n成功: %d 张图片\n失败: %d 张图片\n\n详细信息:\n",
            $this->results['total'],
            $this->results['processed'],
            $this->results['failed']
        );
        
        foreach ($this->results['details'] as $detail) {
            $output .= sprintf(
                "文章: %s (ID: %d)\n状态: %s\n",
                $detail['title'],
                $detail['id'],
                $detail['status']
            );
            
            if ($detail['status'] === 'success') {
                $output .= sprintf(
                    "处理图片: %d 成功, %d 失败\n",
                    $detail['processed'],
                    $detail['failed']
                );
            } else {
                $output .= sprintf("错误: %s\n", $detail['error']);
            }
            
            $output .= "------------------------\n";
        }
        
        echo $output;
        error_log('LskyPro: ' . $output);
    }
}

// 修改验证方法
function verify_cron_status() {
    $status = get_option('lsky_pro_cron_status');
    $last_run = get_option('lsky_pro_cron_last_run');
    
    if (!$last_run) {
        return array(
            'status' => 'unknown',
            'message' => '计划任务尚未执行'
        );
    }
    
    // 如果状态显示正在运行，但已经超过30分钟，认为是异常
    if ($status['status'] === 'running' && (time() - $status['start_time']) > 1800) {
        return array(
            'status' => 'error',
            'message' => '任务执行时间过长，可能已经异常终止',
            'last_run' => date('Y-m-d H:i:s', $last_run)
        );
    }
    
    // 返回当前状态
    return array(
        'status' => $status['status'],
        'message' => $status['message'],
        'last_run' => date('Y-m-d H:i:s', $last_run),
        'details' => isset($status['results']) ? $status['results'] : null
    );
}

// 验证 Cron 密码
function verify_cron_password($password) {
    if (empty($password)) {
        error_log('Cron 密码为空');
        return false;
    }
    
    // 从 WordPress 选项中获取存储的密码
    $stored_password = get_option('lsky_pro_cron_password');
    if (empty($stored_password)) {
        error_log('未找到存储的 Cron 密码');
        return false;
    }
    
    // 验证密码
    if (wp_check_password($password, $stored_password)) {
        return true;
    }
    
    error_log('Cron 密码验证失败');
    return false;
}

// 在CLI模式下执行
if (php_sapi_name() === 'cli') {
    // 解析命令行参数
    $password = '';
    foreach ($argv as $arg) {
        if (strpos($arg, '--password=') === 0) {
            $password = substr($arg, 11);
            break;
        }
    }
    
    // 验证密码
    if (!verify_cron_password($password)) {
        die("错误：无效的密码或未提供密码\n");
    }
    
    // 如果密码验证通过，继续执行
    $processor = new LskyProImageProcessor();
    $processor->process();
} 