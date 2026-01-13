<?php

declare(strict_types=1);

namespace LskyPro\Support;

final class UploadExclusions
{
    private const META_AVATAR = '_lsky_pro_is_avatar';
    private const META_BATCH_SKIP = '_lsky_pro_batch_skip';
    private const META_TYPE = '_lsky_pro_type';

    private static function pendingKey(string $filePath): string
    {
        $p = $filePath;
        if (\function_exists('wp_normalize_path')) {
            $p = \wp_normalize_path($p);
        }

        return 'lsky_pro_excl_pending_' . \md5($p);
    }

    public static function markRestricted(int $attachmentId, string $reason = '', bool $markAvatar = false): void
    {
        if ($attachmentId <= 0 || !\function_exists('update_post_meta')) {
            return;
        }

        \update_post_meta($attachmentId, self::META_BATCH_SKIP, 1);
        \update_post_meta($attachmentId, self::META_TYPE, 1);

        if ($markAvatar) {
            \update_post_meta($attachmentId, self::META_AVATAR, 1);
        }

        if ($reason !== '') {
            \update_post_meta($attachmentId, '_lsky_pro_batch_skip_reason', $reason);
        }
    }

    public static function storePendingByFile(string $filePath, string $reason = '', bool $markAvatar = false): void
    {
        $filePath = \trim($filePath);
        if ($filePath === '' || !\function_exists('set_transient')) {
            return;
        }

        \set_transient(
            self::pendingKey($filePath),
            [
                'reason' => $reason,
                'mark_avatar' => $markAvatar,
                'created_at' => \time(),
            ],
            2 * \HOUR_IN_SECONDS
        );
    }

    public static function applyPendingForAttachment(int $attachmentId): void
    {
        if ($attachmentId <= 0 || !\function_exists('get_attached_file') || !\function_exists('get_transient')) {
            return;
        }

        $filePath = (string) \get_attached_file($attachmentId);
        if ($filePath === '') {
            return;
        }

        $pending = \get_transient(self::pendingKey($filePath));
        if (!\is_array($pending)) {
            return;
        }

        $reason = isset($pending['reason']) ? (string) $pending['reason'] : '';
        $markAvatar = isset($pending['mark_avatar']) ? (bool) $pending['mark_avatar'] : false;

        self::markRestricted($attachmentId, $reason, $markAvatar);

        if (\function_exists('delete_transient')) {
            \delete_transient(self::pendingKey($filePath));
        }
    }

    /**
     * 返回 false 表示跳过图床上传。
     *
     * @param array{file_path?:string,mime_type?:string,attachment_id?:int|null,source?:string|null} $args
     * @param array{doing_ajax?:bool,action?:string,context?:string,referer?:string,request_uri?:string} $requestContext
     */
    public static function shouldUpload(array $args = [], array $requestContext = []): bool
    {
        $options = Options::normalized();

        $args = \array_merge(
            [
                'file_path' => '',
                'mime_type' => '',
                'attachment_id' => null,
                'source' => null,
            ],
            $args
        );

        $doingAjax = isset($requestContext['doing_ajax'])
            ? (bool) $requestContext['doing_ajax']
            : (\function_exists('wp_doing_ajax') ? \wp_doing_ajax() : false);

        $action = isset($requestContext['action']) ? (string) $requestContext['action'] : '';
        if ($action === '' && isset($_REQUEST['action'])) {
            $action = \sanitize_key((string) $_REQUEST['action']);
        }

        $context = isset($requestContext['context']) ? (string) $requestContext['context'] : '';
        if ($context === '' && isset($_REQUEST['context'])) {
            $context = \sanitize_key((string) $_REQUEST['context']);
        }

        $referer = isset($requestContext['referer']) ? (string) $requestContext['referer'] : '';
        if ($referer === '' && \function_exists('wp_get_referer')) {
            $r = (string) \wp_get_referer();
            if ($r !== '') {
                $referer = $r;
            }
        }
        if ($referer === '' && isset($_SERVER['HTTP_REFERER'])) {
            $referer = (string) $_SERVER['HTTP_REFERER'];
        }

        $requestUri = isset($requestContext['request_uri'])
            ? (string) $requestContext['request_uri']
            : (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');

        // 默认允许上传
        $should = true;
        $skipReason = '';
        $skipMarkAvatar = false;

        $mime = \strtolower(\trim((string) ($args['mime_type'] ?? '')));
        if ($mime !== '' && \strpos($mime, 'image/') !== 0) {
            $should = false;
        }

        $excludeSiteIcon = isset($options['exclude_site_icon']) ? (int) $options['exclude_site_icon'] : 1;
        if ($excludeSiteIcon === 1 && $context === 'site-icon') {
            $should = false;
            $skipReason = 'site-icon';
        }

        $excludeActionsRaw = isset($options['exclude_ajax_actions']) ? (string) $options['exclude_ajax_actions'] : "avatar\n";
        if ($should && $doingAjax && $action !== '' && $excludeActionsRaw !== '') {
            $keywords = \preg_split('/\r\n|\r|\n/', $excludeActionsRaw);
            if (\is_array($keywords)) {
                foreach ($keywords as $kw) {
                    $kw = \strtolower(\trim((string) $kw));
                    if ($kw === '') {
                        continue;
                    }
                    if (\strpos(\strtolower($action), $kw) !== false) {
                        $should = false;
                        $skipReason = 'ajax-action:' . $kw;
                        if (\strpos($kw, 'avatar') !== false || \strpos(\strtolower($action), 'avatar') !== false) {
                            $skipMarkAvatar = true;
                        }
                        break;
                    }
                }
            }
        }

        $excludeRefererRaw = isset($options['exclude_referer_contains']) ? (string) $options['exclude_referer_contains'] : '';
        if ($should && $referer !== '' && $excludeRefererRaw !== '') {
            $needles = \preg_split('/\r\n|\r|\n/', $excludeRefererRaw);
            if (\is_array($needles)) {
                foreach ($needles as $needle) {
                    $needle = \trim((string) $needle);
                    if ($needle === '') {
                        continue;
                    }
                    if (\stripos($referer, $needle) !== false) {
                        $should = false;
                        $skipReason = 'referer-contains';
                        if (\stripos($needle, 'avatar') !== false || \stripos($referer, 'avatar') !== false) {
                            $skipMarkAvatar = true;
                        }
                        break;
                    }
                }
            }
        }

        if (\function_exists('apply_filters')) {
            $should = (bool) \apply_filters('lsky_pro_should_upload', $should, $args, [
                'doing_ajax' => $doingAjax,
                'action' => $action,
                'context' => $context,
                'referer' => $referer,
                'request_uri' => $requestUri,
            ]);
        }

        if ($should === false && $skipReason !== '') {
            $attachmentId = isset($args['attachment_id']) ? (int) $args['attachment_id'] : 0;

            if ($attachmentId > 0) {
                self::markRestricted($attachmentId, $skipReason, $skipMarkAvatar);
            } else {
                $filePath = isset($args['file_path']) ? (string) $args['file_path'] : '';
                if ($filePath !== '') {
                    self::storePendingByFile($filePath, $skipReason, $skipMarkAvatar);
                }
            }
        }

        return $should;
    }
}
