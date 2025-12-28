// 移除 Vue：使用 jQuery 驱动 setup-2 页面交互
jQuery(document).ready(function ($) {
    const data = (typeof window.lskyProSetup2Data !== 'undefined') ? window.lskyProSetup2Data : null;

    function showNotice(type, message) {
        const $ok = $('#lsky-setup2-notice');
        const $err = $('#lsky-setup2-error');
        $ok.hide().text('');
        $err.hide().text('');
        if (type === 'success') {
            $ok.text(message).show();
        } else {
            $err.text(message).show();
        }
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            try {
                const $tmp = $('<textarea>').val(text).css({ position: 'fixed', left: '-9999px', top: '-9999px' });
                $('body').append($tmp);
                $tmp[0].focus();
                $tmp[0].select();
                const ok = document.execCommand('copy');
                $tmp.remove();
                ok ? resolve() : reject(new Error('copy failed'));
            } catch (e) {
                reject(e);
            }
        });
    }

    $('#lsky-copy-command').on('click', function () {
        const text = $('#lsky-cron-command').text().trim();
        if (!text) return;
        copyText(text)
            .then(function () {
                showNotice('success', '命令已复制');
            })
            .catch(function () {
                showNotice('error', '复制失败，请手动复制');
            });
    });

    $('#lsky-toggle-password').on('click', function () {
        const $input = $('#lsky-cron-password');
        const isPwd = $input.attr('type') === 'password';
        $input.attr('type', isPwd ? 'text' : 'password');
        $(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
    });

    $('#lsky-save-cron-password').on('click', function () {
        if (!data) return;
        const password = $('#lsky-cron-password').val().trim();
        $('#lsky-password-error').hide().text('');
        $('#lsky-password-saved').hide();
        if (!password) {
            $('#lsky-password-error').text('密码不能为空').show();
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text(data.i18n?.savingPassword || '正在保存...');

        $.post(data.ajaxurl, {
            action: 'lsky_pro_save_cron_password',
            nonce: data.cronPasswordNonce,
            password: password
        })
            .done(function (res) {
                if (res && res.success) {
                    $('#lsky-password-saved').show();
                    showNotice('success', res.data?.message || data.i18n?.passwordSaved || '密码保存成功');
                } else {
                    const msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data) ? res.data : (data.i18n?.passwordError || '密码保存失败');
                    $('#lsky-password-error').text(msg).show();
                    showNotice('error', msg);
                }
            })
            .fail(function () {
                const msg = data.i18n?.passwordError || '密码保存失败';
                $('#lsky-password-error').text(msg).show();
                showNotice('error', msg);
            })
            .always(function () {
                $btn.prop('disabled', false).text(data.i18n?.savePassword || '保存密码');
            });
    });

    $('#lsky-test-cron').on('click', function () {
        if (!data) return;
        const $btn = $(this);
        const $status = $('#lsky-cron-status');
        $btn.prop('disabled', true).text('检测中...');
        $status.hide().removeClass('success error').text('');

        $.post(data.ajaxurl, {
            action: 'lsky_pro_test_cron',
            nonce: data.cronTestNonce
        })
            .done(function (res) {
                if (res && res.success) {
                    $('#lsky-last-run').text(res.data?.lastRun || data.lastRun || '未知');
                    $status.addClass('success').text(res.data?.message || 'Cron 运行正常').show();
                    showNotice('success', res.data?.message || 'Cron 运行正常');
                } else {
                    $('#lsky-last-run').text(res?.data?.lastRun || data.lastRun || '未知');
                    const msg = res?.data?.message || res?.data || 'Cron 似乎没有正常运行';
                    const details = res?.data?.details ? ('\n' + res.data.details) : '';
                    $status.addClass('error').text(msg + details).show();
                    showNotice('error', msg);
                }
            })
            .fail(function () {
                $status.addClass('error').text('检测失败，请稍后重试').show();
                showNotice('error', '检测失败，请稍后重试');
            })
            .always(function () {
                $btn.prop('disabled', false).text('检测运行状态');
            });
    });

    $('#lsky-refresh-logs').on('click', function () {
        if (!data) return;
        const $btn = $(this);
        const $logs = $('#lsky-logs');
        const $no = $('#lsky-no-logs');

        $btn.prop('disabled', true).text('加载中...');
        $logs.hide().empty();
        $no.show();

        $.post(data.ajaxurl, {
            action: 'lsky_pro_get_cron_logs',
            nonce: data.cronLogsNonce
        })
            .done(function (res) {
                if (res && res.success && Array.isArray(res.data?.logs) && res.data.logs.length) {
                    res.data.logs.forEach(function (log) {
                        const time = log.time ? String(log.time) : '';
                        const msg = log.message ? String(log.message) : '';
                        $logs.append(
                            $('<div>').addClass('log-item')
                                .append($('<span>').addClass('log-time').text(time))
                                .append($('<span>').addClass('log-message').text(msg))
                        );
                    });
                    $no.hide();
                    $logs.show();
                } else {
                    $no.show();
                }
            })
            .fail(function () {
                showNotice('error', '获取日志失败');
            })
            .always(function () {
                $btn.prop('disabled', false).text('刷新日志');
            });
    });

    $('#completeSetup').on('click', function () {
        if (!data) return;
        const $btn = $(this);
        $btn.prop('disabled', true).text('处理中...');

        $.post(data.ajaxurl, {
            action: 'lsky_pro_setup_2_complete',
            nonce: data.nonce
        })
            .done(function (res) {
                if (res && res.success) {
                    showNotice('success', res.data?.message || '设置完成');
                    const redirect = res.data?.redirect || data.settingsUrl;
                    if (redirect) {
                        window.location.href = redirect;
                    }
                } else {
                    const msg = res?.data?.message || res?.data || '设置失败';
                    showNotice('error', msg);
                    $btn.prop('disabled', false).text('我已完成设置');
                }
            })
            .fail(function () {
                showNotice('error', '请求失败');
                $btn.prop('disabled', false).text('我已完成设置');
            });
    });
});
