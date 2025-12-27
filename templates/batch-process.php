<div id="lsky-batch-app" v-cloak>
    <!-- 在批量处理按钮前添加版本信息显示 -->
    <div class="version-info mb-4">
        <div class="d-flex align-items-center">
            <span class="me-2">当前版本：<strong id="current-version">{{ currentVersionText }}</strong></span>
            <div id="version-status">
                <span v-if="update.loading" class="badge bg-secondary">检查中...</span>
                <span v-else-if="update.error" class="badge bg-danger">检查更新失败</span>
                <span v-else-if="update.hasUpdate" class="badge bg-warning">有新版本可用：{{ update.latestVersion }}</span>
                <span v-else class="badge bg-success">已是最新版本</span>
            </div>
        </div>

        <div id="update-info" v-if="!update.loading && !update.error && update.hasUpdate" class="mt-2">
            <div class="alert alert-info">
                <h6>更新说明：</h6>
                <div id="release-notes" v-html="update.releaseNotes"></div>
                <a :href="update.downloadUrl" id="download-link" class="btn btn-primary btn-sm mt-2" target="_blank" rel="noopener">下载更新</a>
            </div>
        </div>
    </div>

    <!-- 批量处理按钮 -->
    <div class="batch-controls">
        <div class="batch-section mb-4">
            <h3 class="h5 mb-3">媒体库图片处理</h3>
            <div class="d-grid gap-2">
                <button
                    id="start-media-batch"
                    class="btn btn-primary w-100"
                    :disabled="processing"
                    v-show="!(processing && processingType === 'media')"
                    @click="start('media')"
                >开始处理媒体库图片</button>
                <button
                    id="stop-media-batch"
                    class="btn btn-outline-secondary w-100"
                    v-show="processing && processingType === 'media'"
                    :disabled="stopping"
                    @click="stop"
                >停止处理</button>
            </div>
        </div>

        <div class="batch-section mb-4">
            <h3 class="h5 mb-3">文章图片处理</h3>
            <div class="d-grid gap-2">
                <button
                    id="start-post-batch"
                    class="btn btn-primary w-100"
                    :disabled="processing"
                    v-show="!(processing && processingType === 'post')"
                    @click="start('post')"
                >开始处理文章图片</button>
                <button
                    id="stop-post-batch"
                    class="btn btn-outline-secondary w-100"
                    v-show="processing && processingType === 'post'"
                    :disabled="stopping"
                    @click="stop"
                >停止处理</button>
            </div>
        </div>
    </div>

    <!-- 进度对话框 -->
    <div class="modal fade" id="progressModal" tabindex="-1" aria-labelledby="progressModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="progressModalLabel">处理进度</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="media-batch-progress" class="progress mb-3" v-show="showMediaProgress">
                        <div class="progress-bar" role="progressbar" :style="{ width: mediaPercent + '%' }"></div>
                    </div>
                    <div id="post-batch-progress" class="progress mb-3" v-show="showPostProgress">
                        <div class="progress-bar" role="progressbar" :style="{ width: postPercent + '%' }"></div>
                    </div>

                    <div id="batch-log" class="mt-4" v-show="logs.length">
                        <h6>处理日志</h6>
                        <div class="log-content log-container">
                            <p v-for="entry in logs" :key="entry.time + entry.message" :class="entry.className">[{{ entry.time }}] {{ entry.message }}</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>
</div>