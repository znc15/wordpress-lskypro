<?php

if (!defined('ABSPATH')) {
    exit;
}

trait LskyProUploaderRequestContextTrait {
    /**
     * 获取最近一次上传请求参数（已脱敏）。
     */
    public function getLastRequestContext() {
        return $this->last_request_context;
    }

    private function setUploadRequestContext($context) {
        $this->last_request_context = is_array($context) ? $context : array();
    }

    private function formatLastRequestContextForError() {
        if (empty($this->last_request_context)) {
            return '';
        }

        $json = wp_json_encode($this->last_request_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }

        return substr($json, 0, 800);
    }
}
