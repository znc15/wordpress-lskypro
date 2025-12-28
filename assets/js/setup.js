jQuery(document).ready(function ($) {
    const data = (typeof window.lskyProSetupData !== 'undefined') ? window.lskyProSetupData : null;

    function getToast() {
        const el = document.getElementById('lsky-setup-toast');
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Toast) return null;
        return bootstrap.Toast.getOrCreateInstance(el, { delay: 3000 });
    }

    function showToast(message, type) {
        const $toast = $('#lsky-setup-toast');
        const $title = $('#lsky-setup-toast-title');
        const $body = $('#lsky-setup-toast-body');

        $toast.removeClass('bg-success bg-danger text-white');
        if (type === 'success') {
            $toast.addClass('bg-success text-white');
            $title.text('成功');
        } else {
            $toast.addClass('bg-danger text-white');
            $title.text('提示');
        }

        $body.text(message);
        const toast = getToast();
        if (toast) toast.show();
    }

    // API URL 自动补全到 /api/v2
    $('#lsky-api-url').on('blur', function () {
        let url = String($(this).val() || '').trim();
        if (!url) return;

        if (!url.endsWith('/api/v2')) {
            if (!url.endsWith('/')) url += '/';
            if (!url.endsWith('api/v2')) url += 'api/v2';
            $(this).val(url);
        }
    });

    $('#lsky-pro-setup-form').on('submit', function (e) {
        e.preventDefault();
        if (!data) {
            showToast('初始化失败：缺少配置数据', 'error');
            return;
        }

        const apiUrl = String($('#lsky-api-url').val() || '').trim();
        const token = String($('#lsky-token').val() || '').trim();

        if (!apiUrl) {
            showToast('请输入 API 地址', 'error');
            return;
        }

        if (!token) {
            showToast('请输入 Token', 'error');
            return;
        }

        const $btn = $('#lsky-setup-submit');
        $btn.prop('disabled', true);
        $btn.find('.btn-text').text('保存中...');
        $btn.find('.spinner-border').removeClass('d-none');

        const formData = new FormData();
        formData.append('action', 'lsky_pro_setup');
        formData.append('setup_nonce', data.nonce);
        formData.append('api_url', apiUrl);
        formData.append('token', token);

        fetch(data.ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(async (res) => {
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch {
                    throw new Error('服务器返回格式错误');
                }

                if (!res.ok || json.success === false) {
                    throw new Error(json.data || '提交失败');
                }

                const message = (json.data && json.data.message) ? json.data.message : '配置保存成功！';
                showToast(message, 'success');

                if (json.data && json.data.redirect) {
                    setTimeout(() => {
                        window.location.href = json.data.redirect;
                    }, 800);
                }
            })
            .catch((err) => {
                showToast(err && err.message ? err.message : '提交失败，请重试', 'error');
            })
            .finally(() => {
                $btn.prop('disabled', false);
                $btn.find('.btn-text').text('保存配置');
                $btn.find('.spinner-border').addClass('d-none');
            });
    });
});
