<script>
    // 批量处理脚本
jQuery(document).ready(function($) {
    let isProcessing = false;
    let shouldStop = false;
    let currentTask = '';
    
    function updateProgress(data, type) {
        const selector = type === 'media' ? '#media-batch-progress' : '#post-batch-progress';
        const progress = (data.processed / data.total) * 100;
        $(`${selector} .progress`).css('width', progress + '%');
        $(`${selector} .processed`).text(data.processed);
        $(`${selector} .success`).text(data.success);
        $(`${selector} .failed`).text(data.failed);
        
        // 添加处理详情到日志
        if (data.processed_items && data.processed_items.length > 0) {
            data.processed_items.forEach(item => {
                let message;
                if (item.status === 'already_processed') {
                    message = `已处理: ${item.original} (已存在于图床)`;
                } else if (item.status === 'newly_processed') {
                    message = `处理成功: ${item.original} -> ${item.new_url}`;
                } else if (
                    item.status === 'restricted_skipped' ||
                    item.status === 'excluded_skipped' ||
                    item.status === 'excluded' ||
                    item.status === 'avatar_skipped' ||
                    item.status === 'avatar_marked_skipped'
                ) {
                    message = `此图片为标记图片，跳过处理: ${item.original}`;
                } else {
                    message = `处理失败: ${item.original} (${item.error})`;
                }
                addLog(message, item.success ? 'success' : 'error');
            });
        }
    }
    
    function addLog(message, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const className = type === 'error' ? 'error' : 
                         type === 'success' ? 'success' : 'info';
        $('#batch-log').show().find('.log-content').prepend(
            `<p class="${className}">[${time}] ${message}</p>`
        );
    }
    
    function processBatch(type) {
        if (shouldStop) {
            isProcessing = false;
            $(`#start-${type}-batch`).show();
            $(`#stop-${type}-batch`).hide();
            addLog('处理已停止');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: `lsky_pro_process_${type}_batch`,
                nonce: '<?php echo wp_create_nonce("lsky_pro_batch"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(response.data, type);
                    addLog(response.data.message);
                    
                    if (response.data.completed) {
                        isProcessing = false;
                        $(`#start-${type}-batch`).show();
                        $(`#stop-${type}-batch`).hide();
                        addLog(`${type === 'media' ? '媒体库' : '文章'}图片处理完成！`);
                    } else {
                        processBatch(type);
                    }
                } else {
                    addLog(response.data.message, 'error');
                    isProcessing = false;
                    $(`#start-${type}-batch`).show();
                    $(`#stop-${type}-batch`).hide();
                }
            },
            error: function() {
                addLog('请求失败，请重试', 'error');
                isProcessing = false;
                $(`#start-${type}-batch`).show();
                $(`#stop-${type}-batch`).hide();
            }
        });
    }
    
    // 媒体库处理
    $('#start-media-batch').click(function() {
        if (isProcessing) return;
        
        isProcessing = true;
        shouldStop = false;
        currentTask = 'media';
        $(this).hide();
        $('#stop-media-batch').show();
        $('#media-batch-progress').show();
        $('#batch-log').show();
        addLog('开始处理媒体库图片...');
        processBatch('media');
    });
    
    $('#stop-media-batch').click(function() {
        shouldStop = true;
        $(this).prop('disabled', true);
        addLog('正在停止处理...');
    });
    
    // 文章处理
    $('#start-post-batch').click(function() {
        if (isProcessing) return;
        
        isProcessing = true;
        shouldStop = false;
        currentTask = 'post';
        $(this).hide();
        $('#stop-post-batch').show();
        $('#post-batch-progress').show();
        $('#batch-log').show();
        addLog('开始处理文章图片...');
        processBatch('post');
    });
    
    $('#stop-post-batch').click(function() {
        shouldStop = true;
        $(this).prop('disabled', true);
        addLog('正在停止处理...');
    });
});
</script>

<style>
#batch-log .error { color: #dc3232; }
#batch-log .success { color: #46b450; }
#batch-log .info { color: #2271b1; }
</style> 