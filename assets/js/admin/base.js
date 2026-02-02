(function (window, document, $) {
    'use strict';

    window.LskyProAdmin = window.LskyProAdmin || {};

    function getAjaxEndpoint() {
        if (typeof window.lskyProData !== 'undefined' && window.lskyProData && window.lskyProData.ajaxurl) {
            return window.lskyProData.ajaxurl;
        }
        if (typeof window.ajaxurl !== 'undefined' && window.ajaxurl) {
            return window.ajaxurl;
        }
        return null;
    }

    function ensureAjaxEndpointOrShowError() {
        var endpoint = getAjaxEndpoint();
        if (!endpoint) {
            if ($('#user-info').length) {
                $('#user-info').html('<div class="alert alert-danger">未找到 AJAX 地址（ajaxurl）</div>');
            }
            return null;
        }
        return endpoint;
    }

    function initTooltips() {
        if (typeof window.bootstrap === 'undefined' || !window.bootstrap || !window.bootstrap.Tooltip) return;
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new window.bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    function addLog(message, type) {
        type = type || 'info';
        var $container = $('#batch-log');
        if (!$container.length) return;

        var time = new Date().toLocaleTimeString();
        var className = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info');

        $container.show();
        var $log = $container.find('.log-content');
        // XSS-safe: always write as text.
        var text = '[' + time + '] ' + String(message === null || message === undefined ? '' : message);
        var $p = $('<p/>').addClass(className).text(text);
        $log.prepend($p);
    }

    function setProgress(type, data) {
        var selector = type === 'media' ? '#media-batch-progress' : '#post-batch-progress';
        if (!$(selector).length) return;

        var total = Number((data && data.total) || 0);
        var processed = Number((data && data.processed) || 0);
        var progress = total > 0 ? Math.min(100, (processed / total) * 100) : 0;
        $(selector + ' .progress-bar').css('width', progress + '%');
    }

    $(function () {
        window.LskyProAdmin.vueBatchActive = (typeof window !== 'undefined' && window.__LSKY_BATCH_VUE_ACTIVE__ === true);
        window.LskyProAdmin.ajaxEndpoint = ensureAjaxEndpointOrShowError();
        window.LskyProAdmin.addLog = addLog;
        window.LskyProAdmin.setProgress = setProgress;
        initTooltips();
    });
})(window, document, jQuery);
