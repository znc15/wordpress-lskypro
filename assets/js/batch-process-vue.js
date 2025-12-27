(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function getAjaxEndpoint() {
        if (typeof window.lskyProData !== 'undefined' && window.lskyProData && window.lskyProData.ajaxurl) {
            return window.lskyProData.ajaxurl;
        }
        if (typeof window.ajaxurl !== 'undefined' && window.ajaxurl) {
            return window.ajaxurl;
        }
        return null;
    }

    async function postAjax(action, payload) {
        const endpoint = getAjaxEndpoint();
        if (!endpoint) {
            throw new Error('未找到 AJAX 地址（ajaxurl）');
        }

        const body = new URLSearchParams();
        body.set('action', action);

        if (payload && typeof payload === 'object') {
            Object.keys(payload).forEach((key) => {
                if (payload[key] === undefined || payload[key] === null) return;
                body.set(key, String(payload[key]));
            });
        }

        const resp = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body
        });

        if (!resp.ok) {
            throw new Error('请求失败：' + resp.status);
        }

        return await resp.json();
    }

    function normalizeErrorMessage(response, fallback) {
        if (!response) return fallback;
        if (response.data && typeof response.data === 'object' && response.data.message) return response.data.message;
        if (response.data && typeof response.data === 'string') return response.data;
        return fallback;
    }

    // 尽早声明由 Vue 接管（避免旧脚本在 ready 时先绑定）
    const mountEl = document.getElementById('lsky-batch-app');
    if (!mountEl) return;
    if (typeof window.Vue === 'undefined' || !window.Vue || !window.Vue.createApp) return;
    window.__LSKY_BATCH_VUE_ACTIVE__ = true;

    onReady(function () {
        const { createApp, ref, reactive, computed, onMounted } = window.Vue;

        createApp({
            setup() {
                const update = reactive({
                    loading: true,
                    error: false,
                    currentVersion: '',
                    latestVersion: '',
                    hasUpdate: false,
                    downloadUrl: '',
                    releaseNotes: ''
                });

                const processing = ref(false);
                const stopping = ref(false);
                const shouldStop = ref(false);
                const processingType = ref(null);

                const media = reactive({ processed: 0, total: 0, failed: 0 });
                const post = reactive({ processed: 0, total: 0, failed: 0 });

                const showMediaProgress = ref(false);
                const showPostProgress = ref(false);

                const logs = ref([]);

                let progressModal = null;

                function addLog(message, type) {
                    const now = new Date();
                    const time = now.toLocaleTimeString();
                    const level = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info');
                    logs.value.unshift({ time, message, className: 'log-entry ' + level });
                }

                const mediaPercent = computed(() => {
                    const total = Number(media.total || 0);
                    const processed = Number(media.processed || 0);
                    return total > 0 ? Math.min(100, (processed / total) * 100) : 0;
                });

                const postPercent = computed(() => {
                    const total = Number(post.total || 0);
                    const processed = Number(post.processed || 0);
                    return total > 0 ? Math.min(100, (processed / total) * 100) : 0;
                });

                const currentVersionText = computed(() => {
                    if (update.loading) return '检查中...';
                    if (update.error) return '未知';
                    return update.currentVersion || '未知';
                });

                async function checkUpdate() {
                    update.loading = true;
                    update.error = false;

                    try {
                        const resp = await postAjax('lsky_pro_check_update', {
                            nonce: window.lskyProData ? window.lskyProData.nonce : ''
                        });

                        if (!resp || !resp.success) {
                            update.error = true;
                            return;
                        }

                        const data = resp.data || {};
                        update.currentVersion = data.current_version || '';
                        update.latestVersion = data.latest_version || '';
                        update.hasUpdate = !!data.has_update;
                        update.downloadUrl = data.download_url || '';
                        update.releaseNotes = data.release_notes || '';
                    } catch (e) {
                        update.error = true;
                    } finally {
                        update.loading = false;
                    }
                }

                function resetBatchUI() {
                    processing.value = false;
                    stopping.value = false;
                    shouldStop.value = false;
                    processingType.value = null;
                }

                function applyProgress(type, data) {
                    const target = type === 'media' ? media : post;
                    target.processed = Number(data.processed || 0);
                    target.total = Number(data.total || 0);
                    target.failed = Number(data.failed || 0);
                }

                function applyProcessedItems(items) {
                    if (!Array.isArray(items)) return;
                    items.forEach((item) => {
                        if (!item) return;

                        if (item.status === 'already_processed') {
                            addLog('已处理: ' + (item.original || '') + ' (已存在于图床)', 'success');
                            return;
                        }

                        if (item.success) {
                            addLog('处理成功: ' + (item.original || '') + ' -> ' + (item.new_url || ''), 'success');
                            return;
                        }

                        addLog('处理失败: ' + (item.original || '') + ' (' + (item.error || '未知错误') + ')', 'error');
                    });
                }

                async function runBatch(type) {
                    try {
                        while (true) {
                            if (shouldStop.value) {
                                addLog('处理已停止');
                                break;
                            }

                            const resp = await postAjax('lsky_pro_process_' + type + '_batch', {
                                nonce: window.lskyProData ? window.lskyProData.batchNonce : ''
                            });

                            if (!resp || !resp.success) {
                                addLog(normalizeErrorMessage(resp, '处理失败'), 'error');
                                break;
                            }

                            const data = resp.data || {};
                            applyProgress(type, data);

                            if (data.message) {
                                addLog(String(data.message));
                            }

                            applyProcessedItems(data.processed_items);

                            if (data.completed) {
                                addLog((type === 'media' ? '媒体库' : '文章') + '图片处理完成！', 'success');
                                break;
                            }
                        }
                    } catch (e) {
                        addLog('请求失败，请重试: ' + (e && e.message ? e.message : '未知错误'), 'error');
                    } finally {
                        resetBatchUI();
                    }
                }

                function start(type) {
                    if (processing.value) return;

                    processing.value = true;
                    stopping.value = false;
                    shouldStop.value = false;
                    processingType.value = type;

                    logs.value = [];

                    if (type === 'media') {
                        showMediaProgress.value = true;
                        showPostProgress.value = false;
                        media.processed = 0;
                        media.total = 0;
                        media.failed = 0;
                        addLog('开始处理媒体库图片...');
                    } else {
                        showPostProgress.value = true;
                        showMediaProgress.value = false;
                        post.processed = 0;
                        post.total = 0;
                        post.failed = 0;
                        addLog('开始处理文章图片...');
                    }

                    if (progressModal) {
                        progressModal.show();
                    }

                    runBatch(type);
                }

                function stop() {
                    if (!processing.value) return;
                    stopping.value = true;
                    addLog('正在停止处理...');
                    shouldStop.value = true;
                }

                onMounted(function () {
                    // 初始化 Modal
                    const el = document.getElementById('progressModal');
                    if (el && typeof window.bootstrap !== 'undefined' && window.bootstrap && window.bootstrap.Modal) {
                        progressModal = new window.bootstrap.Modal(el);

                        el.addEventListener('hidden.bs.modal', function () {
                            if (processing.value && progressModal) {
                                progressModal.show();
                            }
                        });
                    }

                    checkUpdate();
                });

                return {
                    update,
                    currentVersionText,
                    processing,
                    stopping,
                    processingType,
                    showMediaProgress,
                    showPostProgress,
                    mediaPercent,
                    postPercent,
                    logs,
                    start,
                    stop
                };
            }
        }).mount('#lsky-batch-app');
    });
})();
