// 创建Vue应用
const { createApp, ref, onMounted, Transition } = Vue;
const { CircleCheckFilled, CircleCloseFilled } = ElementPlusIconsVue;

const LskyProSetup = {
    setup() {
        // 响应式状态
        const formData = ref({
            accountType: 'paid',
            apiUrl: '',
            token: '',
            email: '',
            password: ''
        });

        const errors = ref({});
        const loading = ref(false);
        
        // Toast 状态
        const toast = ref({
            show: false,
            message: '',
            type: 'error' // 'error' 或 'success'
        });

        // 添加 nonce ref 并尝试多种方式获取 nonce
        const nonce = ref('');
        const ajaxUrl = ref('');
        
        onMounted(() => {
            // 优先使用 wp_localize_script 传递的数据
            if (typeof lskyProData !== 'undefined') {
                nonce.value = lskyProData.nonce;
                ajaxUrl.value = lskyProData.ajaxurl;
            } else {
                // 后备方案：尝试从全局变量或DOM元素获取
                if (typeof lskyProNonce !== 'undefined') {
                    nonce.value = lskyProNonce;
                } else {
                    const nonceInput = document.querySelector('#lsky-pro-nonce');
                    if (nonceInput) {
                        nonce.value = nonceInput.value;
                    }
                }
                
                if (typeof ajaxurl !== 'undefined') {
                    ajaxUrl.value = ajaxurl;
                }
            }
            
            // 调试信息
            console.log('Nonce loaded:', nonce.value ? '是' : '否');
            console.log('Ajax URL loaded:', ajaxUrl.value ? '是' : '否');
        });

        // 显示 Toast
        const showToast = (message, type = 'error') => {
            toast.value.show = true;
            toast.value.message = message;
            toast.value.type = type;
            
            // 3秒后自动关闭
            setTimeout(() => {
                toast.value.show = false;
            }, 3000);
        };

        // 验证API URL
        const validateApiUrl = (url) => {
            if (!url) return;
            let newUrl = url.trim();
            
            if (!newUrl.endsWith('/api/v1')) {
                if (!newUrl.endsWith('/')) {
                    newUrl += '/';
                }
                if (!newUrl.endsWith('api/v1')) {
                    newUrl += 'api/v1';
                }
                formData.value.apiUrl = newUrl;
            }
        };

        // 表单验证
        const validateForm = () => {
            let isValid = true;
            
            if (!formData.value.apiUrl) {
                showToast('请输入API地址');
                isValid = false;
            }

            if (formData.value.accountType === 'paid' && !formData.value.token) {
                showToast('请输入Token');
                isValid = false;
            }

            if (formData.value.accountType === 'free') {
                if (!formData.value.email) {
                    showToast('请输入邮箱');
                    isValid = false;
                }
                if (!formData.value.password) {
                    showToast('请输入密码');
                    isValid = false;
                }
            }

            return isValid;
        };

        // 表单提交
        const handleSubmit = async (e) => {
            e.preventDefault();
            
            if (!validateForm()) {
                return;
            }

            if (!nonce.value) {
                showToast('安全验证失败：找不到 nonce', 'error');
                return;
            }

            loading.value = true;
            
            try {
                const formDataToSend = new FormData();
                formDataToSend.append('setup_nonce', nonce.value);
                formDataToSend.append('action', 'lsky_pro_setup');
                formDataToSend.append('account_type', formData.value.accountType);
                formDataToSend.append('api_url', formData.value.apiUrl);
                
                if (formData.value.accountType === 'paid') {
                    formDataToSend.append('token', formData.value.token);
                } else {
                    formDataToSend.append('email', formData.value.email);
                    formDataToSend.append('password', formData.value.password);
                }

                // 使用从 WordPress 获取的 AJAX URL
                const response = await fetch(ajaxUrl.value || ajaxurl, {
                    method: 'POST',
                    body: formDataToSend
                });

                const responseText = await response.text();
                let responseData;
                try {
                    responseData = JSON.parse(responseText);
                } catch (e) {
                    console.error('解析响应失败:', responseText);
                    throw new Error('服务器返回格式错误');
                }

                if (!response.ok || responseData.success === false) {
                    throw new Error(responseData.data || '提交失败');
                }

                showToast(responseData.data.message || '配置保存成功！', 'success');
                
                // 检查是否需要重定向
                if (responseData.data && responseData.data.redirect) {
                    setTimeout(() => {
                        window.location.href = responseData.data.redirect;
                    }, 1500);
                }
                
            } catch (error) {
                showToast(error.message || '提交失败，请重试', 'error');
            } finally {
                loading.value = false;
            }
        };

        return {
            formData,
            loading,
            toast,
            nonce,
            validateApiUrl,
            handleSubmit
        };
    },
    template: `
        <div class="wrap" style="max-width: 800px; margin: 20px auto; display: flex; justify-content: center; align-items: center; min-height: calc(100vh - 120px);">
            <!-- Toast 提示 -->
            <Transition
                name="slide-fade"
            >
                <div v-if="toast.show" 
                    style="
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        padding: 12px 24px;
                        border-radius: 8px;
                        background: white;
                        color: #333;
                        font-size: 14px;
                        z-index: 9999;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
                    "
                >
                    <svg v-if="toast.type === 'error'"
                        viewBox="0 0 1024 1024"
                        style="
                            width: 24px;
                            height: 24px;
                            color: #dc3545;
                            padding-right: 15px;
                        "
                    >
                        <path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896zm0 393.664L407.936 353.6a38.4 38.4 0 1 0-54.336 54.336L457.664 512 353.6 616.064a38.4 38.4 0 1 0 54.336 54.336L512 566.336 616.064 670.4a38.4 38.4 0 1 0 54.336-54.336L566.336 512 670.4 407.936a38.4 38.4 0 1 0-54.336-54.336L512 457.664z"/>
                    </svg>
                    <svg v-else
                        viewBox="0 0 1024 1024"
                        style="
                            width: 24px;
                            height: 24px;
                            color: #4CAF50;
                            padding-right: 15px;
                        "
                    >
                        <path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896zm-55.808 536.384-99.52-99.584a38.4 38.4 0 1 0-54.336 54.336l126.72 126.72a38.272 38.272 0 0 0 54.336 0l262.4-262.464a38.4 38.4 0 1 0-54.272-54.336L456.192 600.384z"/>
                    </svg>
                    <div style="display: flex; flex-direction: column;">
                        <span style="
                            font-weight: 500;
                            color: #333;
                            margin-bottom: 2px;
                        ">{{ toast.type === 'error' ? '提示' : '嗯！' }}</span>
                        <span style="
                            color: #666;
                            font-size: 13px;
                        ">{{ toast.message }}</span>
                    </div>
                </div>
            </Transition>

            <div class="card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); width: 100%;">
                <h2 style="margin-bottom: 20px; color: #1d2327;">LskyPro 图床配置</h2>
                
                <form @submit="handleSubmit" style="display: flex; flex-direction: column; gap: 20px;">
                    
                    <!-- 账户类型滑块切换 -->
                    <div class="account-type" style="background:rgb(255, 255, 255); padding: 20px; border-radius: 8px;">
                        <div style="display: flex; align-items: center; justify-content: center; position: relative;">
                            <!-- 滑块背景 -->
                            <div style="
                                position: relative;
                                width: 200px;
                                height: 36px;
                                background: #e9ecef;
                                border-radius: 18px;
                                padding: 3px;
                                cursor: pointer;
                            " @click="formData.accountType = formData.accountType === 'paid' ? 'free' : 'paid'">
                                <!-- 滑块 -->
                                <div style="
                                    position: absolute;
                                    width: 104px;
                                    height: 36px;
                                    background: white;
                                    border-radius: 15px;
                                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                                    transition: transform 0.3s ease;
                                    z-index: 1;
                                " :style="formData.accountType === 'paid' ? 'transform: translateX(0)' : 'transform: translateX(94px)'">
                                </div>
                                
                                <!-- 文字标签 -->
                                <div style="
                                    position: absolute;
                                    width: 100%;
                                    height: 100%;
                                    display: flex;
                                    justify-content: space-around;
                                    align-items: center;
                                    z-index: 2;
                                    transform: translateY(-2px);
                                ">
                                    <span style="
                                        flex: 1;
                                        text-align: center;
                                        font-size: 14px;
                                        transition: color 0.3s ease;
                                        transform: translateX(-1px);
                                    " :style="formData.accountType === 'paid' ? 'color: #2271b1' : 'color: #646970'">
                                        付费版
                                    </span>
                                    <span style="
                                        flex: 1;
                                        text-align: center;
                                        font-size: 14px;
                                        transition: color 0.3s ease;
                                        transform: translateX(-5px);
                                    " :style="formData.accountType === 'free' ? 'color: #2271b1' : 'color: #646970'">
                                        开源版
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 版本说明 -->
                        <div style="
                            margin-top: 15px;
                            text-align: center;
                            color: #646970;
                            font-size: 13px;
                        ">
                            {{ formData.accountType === 'paid' ? '付费版需要输入购买的授权Token' : '开源版需要输入注册的邮箱和密码' }}
                        </div>
                    </div>

                    <!-- API地址输入 -->
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">API 地址</label>
                        <input 
                            type="url" 
                            v-model="formData.apiUrl"
                            @blur="validateApiUrl(formData.apiUrl)"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                            placeholder="例如: https://img.example.com/api/v1"
                            autocomplete="url"
                        >
                    </div>

                    <!-- 付费版字段 -->
                    <div v-if="formData.accountType === 'paid'" class="form-group">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Token</label>
                        <input 
                            type="text" 
                            v-model="formData.token"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                            placeholder="请输入您的授权Token"
                            autocomplete="off"
                        >
                    </div>

                    <!-- 开源版字段 -->
                    <div v-if="formData.accountType === 'free'" style="display: flex; flex-direction: column; gap: 15px;">
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">邮箱</label>
                            <input 
                                type="email" 
                                v-model="formData.email"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                placeholder="请输入注册邮箱"
                                autocomplete="email"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">密码</label>
                            <input 
                                type="password" 
                                v-model="formData.password"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                placeholder="请输入密码"
                                autocomplete="current-password"
                            >
                        </div>
                    </div>

                    <!-- 提交按钮 -->
                    <div style="margin-top: 10px;">
                        <button 
                            type="submit" 
                            :disabled="loading"
                            style="
                                background: #2271b1;
                                color: white;
                                border: none;
                                padding: 12px 24px;
                                border-radius: 4px;
                                cursor: pointer;
                                width: 100%;
                                font-weight: 500;
                                font-size: 15px;
                                transition: all 0.3s ease;
                                position: relative;
                                overflow: hidden;
                                box-shadow: 0 2px 4px rgba(34,113,177,0.2);
                                transform: translateY(0);
                            "
                            :style="loading ? {
                                'opacity': '0.7',
                                'background': '#2271b1',
                                'cursor': 'not-allowed'
                            } : {
                                'background': '#2271b1',
                                ':hover': {
                                    'transform': 'translateY(-1px)',
                                    'box-shadow': '0 4px 8px rgba(34,113,177,0.3)'
                                }
                            }"
                            @mouseover="$event.target.style.transform = 'translateY(-1px)'; $event.target.style.boxShadow = '0 4px 8px rgba(34,113,177,0.3)'"
                            @mouseout="$event.target.style.transform = 'translateY(0)'; $event.target.style.boxShadow = '0 2px 4px rgba(34,113,177,0.2)'"
                        >
                            <span style="
                                position: relative;
                                z-index: 1;
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                gap: 8px;
                            ">
                                {{ loading ? '保存中...' : '保存配置' }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `,
    styles: `
        .slide-fade-enter-active {
            transition: all 0.3s ease-out;
        }

        .slide-fade-leave-active {
            transition: all 0.3s ease-in;
        }

        .slide-fade-enter-from,
        .slide-fade-leave-to {
            transform: translateX(20px);
            opacity: 0;
        }

        .slide-fade-enter-to,
        .slide-fade-leave-from {
            transform: translateX(0);
            opacity: 1;
        }
    `
};

// 在DOM加载完成后初始化Vue应用
jQuery(document).ready(function() {
    // 检查Vue是否正确加载
    if (typeof Vue === 'undefined') {
        console.error('Vue 未加载，请检查网络连接或CDN可用性');
        // 显示错误信息给用户
        const setupDiv = document.getElementById('lsky-pro-setup');
        if (setupDiv) {
            setupDiv.innerHTML = '<div class="notice notice-error"><p>加载Vue框架失败，请检查网络连接后刷新页面重试。</p></div>';
        }
        return;
    }
    
    // 检查ElementPlusIconsVue是否正确加载
    if (typeof ElementPlusIconsVue === 'undefined') {
        console.error('Element Plus Icons 未加载');
        // 继续执行，但不注册图标组件
        const app = createApp(LskyProSetup);
        app.mount('#lsky-pro-setup');
        return;
    }
    
    const app = createApp(LskyProSetup);
    
    // 注册图标组件
    app.component('circle-check-filled', CircleCheckFilled);
    app.component('circle-close-filled', CircleCloseFilled);
    
    // 添加全局样式
    const style = document.createElement('style');
    style.textContent = LskyProSetup.styles;
    document.head.appendChild(style);
    
    app.mount('#lsky-pro-setup');
}); 