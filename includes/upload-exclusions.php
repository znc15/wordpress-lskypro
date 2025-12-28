<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 生成“待打标” transient key（按本地绝对路径）。
 */
function lsky_pro_exclusion_pending_transient_key(string $file_path): string {
    $p = $file_path;
    if (function_exists('wp_normalize_path')) {
        $p = wp_normalize_path($p);
    }
    return 'lsky_pro_excl_pending_' . md5($p);
}

/**
 * 给附件写入“限制文件”标记，供 batch 识别。
 */
function lsky_pro_mark_attachment_restricted(int $attachment_id, string $reason = '', bool $mark_avatar = false): void {
    if ($attachment_id <= 0 || !function_exists('update_post_meta')) {
        return;
    }

    // 通用：批处理跳过
    update_post_meta($attachment_id, '_lsky_pro_batch_skip', 1);

    // 0=非限制文件，1=限制文件
    update_post_meta($attachment_id, '_lsky_pro_type', 1);

    if ($mark_avatar) {
        update_post_meta($attachment_id, '_lsky_pro_is_avatar', 1);
    }

    if ($reason !== '') {
        update_post_meta($attachment_id, '_lsky_pro_batch_skip_reason', $reason);
    }
}

/**
 * 在还没有 attachment_id 的阶段，先按 file_path 暂存“待打标”信息。
 */
function lsky_pro_store_pending_exclusion_by_file(string $file_path, string $reason = '', bool $mark_avatar = false): void {
    $file_path = trim($file_path);
    if ($file_path === '' || !function_exists('set_transient')) {
        return;
    }

    $key = lsky_pro_exclusion_pending_transient_key($file_path);
    set_transient(
        $key,
        array(
            'reason' => $reason,
            'mark_avatar' => $mark_avatar,
            'created_at' => time(),
        ),
        2 * HOUR_IN_SECONDS
    );
}

/**
 * 当拿到 attachment_id 后（例如生成 metadata 阶段），尝试应用之前按 file_path 暂存的打标。
 */
function lsky_pro_apply_pending_exclusion_for_attachment(int $attachment_id): void {
    if ($attachment_id <= 0 || !function_exists('get_attached_file') || !function_exists('get_transient')) {
        return;
    }

    $file_path = (string) get_attached_file($attachment_id);
    if ($file_path === '') {
        return;
    }

    $key = lsky_pro_exclusion_pending_transient_key($file_path);
    $pending = get_transient($key);
    if (!is_array($pending)) {
        return;
    }

    $reason = isset($pending['reason']) ? (string) $pending['reason'] : '';
    $mark_avatar = isset($pending['mark_avatar']) ? (bool) $pending['mark_avatar'] : false;
    lsky_pro_mark_attachment_restricted($attachment_id, $reason, $mark_avatar);

    if (function_exists('delete_transient')) {
        delete_transient($key);
    }
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
    $skip_reason = '';
    $skip_mark_avatar = false;

    // 仅处理图片类型：如果能判断出不是图片，则不走图床（保持 WordPress 原行为）
    $mime = strtolower(trim((string) $args['mime_type']));
    if ($mime !== '' && strpos($mime, 'image/') !== 0) {
        $should = false;
        // 非图片：不打标（只影响图床上传逻辑，不参与批处理跳过）
    }

    // 排除站点图标（强制默认开启，避免上传后删除本地文件导致站点图标流程异常）
    $exclude_site_icon = isset($options['exclude_site_icon']) ? (int) $options['exclude_site_icon'] : 1;
    if ($exclude_site_icon === 1 && $context === 'site-icon') {
        $should = false;
        $skip_reason = 'site-icon';
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
                    $skip_reason = 'ajax-action:' . $kw;
                    if (strpos($kw, 'avatar') !== false || strpos(strtolower($action), 'avatar') !== false) {
                        $skip_mark_avatar = true;
                    }
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
                    $skip_reason = 'referer-contains';
                    if (stripos($needle, 'avatar') !== false || stripos($referer, 'avatar') !== false) {
                        $skip_mark_avatar = true;
                    }
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
    if ($should === false && $skip_reason !== '') {
        $attachment_id = isset($args['attachment_id']) ? (int) $args['attachment_id'] : 0;
        if ($attachment_id <= 0 && isset($args['file_path']) && (string) $args['file_path'] !== '' && function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            if ($basedir !== '' && function_exists('wp_normalize_path')) {
                $basedir_n = wp_normalize_path($basedir);
                $file_n = wp_normalize_path((string) $args['file_path']);
                if ($basedir_n !== '' && strpos($file_n, $basedir_n) === 0) {
                    $rel = ltrim(substr($file_n, strlen($basedir_n)), '/');
                    if ($rel !== '') {
                        global $wpdb;
                        if ($wpdb) {
                            $found = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                                    $rel
                                )
                            );
                            if ($found > 0) {
                                $attachment_id = $found;
                            }
                        }
                    }
                }
            }
        }

        if ($attachment_id > 0) {
            lsky_pro_mark_attachment_restricted($attachment_id, $skip_reason, $skip_mark_avatar);
        } else {
            $file_path = isset($args['file_path']) ? (string) $args['file_path'] : '';
            if ($file_path !== '') {
                lsky_pro_store_pending_exclusion_by_file($file_path, $skip_reason, $skip_mark_avatar);
            }
        }
    }

    return $should;
}
