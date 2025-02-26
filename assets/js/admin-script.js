jQuery(document).ready(function($) {
    // 初始化 Bootstrap 工具提示和模态框
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    const progressModal = new bootstrap.Modal(document.getElementById('progressModal'));

    // 用户信息加载相关代码
    function loadInfo() {
        $('#user-info').html('<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中...</span></div></div>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lsky_pro_get_info',
                nonce: lskyProData.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.user_info && response.data.user_info.status) {
                        // 直接显示原始的用户信息HTML
                        $('#user-info').html(response.data.user_info.html);
                    } else {
                        $('#user-info').html('<div class="alert alert-danger">获取用户信息失败</div>');
                    }
                } else {
                    $('#user-info').html('<div class="alert alert-danger">' + response.data + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $('#user-info').html('<div class="alert alert-danger">请求失败: ' + error + '</div>');
            }
        });
    }

    // 开始处理媒体库图片
    $('#start-media-batch').click(function() {
        $(this).hide();
        $('#stop-media-batch').show();
        $('#media-batch-progress').show();
        $('.log-content').empty();
        progressModal.show();
        addLog('开始处理媒体库图片...');
        processBatch('media');
    });

    // 开始处理文章图片
    $('#start-post-batch').click(function() {
        $(this).hide();
        $('#stop-post-batch').show();
        $('#post-batch-progress').show();
        $('.log-content').empty();
        progressModal.show();
        addLog('开始处理文章图片...');
        processBatch('post');
    });

    // 停止处理按钮事件
    $('#stop-media-batch, #stop-post-batch').click(function() {
        $(this).prop('disabled', true);
        addLog('正在停止处理...');
        shouldStop = true;
    });

    // 模态框关闭事件
    $('#progressModal').on('hidden.bs.modal', function () {
        if (isProcessing) {
            progressModal.show();
        }
    });

    // 检查更新函数
    function checkUpdate() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lsky_pro_check_update',
                nonce: lskyProData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#current-version').text(data.current_version);
                    
                    if (data.has_update) {
                        $('#version-status').html(
                            '<span class="badge bg-warning">有新版本可用：' + 
                            data.latest_version + '</span>'
                        );
                        $('#release-notes').html(data.release_notes);
                        $('#download-link').attr('href', data.download_url);
                        $('#update-info').show();
                    } else {
                        $('#version-status').html(
                            '<span class="badge bg-success">已是最新版本</span>'
                        );
                        $('#update-info').hide();
                    }
                } else {
                    $('#version-status').html(
                        '<span class="badge bg-danger">检查更新失败</span>'
                    );
                }
            },
            error: function() {
                $('#version-status').html(
                    '<span class="badge bg-danger">检查更新失败</span>'
                );
            }
        });
    }

    // 加载用户信息
    loadInfo();

    // 在页面加载时检查更新
    checkUpdate();
}); 