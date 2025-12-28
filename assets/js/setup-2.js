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
            button.textContent = '正在完成设置...';
            
            console.log('开始完成设置...');
            console.log('发送数据:', {
                action: 'lsky_pro_setup_2_complete',
                nonce: lskyProSetup2Data.nonce
            });
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'lsky_pro_setup_2_complete',
                    nonce: lskyProSetup2Data.nonce
                },
                success: (response) => {
                    console.log('收到响应:', response);
                    
                    if (response.success) {
                        this.showToast(response.data.message || '设置完成！');
                        
                        // 添加调试日志
                        console.log('准备跳转到:', response.data.redirect || lskyProSetup2Data.settingsUrl);
                        
                        // 确保在 toast 显示后再跳转
                        setTimeout(() => {
                            const redirectUrl = response.data.redirect || lskyProSetup2Data.settingsUrl;
                            console.log('执行跳转到:', redirectUrl);
                            window.location.replace(redirectUrl); // 使用 replace 而不是 href
                        }, 1500);
                    } else {
                        this.showToast(response.data.message || '设置失败', 'error');
                        console.error('Setup failed:', response.data);
                        button.disabled = false;
                        button.textContent = '我已完成设置';
                    }
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    console.error('Ajax error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    
                    this.showToast('请求失败，请重试: ' + (errorThrown || textStatus), 'error');
                    button.disabled = false;
                    button.textContent = '我已完成设置';
                }
            });
        },
        
        addLog(message, type = 'info') {
            const now = new Date();
            const time = now.toLocaleTimeString();
            this.logs.push({
                time,
                message,
                type
            });
            
            // 自动滚动到底部
            this.$nextTick(() => {
                if (this.$refs.logContent) {
                    this.$refs.logContent.scrollTop = this.$refs.logContent.scrollHeight;
                }
            });
        },
        
        clearLogs() {
            this.cronLogs = [];
        },
        
        async testCron() {
            if (this.testing) return;
            
            this.testing = true;
            this.addLog('开始检测 Cron 运行状态...');
            
            try {
                const response = await jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lsky_pro_test_cron',
                        nonce: lskyProSetup2Data.cronTestNonce
                    }
                });
                
                if (response.success) {
                    this.cronStatus = {
                        status: 'success',
                        message: response.data.message,
                        lastRun: response.data.lastRun
                    };
                    this.addLog(`Cron ${response.data.message}（上次运行：${response.data.lastRun}）`, 'success');
                } else {
                    this.cronStatus = {
                        status: 'error',
                        message: response.data.message,
                        details: response.data.details
                    };
                    this.addLog(response.data.message, 'error');
                    if (response.data.details) {
                        this.addLog(response.data.details, 'error');
                    }
                }
            } catch (error) {
                this.cronStatus = {
                    status: 'error',
                    message: 'Cron 测试失败',
                    details: '请检查服务器设置'
                };
                this.addLog('Cron 测试失败，请检查服务器设置', 'error');
            } finally {
                this.testing = false;
            }
        },
        
        async checkStatus() {
            if (this.checking) return;
            
            this.checking = true;
            this.statusResult = null; // 重置状态
            
            try {
                const response = await jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lsky_pro_test_cron',
                        nonce: lskyProSetup2Data.cronTestNonce
                    }
                });
                
                if (response.success) {
                    this.statusResult = {
                        status: 'success',
                        message: `Cron 运行正常（上次执行：${response.data.lastRun}）`
                    };
                } else {
                    this.statusResult = {
                        status: 'error',
                        message: response.data.message || 'Cron 似乎没有正常运行'
                    };
                }
            } catch (error) {
                this.statusResult = {
                    status: 'error',
                    message: '检测失败，请稍后重试'
                };
            } finally {
                this.checking = false;
            }
        },
        
        async saveCronPassword() {
            if (!this.cronPassword) return;
            
            this.savingPassword = true;
            
            try {
                const response = await jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lsky_pro_save_cron_password',
                        nonce: lskyProSetup2Data.cronPasswordNonce,
                        password: this.cronPassword
                    }
                });
                
                if (response.success) {
                    this.showToast(this.i18n.passwordSaved);
                    this.savedPassword = this.cronPassword;
                    this.cronPassword = '';
                    this.updateCronCommand();
                } else {
                    this.showToast(response.data.message || this.i18n.passwordError, 'error');
                }
            } catch (error) {
                this.showToast(this.i18n.passwordError, 'error');
            } finally {
                this.savingPassword = false;
            }
        },
        async loadLogs() {
            try {
                if (!lskyProSetup2Data || !lskyProSetup2Data.cronLogsNonce) {
                    throw new Error('配置数据未正确加载');
                }
                
                const response = await jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'lsky_pro_get_cron_logs',
                        nonce: lskyProSetup2Data.cronLogsNonce
                    }
                });
                
                console.log('Load logs response:', response);
                
                if (response.success && response.data && response.data.logs) {
                    this.logs = response.data.logs;
                } else {
                    throw new Error(response.data?.message || '获取日志失败');
                }
            } catch (error) {
                console.error('加载日志失败:', error);
                this.addLog(`加载日志失败: ${error.message || '未知错误'}`, 'error');
            }
        },
        togglePasswordVisibility() {
            this.passwordVisible = !this.passwordVisible;
            const passwordInput = document.getElementById('cronPassword');
            passwordInput.type = this.passwordVisible ? 'text' : 'password';
        },
        updateCronCommand() {
            let command = initialCommand || 'php /path/to/your/cron.php';
            
            if (this.savedPassword) {
                command += ' --password=' + this.savedPassword;
            }
            
            this.cronCommand = command;
        },
        async getSavedPassword() {
            try {
                const response = await jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lsky_pro_get_cron_password',
                        nonce: lskyProSetup2Data.cronPasswordNonce
                    }
                });
                
                if (response.success && response.data.password) {
                    this.savedPassword = response.data.password;
                    this.updateCronCommand();
                }
            } catch (error) {
                console.error('获取密码失败:', error);
            }
        }
    },
    created() {
        // 获取已保存的密码
        this.getSavedPassword();
    },
    mounted() {
        // 页面加载时自动检查一次状态
        this.checkStatus();
    },
    watch: {
        cronPassword: function(newVal) {
            this.updateCronCommand();
        }
    }
}).mount('#app');