jQuery(document).ready(function($) {
    const vueBatchActive = (typeof window !== 'undefined' && window.__LSKY_BATCH_VUE_ACTIVE__ === true);

    const ajaxEndpoint = (typeof lskyProData !== 'undefined' && lskyProData && lskyProData.ajaxurl)
        ? lskyProData.ajaxurl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : null);

    if (!ajaxEndpoint) {
        if ($('#user-info').length) {
            $('#user-info').html('<div class="alert alert-danger">未找到 AJAX 地址（ajaxurl）</div>');
        }
        return;
    }

    // 初始化 Bootstrap 工具提示和模态框
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    const progressModalEl = document.getElementById('progressModal');
    const progressModal = (vueBatchActive || !progressModalEl) ? null : new bootstrap.Modal(progressModalEl);

    // 批量处理状态（Vue 接管时不启用旧逻辑）
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
                        const skippedStatuses = ['restricted_skipped', 'excluded_skipped', 'excluded', 'avatar_skipped', 'avatar_marked_skipped'];
                        if (item.status && skippedStatuses.indexOf(item.status) !== -1) {
                            addLog(`此图片为标记图片，跳过处理: ${item.original}`, 'success');
                            return;
                        }
                        if (item.success) {
                            if (!item.new_url) {
                                addLog(`此图片为标记图片，跳过处理: ${item.original}`, 'success');
                            } else {
                                addLog(`处理成功: ${item.original} -> ${item.new_url}`, 'success');
                            }
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
                    const statusOk = userInfoResp && (userInfoResp.status === 'success');
                    if (!userInfoResp || !statusOk) {
                        const msg = userInfoResp && userInfoResp.message ? userInfoResp.message : '获取用户信息失败';
                        $('#user-info').html('<div class="alert alert-danger">' + msg + '</div>');
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

                    let html = '';
                    html += '<div class="mb-3">';
                    html += '  <div style="font-weight:600; font-size:16px; color:#1e293b;">' + displayName + '</div>';
                    html += '  <div style="color:#64748b; font-size:13px;">账号信息概览</div>';
                    html += '</div>';
                    html += '<table class="user-info-table">';
                    html += '<tr><td><strong>用户名</strong></td><td>' + displayName + '</td></tr>';
                    html += '<tr><td><strong>邮箱</strong></td><td>' + (info.email || '未知') + '</td></tr>';

                    // v2: used_storage/total_storage（通常为 KB）
                    if (info.used_storage !== undefined && info.total_storage !== undefined) {
                        const usedBytes = Number(info.used_storage) * 1024;
                        const totalBytes = Number(info.total_storage) * 1024;
                        const percentage = totalBytes > 0 ? ((usedBytes / totalBytes) * 100).toFixed(2) : '0.00';
                        html += '<tr><td><strong>已使用空间：</strong></td><td>' + formatSize(usedBytes) + ' / ' + formatSize(totalBytes) + ' (' + percentage + '%)</td></tr>';
                    } else if (info.total_storage !== undefined) {
                        html += '<tr><td><strong>总容量：</strong></td><td>' + formatSize(Number(info.total_storage) * 1024) + '</td></tr>';
                    }

                    html += '<tr><td><strong>图片数量</strong></td><td>' + (info.photo_count !== undefined ? info.photo_count : '0') + '</td></tr>';
                    html += '<tr><td><strong>相册数量</strong></td><td>' + (info.album_count !== undefined ? info.album_count : '0') + '</td></tr>';
                    if (info.url) {
                        html += '<tr><td><strong>个人主页</strong></td><td><a href="' + info.url + '" target="_blank" rel="noopener">' + info.url + '</a></td></tr>';
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

    const hasBatchUI = $('#start-media-batch').length || $('#start-post-batch').length;
    if (!vueBatchActive && hasBatchUI) {
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
            if (progressModal) progressModal.show();
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
            if (progressModal) progressModal.show();
            addLog('开始处理文章图片...');
            processBatch('post');
        });

        // 停止处理按钮事件
        $('#stop-media-batch, #stop-post-batch').click(function() {
            $(this).prop('disabled', true);
            addLog('正在停止处理...');
            shouldStop = true;
        });

        // 重置文章批处理进度（从头开始）
        $('#reset-post-batch').click(function() {
            if (isProcessing) {
                addLog('正在处理中，无法重置进度', 'error');
                return;
            }

            const ok = window.confirm('确定要重置“文章图片处理”的进度吗？重置后下次将从头开始扫描文章。');
            if (!ok) return;

            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_reset_post_batch',
                    nonce: (lskyProData && lskyProData.batchNonce) ? lskyProData.batchNonce : ''
                },
                success: function(response) {
                    if (!response || !response.success) {
                        const msg = response && response.data && response.data.message ? response.data.message : (response && response.data ? response.data : '重置失败');
                        addLog(msg, 'error');
                        return;
                    }

                    // 重置进度条显示
                    $('#post-batch-progress .progress-bar').css('width', '0%');
                    addLog(response.data && response.data.message ? response.data.message : '已重置文章批处理进度', 'success');
                },
                error: function(xhr, status, error) {
                    addLog('请求失败，请重试: ' + (error || status), 'error');
                }
            });
        });

        // 重置媒体库批处理进度（从头开始）
        $('#reset-media-batch').click(function() {
            if (isProcessing) {
                addLog('正在处理中，无法重置进度', 'error');
                return;
            }

            const ok = window.confirm('确定要重置“媒体库图片处理”的进度吗？这会清除已上传图片的图床记录，下次将重新上传，可能产生重复图片。');
            if (!ok) return;

            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_reset_media_batch',
                    nonce: (lskyProData && lskyProData.batchNonce) ? lskyProData.batchNonce : ''
                },
                success: function(response) {
                    if (!response || !response.success) {
                        const msg = response && response.data && response.data.message ? response.data.message : (response && response.data ? response.data : '重置失败');
                        addLog(msg, 'error');
                        return;
                    }

                    $('#media-batch-progress .progress-bar').css('width', '0%');
                    addLog(response.data && response.data.message ? response.data.message : '已重置媒体库批处理进度', 'success');
                },
                error: function(xhr, status, error) {
                    addLog('请求失败，请重试: ' + (error || status), 'error');
                }
            });
        });

        // 模态框关闭事件
        $('#progressModal').on('hidden.bs.modal', function () {
            if (isProcessing) {
                if (progressModal) progressModal.show();
            }
        });
    }

    // 检查更新函数
    function checkUpdate() {
        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            data: {
                // 更新检查功能已移除
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

    // 加载用户信息（仅概览页存在）
    if ($('#user-info').length) {
        loadInfo();
    }

    // 在页面加载时检查更新（Vue 接管时由 Vue 触发）
    if (!vueBatchActive && $('#current-version').length && $('#version-status').length) {
        checkUpdate();
    }
}); 