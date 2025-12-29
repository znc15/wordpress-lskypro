(function (window, document, $) {
    'use strict';

    function loadInfo(ajaxEndpoint) {
        $('#user-info').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">加载中...</span>
                </div>
                <p class="text-muted">正在加载账号信息...</p>
                <small class="text-muted d-block mt-2">如果加载时间过长，可能是网络问题</small>
            </div>
        `);

        // 设置加载超时提示
        var loadingTimeout = setTimeout(function() {
            if ($('#user-info .spinner-border').length > 0) {
                $('#user-info').html(`
                    <div class="text-center py-5">
                        <div class="spinner-border text-warning mb-3" role="status">
                            <span class="visually-hidden">加载中...</span>
                        </div>
                        <p class="text-warning fw-bold">加载时间较长，请稍候...</p>
                        <small class="text-muted">正在连接服务器，请检查网络连接</small>
                    </div>
                `);
            }
        }, 5000); // 5秒后显示警告

        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            timeout: 30000, // 30秒超时
            data: {
                action: 'lsky_pro_get_info',
                nonce: window.lskyProData ? window.lskyProData.nonce : ''
            },
            success: function (response) {
                clearTimeout(loadingTimeout); // 清除超时提示

                if (response.success) {
                    var userInfoResp = response.data && response.data.user_info ? response.data.user_info : null;
                    var statusOk = userInfoResp && (userInfoResp.status === 'success');
                    if (!userInfoResp || !statusOk) {
                        var msg = (userInfoResp && userInfoResp.message) ? userInfoResp.message : '获取用户信息失败';
                        showError(msg, ajaxEndpoint);
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

                    // 构建用户信息HTML
                    var html = '<div class="row g-4">';

                    // 基本信息卡片
                    html += '<div class="col-md-6">';
                    html += '  <div class="info-card">';
                    html += '    <div class="info-card-header">';
                    html += '      <i class="dashicons dashicons-admin-users"></i>';
                    html += '      <h3>基本信息</h3>';
                    html += '    </div>';
                    html += '    <div class="info-card-body">';
                    html += '      <div class="info-item">';
                    html += '        <span class="info-label">用户名</span>';
                    html += '        <span class="info-value">' + displayName + '</span>';
                    html += '      </div>';
                    html += '      <div class="info-item">';
                    html += '        <span class="info-label">邮箱</span>';
                    html += '        <span class="info-value">' + (info.email || '未设置') + '</span>';
                    html += '      </div>';
                    if (info.phone) {
                        html += '      <div class="info-item">';
                        html += '        <span class="info-label">手机号</span>';
                        html += '        <span class="info-value">' + info.phone + '</span>';
                        html += '      </div>';
                    }
                    if (info.created_at) {
                        var createdDate = new Date(info.created_at).toLocaleDateString('zh-CN');
                        html += '      <div class="info-item">';
                        html += '        <span class="info-label">注册时间</span>';
                        html += '        <span class="info-value">' + createdDate + '</span>';
                        html += '      </div>';
                    }
                    html += '    </div>';
                    html += '  </div>';
                    html += '</div>';

                    // 存储空间卡片
                    html += '<div class="col-md-6">';
                    html += '  <div class="info-card">';
                    html += '    <div class="info-card-header">';
                    html += '      <i class="dashicons dashicons-database"></i>';
                    html += '      <h3>存储空间</h3>';
                    html += '    </div>';
                    html += '    <div class="info-card-body">';

                    if (info.used_storage !== undefined && info.total_storage !== undefined) {
                        var usedBytes = Number(info.used_storage) * 1024;
                        var totalBytes = Number(info.total_storage);
                        var percentage = totalBytes > 0 ? ((usedBytes / totalBytes) * 100) : 0;
                        var percentageStr = percentage.toFixed(2);

                        html += '      <div class="storage-stats">';
                        html += '        <div class="storage-numbers">';
                        html += '          <span class="storage-used">' + formatSize(usedBytes) + '</span>';
                        html += '          <span class="storage-separator">/</span>';
                        html += '          <span class="storage-total">' + formatSize(totalBytes) + '</span>';
                        html += '        </div>';
                        html += '        <div class="storage-percentage">' + percentageStr + '%</div>';
                        html += '      </div>';
                        html += '      <div class="storage-progress">';
                        html += '        <div class="storage-progress-bar" style="width: ' + percentageStr + '%"></div>';
                        html += '      </div>';
                    } else {
                        html += '      <div class="info-item">';
                        html += '        <span class="info-label">存储信息</span>';
                        html += '        <span class="info-value">暂无数据</span>';
                        html += '      </div>';
                    }

                    html += '    </div>';
                    html += '  </div>';
                    html += '</div>';

                    // 统计信息卡片
                    html += '<div class="col-12">';
                    html += '  <div class="info-card">';
                    html += '    <div class="info-card-header">';
                    html += '      <i class="dashicons dashicons-chart-bar"></i>';
                    html += '      <h3>统计信息</h3>';
                    html += '    </div>';
                    html += '    <div class="info-card-body">';
                    html += '      <div class="stats-grid">';

                    var stats = [
                        { label: '照片数量', value: info.photo_count || 0, icon: 'dashicons-format-image' },
                        { label: '相册数量', value: info.album_count || 0, icon: 'dashicons-portfolio' },
                        { label: '分享数量', value: info.share_count || 0, icon: 'dashicons-share' },
                        { label: '订单数量', value: info.order_count || 0, icon: 'dashicons-cart' }
                    ];

                    stats.forEach(function(stat) {
                        html += '        <div class="stat-item">';
                        html += '          <div class="stat-icon"><i class="dashicons ' + stat.icon + '"></i></div>';
                        html += '          <div class="stat-content">';
                        html += '            <div class="stat-value">' + stat.value + '</div>';
                        html += '            <div class="stat-label">' + stat.label + '</div>';
                        html += '          </div>';
                        html += '        </div>';
                    });

                    html += '      </div>';
                    html += '    </div>';
                    html += '  </div>';
                    html += '</div>';

                    html += '</div>'; // 关闭 row

                    $('#user-info').html(html);
                } else {
                    clearTimeout(loadingTimeout);
                    showError(response.data || '加载失败', ajaxEndpoint);
                }
            },
            error: function (xhr, status, error) {
                clearTimeout(loadingTimeout);

                var errorMsg = '请求失败';
                if (status === 'timeout') {
                    errorMsg = '请求超时，服务器响应时间过长';
                } else if (status === 'error') {
                    errorMsg = '网络错误，请检查网络连接';
                } else if (error) {
                    errorMsg = '请求失败: ' + error;
                }

                showError(errorMsg, ajaxEndpoint);
            }
        });
    }

    function showError(message, ajaxEndpoint) {
        $('#user-info').html(`
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="dashicons dashicons-warning" style="font-size: 48px; color: #dc3232; width: 48px; height: 48px;"></i>
                </div>
                <h4 class="text-danger mb-3">加载失败</h4>
                <p class="text-muted mb-4">${message}</p>
                <button class="btn btn-primary" onclick="window.lskyProRetryLoad()">
                    <i class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></i>
                    重试
                </button>
            </div>
        `);

        // 设置全局重试函数
        window.lskyProRetryLoad = function() {
            loadInfo(ajaxEndpoint);
        };
    }

    $(function () {
        if (!$('#user-info').length) return;
        var api = window.LskyProAdmin || {};
        if (!api.ajaxEndpoint) return;
        loadInfo(api.ajaxEndpoint);
    });
})(window, document, jQuery);
