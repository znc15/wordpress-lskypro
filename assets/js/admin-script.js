jQuery(document).ready(function($) {
    const ajaxEndpoint = (typeof lskyProData !== 'undefined' && lskyProData && lskyProData.ajaxurl)
        ? lskyProData.ajaxurl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : null);

    if (!ajaxEndpoint) {
        $('#user-info').html('<div class="alert alert-danger">未找到 AJAX 地址（ajaxurl）</div>');
        return;
    }

    // 初始化 Bootstrap 工具提示和模态框
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    const progressModal = new bootstrap.Modal(document.getElementById('progressModal'));

    // 批量处理状态
    let isProcessing = false;
    let shouldStop = false;
    let currentType = null;

    function addLog(message, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const className = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info');

        $('#batch-log').show();
        const $log = $('#batch-log').find('.log-content');
        $log.prepend(`<p class="${className}">[${time}] ${message}</p>`);
    }

    function setProgress(type, data) {
        const selector = type === 'media' ? '#media-batch-progress' : '#post-batch-progress';
        const total = Number(data.total || 0);
        const processed = Number(data.processed || 0);
        const progress = total > 0 ? Math.min(100, (processed / total) * 100) : 0;
        $(`${selector} .progress-bar`).css('width', progress + '%');
    }

    function resetBatchUI(type) {
        isProcessing = false;
        shouldStop = false;
        currentType = null;

        if (type === 'media') {
            $('#start-media-batch').show();
            $('#stop-media-batch').hide().prop('disabled', false);
        }
        if (type === 'post') {
            $('#start-post-batch').show();
            $('#stop-post-batch').hide().prop('disabled', false);
        }
    }

    function processBatch(type) {
        if (shouldStop) {
            addLog('处理已停止');
            resetBatchUI(type);
            return;
        }

        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            data: {
                action: `lsky_pro_process_${type}_batch`,
                nonce: (lskyProData && lskyProData.batchNonce) ? lskyProData.batchNonce : ''
            },
            success: function(response) {
                if (!response || !response.success) {
                    const msg = response && response.data && response.data.message ? response.data.message : (response && response.data ? response.data : '处理失败');
                    addLog(msg, 'error');
                    resetBatchUI(type);
                    return;
                }

                setProgress(type, response.data);
                if (response.data && response.data.message) {
                    addLog(response.data.message);
                }

                if (response.data && Array.isArray(response.data.processed_items)) {
                    response.data.processed_items.forEach(item => {
                        if (!item) return;
                        if (item.status === 'already_processed') {
                            addLog(`已处理: ${item.original} (已存在于图床)`, 'success');
                            return;
                        }
                        if (item.success) {
                            addLog(`处理成功: ${item.original} -> ${item.new_url}`, 'success');
                            return;
                        }
                        addLog(`处理失败: ${item.original} (${item.error || '未知错误'})`, 'error');
                    });
                }

                if (response.data && response.data.completed) {
                    addLog(`${type === 'media' ? '媒体库' : '文章'}图片处理完成！`, 'success');
                    resetBatchUI(type);
                    return;
                }

                processBatch(type);
            },
            error: function(xhr, status, error) {
                addLog('请求失败，请重试: ' + (error || status), 'error');
                resetBatchUI(type);
            }
        });
    }

    // 用户信息加载相关代码
    function loadInfo() {
        $('#user-info').html('<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中...</span></div></div>');
        
        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            data: {
                action: 'lsky_pro_get_info',
                nonce: lskyProData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const userInfoResp = response.data && response.data.user_info ? response.data.user_info : null;
                    if (!userInfoResp || userInfoResp.status !== true) {
                        const msg = userInfoResp && userInfoResp.message ? userInfoResp.message : '获取用户信息失败';
                        $('#user-info').html('<div class="alert alert-danger">' + msg + '</div>');
                        return;
                    }

                    // 兼容旧结构：若后端提供 html，则直接渲染
                    if (userInfoResp.html) {
                        $('#user-info').html(userInfoResp.html);
                        return;
                    }

                    const info = userInfoResp.data || {};
                    const displayName = info.name || info.username || '未知';

                    function formatSize(bytes) {
                        if (!bytes) return '0 B';
                        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        let i = 0;
                        let size = Number(bytes);
                        while (size >= 1024 && i < units.length - 1) {
                            size /= 1024;
                            i++;
                        }
                        const decimalPlaces = size > 100 ? 0 : 2;
                        return size.toFixed(decimalPlaces) + ' ' + units[i];
                    }

                    let html = '<table class="widefat" style="background: transparent; border: none;">';
                    html += '<tr><td><strong>用户名：</strong></td><td>' + displayName + '</td></tr>';
                    html += '<tr><td><strong>邮箱：</strong></td><td>' + (info.email || '未知') + '</td></tr>';

                    // 有些接口返回 capacity/size 为 KB，有些返回 bytes，这里保持宽松兼容
                    if (info.size !== undefined && info.capacity !== undefined) {
                        const usedBytes = Number(info.size) * 1024;
                        const totalBytes = Number(info.capacity) * 1024;
                        const percentage = totalBytes > 0 ? ((usedBytes / totalBytes) * 100).toFixed(2) : '0.00';
                        html += '<tr><td><strong>已使用空间：</strong></td><td>' + formatSize(usedBytes) + ' / ' + formatSize(totalBytes) + ' (' + percentage + '%)</td></tr>';
                    } else if (info.capacity !== undefined) {
                        html += '<tr><td><strong>总容量：</strong></td><td>' + formatSize(info.capacity) + '</td></tr>';
                    }

                    html += '<tr><td><strong>图片数量：</strong></td><td>' + (info.image_num || '0') + '</td></tr>';
                    html += '<tr><td><strong>相册数量：</strong></td><td>' + (info.album_num || '0') + '</td></tr>';
                    if (info.url) {
                        html += '<tr><td><strong>个人主页：</strong></td><td><a href="' + info.url + '" target="_blank">' + info.url + '</a></td></tr>';
                    }
                    html += '</table>';

                    $('#user-info').html(html);
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
        if (isProcessing) return;
        isProcessing = true;
        currentType = 'media';
        shouldStop = false;

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
        if (isProcessing) return;
        isProcessing = true;
        currentType = 'post';
        shouldStop = false;

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
            url: ajaxEndpoint,
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