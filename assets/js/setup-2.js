const app = Vue.createApp({
    data() {
        return {
            copied: false,
            testing: false,
            cronStatus: null,
            cronLogs: [],
            toast: {
                show: false,
                message: '',
                type: 'success',
                title: ''
            },
            cronPassword: '',
            passwordError: '',
            passwordSaved: false,
            savingPassword: false,
            logs: [],
            checking: false,
            statusResult: null,
            i18n: lskyProSetup2Data.i18n,
            passwordVisible: false,
            passwordStrength: 0,
            showCopyFeedback: false,
            cronCommand: initialCommand || 'php /path/to/your/cron.php',
            phpPath: '',
            cronScript: '',
            savedPassword: '',
        }
    },
    computed: {
        buttonText() {
            if (this.testing) {
                return '检测中...';
            }
            if (this.cronStatus && this.cronStatus.status === 'success') {
                return '运行正常';
            }
            return '检测运行状态';
        },
        passwordStrengthClass() {
            if (!this.cronPassword) return '';
            if (this.cronPassword.length < 8) return 'weak';
            if (this.cronPassword.length < 12) return 'medium';
            return 'strong';
        },
        passwordStrengthText() {
            if (!this.cronPassword) return '';
            if (this.cronPassword.length < 8) return '弱';
            if (this.cronPassword.length < 12) return '中';
            return '强';
        }
    },
    methods: {
        showToast(message, type = 'success') {
            this.toast.message = message;
            this.toast.type = type;
            this.toast.title = type === 'success' ? '嗯！' : '提示';
            this.toast.show = true;
            
            setTimeout(() => {
                this.toast.show = false;
            }, 3000);
        },
        
        copyCommand() {
            const commandText = document.querySelector('.command-text').innerText;
            
            // 检查命令是否为空
            if (!commandText || commandText.trim() === '') {
                console.error('命令为空，无法复制');
                return;
            }
            
            // 使用现代 Clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(commandText).then(() => {
                    this.showCopyFeedback = true;
                    setTimeout(() => {
                        this.showCopyFeedback = false;
                    }, 2000);
                }).catch(err => {
                    console.error('无法复制文本: ', err);
                    this.fallbackCopyCommand(commandText);
                });
            } else {
                // 兼容性处理
                this.fallbackCopyCommand(commandText);
            }
        },
        
        fallbackCopyCommand(text) {
            // 创建临时文本区域
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    this.showCopyFeedback = true;
                    setTimeout(() => {
                        this.showCopyFeedback = false;
                    }, 2000);
                }
            } catch (err) {
                console.error('回退复制方法失败: ', err);
            }
            
            document.body.removeChild(textArea);
        },
        
        completeSetup() {
            const button = document.getElementById('completeSetup');
            button.disabled = true;
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