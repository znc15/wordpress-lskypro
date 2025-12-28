(function (window, document, $) {
    'use strict';

    function checkUpdate(ajaxEndpoint) {
        $.ajax({
            url: ajaxEndpoint,
            type: 'POST',
            data: {
                // 更新检查功能已移除
                nonce: window.lskyProData ? window.lskyProData.nonce : ''
            },
            success: function (response) {
                if (response.success) {
                    var data = response.data;
                    $('#current-version').text(data.current_version);

                    if (data.has_update) {
                        $('#version-status').html('<span class="badge bg-warning">有新版本可用：' + data.latest_version + '</span>');
                        $('#release-notes').html(data.release_notes);
                        $('#download-link').attr('href', data.download_url);
                        $('#update-info').show();
                    } else {
                        $('#version-status').html('<span class="badge bg-success">已是最新版本</span>');
                        $('#update-info').hide();
                    }
                } else {
                    $('#version-status').html('<span class="badge bg-danger">检查更新失败</span>');
                }
            },
            error: function () {
                $('#version-status').html('<span class="badge bg-danger">检查更新失败</span>');
            }
        });
    }

    $(function () {
        var api = window.LskyProAdmin || {};
        if (!api.ajaxEndpoint) return;

        if (!api.vueBatchActive && $('#current-version').length && $('#version-status').length) {
            checkUpdate(api.ajaxEndpoint);
        }
    });
})(window, document, jQuery);
