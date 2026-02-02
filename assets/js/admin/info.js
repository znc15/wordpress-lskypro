(function (window, document, $) {
    'use strict';

    function escapeHtml(value) {
        var str = value === null || value === undefined ? '' : String(value);
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatSize(bytes) {
        if (!bytes || Number(bytes) <= 0) return '0 B';
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

    function getGreeting() {
        var h = new Date().getHours();
        if (h < 6) return '凌晨好';
        if (h < 12) return '上午好';
        if (h < 18) return '下午好';
        return '晚上好';
    }

    function formatDateCN(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString('zh-CN');
    }

    function nowTimeHHMM() {
        return new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
    }

    function siteBaseFromApiUrl(apiUrl) {
        if (!apiUrl) return '';
        var u = String(apiUrl).replace(/\/+$/, '');
        // 约定：配置要求以 /api/v2 结尾
        return u.replace(/\/api\/v2$/i, '');
    }

    function pickFirstNumber(obj, paths) {
        for (var i = 0; i < paths.length; i++) {
            var p = paths[i];
            var cur = obj;
            var ok = true;
            for (var j = 0; j < p.length; j++) {
                if (!cur || typeof cur !== 'object' || cur[p[j]] === undefined || cur[p[j]] === null) {
                    ok = false;
                    break;
                }
                cur = cur[p[j]];
            }
            if (ok) {
                var n = Number(cur);
                if (!isNaN(n) && isFinite(n)) return n;
            }
        }
        return 0;
    }

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

                    // 读取 /group（旧代码里叫 strategies）用于上传限制/会员信息
                    var groupResp = response.data && response.data.strategies ? response.data.strategies : null;
                    var groupOk = groupResp && groupResp.status === 'success';
                    var groupData = groupOk ? (groupResp.data || {}) : {};
                    var group = groupData.group || {};
                    var groupOptions = group.options || {};

                    var displayName = info.name || info.username || '未知';
                    var greeting = getGreeting();
                    var safeName = escapeHtml(displayName);
                    var safeEmail = escapeHtml(info.email || '');

                    var apiUrl = (window.lskyProData && window.lskyProData.apiUrl) ? window.lskyProData.apiUrl : '';
                    var siteBase = siteBaseFromApiUrl(apiUrl);
                    var linkUpload = siteBase ? (siteBase + '/upload') : '#';
                    var linkAlbums = siteBase ? (siteBase + '/albums') : '#';
                    var linkShares = siteBase ? (siteBase + '/shares') : '#';
                    var linkProfile = siteBase ? (siteBase + '/profile') : '#';

                    // 邮箱是否已验证（字段名在不同版本可能不同，做宽松判断）
                    var emailVerified = false;
                    if (info.email_verified_at) emailVerified = true;
                    if (info.email_verified === true) emailVerified = true;
                    if (info.is_email_verified === true) emailVerified = true;

                    // 存储
                    var usedBytes = (info.used_storage !== undefined && info.used_storage !== null) ? (Number(info.used_storage) * 1024) : 0;
                    var totalBytes = (info.total_storage !== undefined && info.total_storage !== null) ? Number(info.total_storage) : 0;
                    var availableBytes = (totalBytes > 0 && usedBytes >= 0) ? Math.max(0, totalBytes - usedBytes) : 0;
                    var usagePercent = totalBytes > 0 ? Math.min(100, (usedBytes / totalBytes) * 100) : 0;
                    var usagePercentStr = usagePercent.toFixed(2);

                    // 上传限制（尽量从 group.options 中读取，找不到就显示 0/暂无）
                    var maxUploadKb = pickFirstNumber(groupOptions, [
                        ['max_upload_size'],
                        ['maxUploadSize'],
                        ['max_upload_kb']
                    ]);
                    var maxUploadBytes = maxUploadKb > 0 ? maxUploadKb * 1024 : 0;

                    var perMinute = pickFirstNumber(groupOptions, [
                        ['upload_per_minute'],
                        ['upload_limit_per_minute'],
                        ['per_minute']
                    ]);
                    var perDay = pickFirstNumber(groupOptions, [
                        ['upload_per_day'],
                        ['upload_limit_per_day'],
                        ['per_day']
                    ]);
                    var concurrent = pickFirstNumber(groupOptions, [
                        ['upload_concurrent'],
                        ['concurrent_uploads'],
                        ['concurrent']
                    ]);

                    var membershipName = (group && group.name) ? group.name : (info.group_name || info.group || '');
                    membershipName = membershipName ? String(membershipName) : '注册用户';

                    var createdDate = formatDateCN(info.created_at);
                    var lastLoginIp = info.last_login_ip || info.last_ip || info.login_ip || info.loginIp || '';

                    // 顶部 KPI（与截图一致的四项）
                    var kpis = [
                        { label: '图片', value: info.photo_count || 0, icon: 'dashicons-format-image', tone: 'blue' },
                        { label: '相册', value: info.album_count || 0, icon: 'dashicons-portfolio', tone: 'green' },
                        { label: '分享', value: info.share_count || 0, icon: 'dashicons-share', tone: 'purple' },
                        { label: '订单', value: info.order_count || 0, icon: 'dashicons-cart', tone: 'orange' }
                    ];

                    var html = '';
                    html += '<div class="lsky-overview">';

                    // 问候语
                    html += '  <div class="lsky-overview-greeting">';
                    html += '    <div class="lsky-overview-greeting-title">' + greeting + '，' + safeName + '</div>';
                    html += '    <div class="lsky-overview-greeting-sub">欢迎回到你的图床工作台</div>';
                    html += '  </div>';

                    // 邮箱未验证提示
                    if (safeEmail && !emailVerified) {
                        html += '  <div class="lsky-overview-alert">';
                        html += '    <div class="lsky-overview-alert-text">您的邮箱尚未验证，请验证邮箱以确保账号安全。</div>';
                        html += '    <a class="button button-primary" href="' + escapeHtml(linkProfile) + '" target="_blank" rel="noopener noreferrer">验证邮箱</a>';
                        html += '  </div>';
                    }

                    // KPI
                    html += '  <div class="lsky-overview-kpis">';
                    kpis.forEach(function (kpi) {
                        html += '    <div class="lsky-kpi">';
                        html += '      <div class="lsky-kpi-icon tone-' + kpi.tone + '"><i class="dashicons ' + kpi.icon + '"></i></div>';
                        html += '      <div class="lsky-kpi-main">';
                        html += '        <div class="lsky-kpi-value">' + escapeHtml(kpi.value) + '</div>';
                        html += '        <div class="lsky-kpi-label">' + escapeHtml(kpi.label) + '</div>';
                        html += '      </div>';
                        html += '    </div>';
                    });
                    html += '  </div>';

                    // 下方两列
                    html += '  <div class="row g-4">';

                    // 左列：存储 + 上传限制
                    html += '    <div class="col-lg-6">';
                    html += '      <div class="info-card">';
                    html += '        <div class="info-card-header"><i class="dashicons dashicons-database"></i><h3>储存与上传限制</h3></div>';
                    html += '        <div class="info-card-body">';
                    html += '          <div class="lsky-storage-grid">';
                    html += '            <div class="lsky-storage-item"><div class="lsky-storage-label">已用</div><div class="lsky-storage-value">' + escapeHtml(formatSize(usedBytes)) + '</div></div>';
                    html += '            <div class="lsky-storage-item"><div class="lsky-storage-label">可用</div><div class="lsky-storage-value">' + escapeHtml(formatSize(availableBytes)) + '</div></div>';
                    html += '            <div class="lsky-storage-item"><div class="lsky-storage-label">合计</div><div class="lsky-storage-value">' + escapeHtml(formatSize(totalBytes)) + '</div></div>';
                    html += '          </div>';
                    html += '          <div class="lsky-storage-progress">';
                    html += '            <div class="lsky-storage-progress-bar" style="width:' + escapeHtml(usagePercentStr) + '%"></div>';
                    html += '          </div>';
                    html += '          <div class="lsky-storage-foot">' + escapeHtml(usagePercentStr) + '% 已使用</div>';

                    html += '          <div class="lsky-section-split">';
                    html += '            <div class="lsky-section-title">上传限制</div>';
                    html += '            <div class="lsky-limit-grid">';
                    html += '              <div class="lsky-limit">';
                    html += '                <div class="lsky-limit-icon tone-blue"><i class="dashicons dashicons-media-default"></i></div>';
                    html += '                <div class="lsky-limit-main"><div class="lsky-limit-label">单文件大小</div><div class="lsky-limit-value">' + escapeHtml(maxUploadBytes ? formatSize(maxUploadBytes) : '暂无数据') + '</div></div>';
                    html += '              </div>';
                    html += '              <div class="lsky-limit">';
                    html += '                <div class="lsky-limit-icon tone-green"><i class="dashicons dashicons-clock"></i></div>';
                    html += '                <div class="lsky-limit-main"><div class="lsky-limit-label">每分钟上传</div><div class="lsky-limit-value">' + escapeHtml(perMinute ? (perMinute + ' 次') : '暂无数据') + '</div></div>';
                    html += '              </div>';
                    html += '              <div class="lsky-limit">';
                    html += '                <div class="lsky-limit-icon tone-purple"><i class="dashicons dashicons-calendar-alt"></i></div>';
                    html += '                <div class="lsky-limit-main"><div class="lsky-limit-label">每日上传</div><div class="lsky-limit-value">' + escapeHtml(perDay ? (perDay + ' 次') : '暂无数据') + '</div></div>';
                    html += '              </div>';
                    html += '              <div class="lsky-limit">';
                    html += '                <div class="lsky-limit-icon tone-orange"><i class="dashicons dashicons-admin-generic"></i></div>';
                    html += '                <div class="lsky-limit-main"><div class="lsky-limit-label">并发上传</div><div class="lsky-limit-value">' + escapeHtml(concurrent ? (concurrent + ' 个') : '暂无数据') + '</div></div>';
                    html += '              </div>';
                    html += '            </div>';
                    html += '          </div>';

                    html += '        </div>';
                    html += '      </div>';
                    html += '    </div>';

                    // 右列：会员等级 + 账户信息
                    html += '    <div class="col-lg-6">';
                    html += '      <div class="lsky-membership">';
                    html += '        <div class="lsky-membership-left">';
                    html += '          <div class="lsky-membership-label">会员等级</div>';
                    html += '          <div class="lsky-membership-name">' + escapeHtml(membershipName) + '</div>';
                    html += '        </div>';
                    html += '      </div>';

                    html += '      <div class="info-card lsky-mt">';
                    html += '        <div class="info-card-header"><i class="dashicons dashicons-id"></i><h3>账户信息</h3></div>';
                    html += '        <div class="info-card-body">';
                    html += '          <div class="lsky-account-grid">';
                    html += '            <div class="lsky-account-item"><div class="lsky-account-icon tone-blue"><i class="dashicons dashicons-admin-users"></i></div><div class="lsky-account-main"><div class="lsky-account-label">用户名</div><div class="lsky-account-value">' + safeName + '</div></div></div>';
                    html += '            <div class="lsky-account-item"><div class="lsky-account-icon tone-green"><i class="dashicons dashicons-email"></i></div><div class="lsky-account-main"><div class="lsky-account-label">邮箱</div><div class="lsky-account-value">' + (safeEmail || '未设置') + '</div></div></div>';
                    if (info.phone) {
                        html += '            <div class="lsky-account-item"><div class="lsky-account-icon tone-purple"><i class="dashicons dashicons-phone"></i></div><div class="lsky-account-main"><div class="lsky-account-label">手机号</div><div class="lsky-account-value">' + escapeHtml(info.phone) + '</div></div></div>';
                    }
                    html += '            <div class="lsky-account-item"><div class="lsky-account-icon tone-purple"><i class="dashicons dashicons-calendar"></i></div><div class="lsky-account-main"><div class="lsky-account-label">注册时间</div><div class="lsky-account-value">' + escapeHtml(createdDate || '暂无') + '</div></div></div>';
                    html += '            <div class="lsky-account-item"><div class="lsky-account-icon tone-orange"><i class="dashicons dashicons-location"></i></div><div class="lsky-account-main"><div class="lsky-account-label">最近登录 IP</div><div class="lsky-account-value">' + escapeHtml(lastLoginIp || '暂无') + '</div></div></div>';
                    html += '          </div>';
                    html += '        </div>';
                    html += '      </div>';
                    html += '    </div>';
                    html += '  </div>';

                    // 底部状态栏
                    html += '  <div class="lsky-overview-footer">';
                    html += '    <div class="lsky-overview-footer-left"><span class="lsky-dot"></span><span>系统运行正常</span></div>';
                    html += '    <div class="lsky-overview-footer-right">最后更新：' + escapeHtml(nowTimeHHMM()) + '</div>';
                    html += '  </div>';

                    html += '</div>';

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
        var safeMessage = escapeHtml(message);
        $('#user-info').html(`
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="dashicons dashicons-warning" style="font-size: 48px; color: #dc3232; width: 48px; height: 48px;"></i>
                </div>
                <h4 class="text-danger mb-3">加载失败</h4>
                <p class="text-muted mb-4">${safeMessage}</p>
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
