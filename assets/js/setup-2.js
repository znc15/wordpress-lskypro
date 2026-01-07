// 移除 Vue：使用 jQuery 驱动 setup-2 页面交互
jQuery(document).ready(function ($) {
    const data = (typeof window.lskyProSetup2Data !== 'undefined') ? window.lskyProSetup2Data : null;

    function showNotice(type, message) {
        const $ok = $('#lsky-setup2-notice');
        const $err = $('#lsky-setup2-error');
        // setup-2 已移除：此脚本保留为空壳，避免旧引用报错。
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
