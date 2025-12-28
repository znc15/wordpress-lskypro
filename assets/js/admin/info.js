(function (window, document, $) {
    'use strict';

    function loadInfo(ajaxEndpoint) {
        $('#user-info').html('<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中...</span></div></div>');

        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            data: {
                action: 'lsky_pro_get_info',
                nonce: window.lskyProData ? window.lskyProData.nonce : ''
            },
            success: function (response) {
                if (response.success) {
                    var userInfoResp = response.data && response.data.user_info ? response.data.user_info : null;
                    var statusOk = userInfoResp && (userInfoResp.status === 'success');
                    if (!userInfoResp || !statusOk) {
                        var msg = (userInfoResp && userInfoResp.message) ? userInfoResp.message : '获取用户信息失败';
                        $('#user-info').html('<div class="alert alert-danger">' + msg + '</div>');
                        return;
                    }

                    var info = userInfoResp.data || {};
                    var displayName = info.name || info.username || '未知';

                    function formatSize(bytes) {
                        if (!bytes) return '0 B';
                        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        var i = 0;
                        var size = Number(bytes);
                        while (size >= 1024 && i < units.length - 1) {
                            size /= 1024;
                            i++;
                        }
                        var decimalPlaces = size > 100 ? 0 : 2;
                        return size.toFixed(decimalPlaces) + ' ' + units[i];
                    }

                    var html = '';
                    html += '<div class="mb-3">';
                    html += '  <div style="font-weight:600; font-size:16px; color:#1e293b;">' + displayName + '</div>';
                    html += '  <div style="color:#64748b; font-size:13px;">账号信息概览</div>';
                    html += '</div>';
                    html += '<table class="user-info-table">';
                    html += '<tr><td><strong>用户名</strong></td><td>' + displayName + '</td></tr>';
                    html += '<tr><td><strong>邮箱</strong></td><td>' + (info.email || '未知') + '</td></tr>';

                    if (info.used_storage !== undefined && info.total_storage !== undefined) {
                        var usedBytes = Number(info.used_storage) * 1024;
                        var totalBytes = Number(info.total_storage) * 1024;
                        var percentage = totalBytes > 0 ? ((usedBytes / totalBytes) * 100).toFixed(2) : '0.00';
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
            error: function (xhr, status, error) {
                $('#user-info').html('<div class="alert alert-danger">请求失败: ' + error + '</div>');
            }
        });
    }

    $(function () {
        if (!$('#user-info').length) return;
        var api = window.LskyProAdmin || {};
        if (!api.ajaxEndpoint) return;
        loadInfo(api.ajaxEndpoint);
    });
})(window, document, jQuery);
