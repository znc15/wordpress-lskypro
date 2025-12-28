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

                    api.setProgress(type, response.data);
                    if (response.data && response.data.message) {
                        api.addLog(response.data.message);
                    }

                    if (response.data && Array.isArray(response.data.processed_items)) {
                        response.data.processed_items.forEach(function (item) {
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

                    if (response.data && response.data.completed) {
                        api.addLog((type === 'media' ? '媒体库' : '文章') + '图片处理完成！', 'success');
                        resetBatchUI(type);
                        return;
                    }

                    processBatch(type);
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

            var ok = window.confirm('确定要重置“媒体库图片处理”的进度吗？这会清除已上传图片的图床记录，下次将重新上传，可能产生重复图片。');
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
            if (isProcessing && progressModal) {
                progressModal.show();
            }
        });
    });
})(window, document, jQuery);
