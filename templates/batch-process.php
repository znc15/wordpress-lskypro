<div id="lsky-batch-app">
    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="batch-section">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <h3 class="h5 mb-1">媒体库图片处理</h3>
                        <div class="text-muted small">将媒体库中未同步的图片批量上传到图床</div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button id="start-media-batch" class="btn btn-primary">开始处理</button>
                    <button id="stop-media-batch" class="btn btn-outline-secondary">停止处理</button>
                    <button id="reset-media-batch" class="btn btn-outline-danger">重置进度（从头开始）</button>
                </div>

                <div class="mt-3 text-muted small">处理过程会在对话框中显示进度与日志。</div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="batch-section">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <h3 class="h5 mb-1">文章图片处理</h3>
                        <div class="text-muted small">扫描文章内容中的图片并同步到图床</div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button id="start-post-batch" class="btn btn-primary">开始处理</button>
                    <button id="stop-post-batch" class="btn btn-outline-secondary">停止处理</button>
                    <button id="reset-post-batch" class="btn btn-outline-danger">重置进度（从头开始）</button>
                </div>

                <div class="mt-3 text-muted small">建议在站点访问较少时执行批处理。</div>
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
                    <div id="media-batch-progress" class="progress mb-3">
                        <div class="progress-bar" role="progressbar"></div>
                    </div>
                    <div id="post-batch-progress" class="progress mb-3">
                        <div class="progress-bar" role="progressbar"></div>
                    </div>

                    <div id="batch-log" class="mt-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <h6 class="mb-2">处理日志</h6>
                            <span class="text-muted small">最新日志在最上方</span>
                        </div>
                        <div class="log-content log-container"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>
</div>