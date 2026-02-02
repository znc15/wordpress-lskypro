(function (window, document, $) {
    'use strict';

    $(function () {
        var api = window.LskyProAdmin || {};
        var ajaxEndpoint = api.ajaxEndpoint;
        var vueBatchActive = api.vueBatchActive === true;

        var hasBatchUI = $('#start-media-batch').length || $('#start-post-batch').length;
        if (!ajaxEndpoint || vueBatchActive || !hasBatchUI) return;

        var progressModalEl = document.getElementById('progressModal');
        var progressModal = null;
        if (progressModalEl && typeof window.bootstrap !== 'undefined' && window.bootstrap && window.bootstrap.Modal) {
            progressModal = new window.bootstrap.Modal(progressModalEl);
        }

        var isProcessing = false;
        var shouldStop = false;
        var currentType = null;
        var lastSeqByType = { media: 0, post: 0 };
        var lastMessageByType = { media: null, post: null };

        function stopServerBatch(type) {
            if (!ajaxEndpoint || !type) return;
            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_stop_batch',
                    type: type,
                    nonce: (window.lskyProData && window.lskyProData.batchNonce) ? window.lskyProData.batchNonce : ''
                }
            });
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
                api.addLog('处理已停止');
                stopServerBatch(type);
                resetBatchUI(type);
                return;
            }

            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_process_' + type + '_batch',
                    nonce: (window.lskyProData && window.lskyProData.batchNonce) ? window.lskyProData.batchNonce : ''
                },
                success: function (response) {
                    if (!response || !response.success) {
                        var msg = (response && response.data && response.data.message) ? response.data.message : (response && response.data ? response.data : '处理失败');
                        api.addLog(msg, 'error');
                        resetBatchUI(type);
                        return;
                    }

                    var data = response.data || {};
                    api.setProgress(type, data);

                    // Async mode: avoid repeating the same batch logs when polling.
                    var isAsync = data && data.async === true;
                    var seq = isAsync ? Number(data.seq || 0) : null;
                    var isNewSeq = isAsync ? (seq && seq !== lastSeqByType[type]) : true;
                    if (isAsync && isNewSeq) {
                        lastSeqByType[type] = seq;
                    }

                    if (data && data.message) {
                        if (!isAsync) {
                            api.addLog(data.message);
                        } else if (isNewSeq || lastMessageByType[type] !== data.message) {
                            api.addLog(data.message);
                            lastMessageByType[type] = data.message;
                        }
                    }

                    if (data && Array.isArray(data.processed_items) && (!isAsync || isNewSeq)) {
                        data.processed_items.forEach(function (item) {
                            if (!item) return;
                            if (item.status === 'already_processed') {
                                api.addLog('已处理: ' + item.original + ' (已存在于图床)', 'success');
                                return;
                            }
                            var skippedStatuses = ['restricted_skipped', 'excluded_skipped', 'excluded', 'avatar_skipped', 'avatar_marked_skipped'];
                            if (item.status && skippedStatuses.indexOf(item.status) !== -1) {
                                api.addLog('此图片为标记图片，跳过处理: ' + item.original, 'success');
                                return;
                            }
                            if (item.success) {
                                if (!item.new_url) {
                                    api.addLog('此图片为标记图片，跳过处理: ' + item.original, 'success');
                                } else {
                                    api.addLog('处理成功: ' + item.original + ' -> ' + item.new_url, 'success');
                                }
                                return;
                            }
                            api.addLog('处理失败: ' + item.original + ' (' + (item.error || '未知错误') + ')', 'error');
                        });
                    }

                    if (data && data.completed) {
                        api.addLog((type === 'media' ? '媒体库' : '文章') + '图片处理完成！', 'success');
                        resetBatchUI(type);
                        return;
                    }

                    // Polling interval: async uses a small delay, sync continues immediately.
                    var delay = isAsync ? 1200 : 0;
                    setTimeout(function () { processBatch(type); }, delay);
                },
                error: function (xhr, status, error) {
                    api.addLog('请求失败，请重试: ' + (error || status), 'error');
                    resetBatchUI(type);
                }
            });
        }

        $('#start-media-batch').click(function () {
            if (isProcessing) return;
            isProcessing = true;
            currentType = 'media';
            shouldStop = false;
            lastSeqByType.media = 0;
            lastMessageByType.media = null;

            $(this).hide();
            $('#stop-media-batch').show();
            $('#media-batch-progress').show();
            $('.log-content').empty();
            if (progressModal) progressModal.show();
            api.addLog('开始处理媒体库图片...');
            processBatch('media');
        });

        $('#start-post-batch').click(function () {
            if (isProcessing) return;
            isProcessing = true;
            currentType = 'post';
            shouldStop = false;
            lastSeqByType.post = 0;
            lastMessageByType.post = null;

            $(this).hide();
            $('#stop-post-batch').show();
            $('#post-batch-progress').show();
            $('.log-content').empty();
            if (progressModal) progressModal.show();
            api.addLog('开始处理文章图片...');
            processBatch('post');
        });

        $('#stop-media-batch, #stop-post-batch').click(function () {
            $(this).prop('disabled', true);
            api.addLog('正在停止处理...');
            shouldStop = true;
            if (currentType) stopServerBatch(currentType);
        });

        $('#reset-post-batch').click(function () {
            if (isProcessing) {
                api.addLog('正在处理中，无法重置进度', 'error');
                return;
            }

            var ok = window.confirm('确定要重置“文章图片处理”的进度吗？重置后下次将从头开始扫描文章。');
            if (!ok) return;

            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_reset_post_batch',
                    nonce: (window.lskyProData && window.lskyProData.batchNonce) ? window.lskyProData.batchNonce : ''
                },
                success: function (response) {
                    if (!response || !response.success) {
                        var msg = (response && response.data && response.data.message) ? response.data.message : (response && response.data ? response.data : '重置失败');
                        api.addLog(msg, 'error');
                        return;
                    }

                    $('#post-batch-progress .progress-bar').css('width', '0%');
                    api.addLog((response.data && response.data.message) ? response.data.message : '已重置文章批处理进度', 'success');
                },
                error: function (xhr, status, error) {
                    api.addLog('请求失败，请重试: ' + (error || status), 'error');
                }
            });
        });

        $('#reset-media-batch').click(function () {
            if (isProcessing) {
                api.addLog('正在处理中，无法重置进度', 'error');
                return;
            }

            var ok = window.confirm(
                '确定要重置“媒体库图片处理”的进度吗？\n\n' +
                '这会清除已同步图片的图床 URL/PhotoId 以及跳过标记记录，下次将重新上传，可能产生重复图片。\n\n' +
                '提示：如果你开启了“上传后删除本地文件”，清除图床 URL 记录可能导致部分媒体暂时无法访问。'
            );
            if (!ok) return;

            $.ajax({
                url: ajaxEndpoint,
                type: 'POST',
                data: {
                    action: 'lsky_pro_reset_media_batch',
                    nonce: (window.lskyProData && window.lskyProData.batchNonce) ? window.lskyProData.batchNonce : ''
                },
                success: function (response) {
                    if (!response || !response.success) {
                        var msg = (response && response.data && response.data.message) ? response.data.message : (response && response.data ? response.data : '重置失败');
                        api.addLog(msg, 'error');
                        return;
                    }

                    $('#media-batch-progress .progress-bar').css('width', '0%');
                    api.addLog((response.data && response.data.message) ? response.data.message : '已重置媒体库批处理进度', 'success');
                },
                error: function (xhr, status, error) {
                    api.addLog('请求失败，请重试: ' + (error || status), 'error');
                }
            });
        });

        $('#progressModal').on('hidden.bs.modal', function () {
            // 允许用户关闭对话框；关闭即视为停止处理，避免反复自动弹出。
            if (isProcessing) {
                shouldStop = true;
                api.addLog('已关闭对话框，正在停止处理...');
                if (currentType) stopServerBatch(currentType);
            }
        });
    });
})(window, document, jQuery);
