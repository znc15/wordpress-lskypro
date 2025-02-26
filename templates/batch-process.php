<!-- 在批量处理按钮前添加版本信息显示 -->
<div class="version-info mb-4">
    <div class="d-flex align-items-center">
        <span class="me-2">当前版本：<strong id="current-version">检查中...</strong></span>
        <div id="version-status"></div>
    </div>
    <div id="update-info" style="display:none;" class="mt-2">
        <div class="alert alert-info">
            <h6>更新说明：</h6>
            <div id="release-notes"></div>
            <a href="#" id="download-link" class="btn btn-primary btn-sm mt-2" target="_blank">下载更新</a>
        </div>
    </div>
</div>

<!-- 批量处理按钮 -->
<div class="batch-controls">
    <div class="batch-section mb-4">
        <h3 class="h5 mb-3">媒体库图片处理</h3>
        <button id="start-media-batch" class="btn btn-primary">开始处理媒体库图片</button>
        <button id="stop-media-batch" class="btn btn-secondary" style="display:none;">停止处理</button>
    </div>

    <div class="batch-section mb-4">
        <h3 class="h5 mb-3">文章图片处理</h3>
        <button id="start-post-batch" class="btn btn-primary">开始处理文章图片</button>
        <button id="stop-post-batch" class="btn btn-secondary" style="display:none;">停止处理</button>
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
                <div id="media-batch-progress" class="progress mb-3" style="display:none;">
                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
                <div id="post-batch-progress" class="progress mb-3" style="display:none;">
                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
                
                <div id="batch-log" class="mt-4">
                    <h6>处理日志</h6>
                    <div class="log-content" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div> 