<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 决定某次上传是否需要同步到图床。
 *
 * 说明：返回 false 表示“跳过图床上传”，WordPress 仍按原流程保存本地文件并使用本地 URL。
 *
 * @param array $args {
 *   @type string      $file_path      本地绝对路径（若已知）
 *   @type string      $mime_type      MIME（若已知）
 *   @type int|null    $attachment_id  附件 ID（若已知）
 *   @type string|null $source         调用来源（upload|post_local_media|media_batch 等）
 * }
 * @param array $request_context {
 *   @type bool   $doing_ajax
 *   @type string $action
 *   @type string $context
 *   @type string $referer
 *   @type string $request_uri
 * }
 */
function lsky_pro_should_upload_to_lsky(array $args = array(), array $request_context = array()): bool {
    $options = function_exists('lsky_pro_get_options_normalized')
        ? lsky_pro_get_options_normalized()
        : (is_array(get_option('lsky_pro_options')) ? get_option('lsky_pro_options') : array());

    $args = array_merge(
        array(
            'file_path' => '',
            'mime_type' => '',
            'attachment_id' => null,
            'source' => null,
        ),
        $args
    );

    $doing_ajax = isset($request_context['doing_ajax'])
        ? (bool) $request_context['doing_ajax']
        : (function_exists('wp_doing_ajax') ? wp_doing_ajax() : false);

    $action = isset($request_context['action']) ? (string) $request_context['action'] : '';
    if ($action === '' && isset($_REQUEST['action'])) {
        $action = sanitize_key((string) $_REQUEST['action']);
    }

    $context = isset($request_context['context']) ? (string) $request_context['context'] : '';
    if ($context === '' && isset($_REQUEST['context'])) {
        $context = sanitize_key((string) $_REQUEST['context']);
    }

    $referer = isset($request_context['referer']) ? (string) $request_context['referer'] : '';
    if ($referer === '' && function_exists('wp_get_referer')) {
        $r = (string) wp_get_referer();
        if ($r !== '') {
            $referer = $r;
        }
    }
    if ($referer === '' && isset($_SERVER['HTTP_REFERER'])) {
        $referer = (string) $_SERVER['HTTP_REFERER'];
    }

    $request_uri = isset($request_context['request_uri'])
        ? (string) $request_context['request_uri']
        : (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');

    $request_context = array(
        'doing_ajax' => $doing_ajax,
        'action' => $action,
        'context' => $context,
        'referer' => $referer,
        'request_uri' => $request_uri,
    );

    // 默认允许上传
    $should = true;

    // 仅处理图片类型：如果能判断出不是图片，则不走图床（保持 WordPress 原行为）
    $mime = strtolower(trim((string) $args['mime_type']));
    if ($mime !== '' && strpos($mime, 'image/') !== 0) {
        $should = false;
    }

    // 排除站点图标（强制默认开启，避免上传后删除本地文件导致站点图标流程异常）
    $exclude_site_icon = isset($options['exclude_site_icon']) ? (int) $options['exclude_site_icon'] : 1;
    if ($exclude_site_icon === 1 && $context === 'site-icon') {
        $should = false;
    }

    // 排除头像：按 AJAX action 关键字匹配（默认含 avatar）。
    // 只要是 ajax 请求且 action 命中任意关键字，就跳过图床上传。
    $exclude_actions_raw = isset($options['exclude_ajax_actions']) ? (string) $options['exclude_ajax_actions'] : "avatar\n";
    if ($should && $doing_ajax && $action !== '' && $exclude_actions_raw !== '') {
        $keywords = preg_split('/\r\n|\r|\n/', $exclude_actions_raw);
        if (is_array($keywords)) {
            foreach ($keywords as $kw) {
                $kw = strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                if (strpos(strtolower($action), $kw) !== false) {
                    $should = false;
                    break;
                }
            }
        }
    }

    // 可选：按 Referer 关键字排除（适合用户中心上传头像这种场景）
    $exclude_referer_raw = isset($options['exclude_referer_contains']) ? (string) $options['exclude_referer_contains'] : '';
    if ($should && $referer !== '' && $exclude_referer_raw !== '') {
        $needles = preg_split('/\r\n|\r|\n/', $exclude_referer_raw);
        if (is_array($needles)) {
            foreach ($needles as $needle) {
                $needle = trim((string) $needle);
                if ($needle === '') {
                    continue;
                }
                if (stripos($referer, $needle) !== false) {
                    $should = false;
                    break;
                }
            }
        }
    }

    /**
     * 允许主题/插件自定义“是否上传图床”。
     *
     * @param bool  $should
     * @param array $args
     * @param array $request_context
     */
    if (function_exists('apply_filters')) {
        $should = (bool) apply_filters('lsky_pro_should_upload', $should, $args, $request_context);
    }

    return $should;
}
