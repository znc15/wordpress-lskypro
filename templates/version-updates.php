<?php

if (!defined('ABSPATH')) {
    exit;
}

// 手动检查更新：清除缓存并重新拉取。
$lsky_pro_did_force_refresh = false;
if (
    isset($_POST['lsky_pro_release_refresh'], $_POST['lsky_pro_release_refresh_nonce'])
    && $_POST['lsky_pro_release_refresh'] === '1'
    && current_user_can('manage_options')
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lsky_pro_release_refresh_nonce'])), 'lsky_pro_release_refresh')
) {
    $refresh_api_url = apply_filters(
        'lsky_pro_github_releases_latest_url',
        'https://api.github.com/repos/znc15/wordpress-lskypro/releases/latest'
    );
    $refresh_api_url = trim((string) $refresh_api_url);
    if ($refresh_api_url !== '') {
        $refresh_cache_key = 'lsky_pro_github_latest_release_v1_' . md5($refresh_api_url);
        delete_transient($refresh_cache_key);
        $lsky_pro_did_force_refresh = true;
    }
}

if (!function_exists('lsky_pro_fetch_github_latest_release')) {
    function lsky_pro_fetch_github_latest_release() {
        $api_url = apply_filters(
            'lsky_pro_github_releases_latest_url',
            'https://api.github.com/repos/znc15/wordpress-lskypro/releases/latest'
        );
        $api_url = trim((string) $api_url);

        $result = array(
            'content_html' => '',
            'source_url' => '',
            'request_url' => $api_url,
            'fetched_at' => 0,
        );

        if ($api_url === '') {
            return $result;
        }

        $cache_key = 'lsky_pro_github_latest_release_v1_' . md5($api_url);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['content_html'])) {
            return $cached;
        }

        $response = wp_remote_get(
            $api_url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3.html+json',
                    'User-Agent' => 'WordPress-LskyPro',
                ),
            )
        );

        if (is_wp_error($response)) {
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $html = '';
        if (!empty($json['body_html'])) {
            $html = (string) $json['body_html'];
        } elseif (!empty($json['body'])) {
            // 兜底：若未返回 body_html，则按纯文本显示。
            $html = '<pre>' . esc_html((string) $json['body']) . '</pre>';
        }

        $result['content_html'] = $html;
        $result['source_url'] = !empty($json['html_url']) ? (string) $json['html_url'] : '';
        $result['fetched_at'] = time();

        set_transient($cache_key, $result, ($html !== '') ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        return $result;
    }
}

$plugin_data = function_exists('get_file_data')
    ? get_file_data(LSKY_PRO_PLUGIN_FILE, array('Version' => 'Version'), 'plugin')
    : array();

$current_version = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '';

$remote = lsky_pro_fetch_github_latest_release();
$content_html = (string) ($remote['content_html'] ?? '');
$source_label = 'GitHub Releases';
$source_url = (string) ($remote['source_url'] ?? '');
$request_url = (string) ($remote['request_url'] ?? '');
$fetched_at = (int) ($remote['fetched_at'] ?? 0);

?>

<div class="mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="badge bg-primary">当前版本</div>
        <div><?php echo esc_html($current_version !== '' ? $current_version : '-'); ?></div>
    </div>
</div>

<div class="mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="badge bg-secondary">来源</div>
        <div>
            <?php echo esc_html($source_label); ?>
            <?php if ($source_url !== '') : ?>
                <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer">查看原文</a>
            <?php endif; ?>
            <form method="post" class="d-inline ms-2">
                <input type="hidden" name="lsky_pro_release_refresh" value="1" />
                <?php wp_nonce_field('lsky_pro_release_refresh', 'lsky_pro_release_refresh_nonce'); ?>
                <button type="submit" class="btn btn-outline-primary btn-sm">手动检查更新</button>
            </form>
            <?php if ($fetched_at > 0) : ?>
                <span class="text-muted ms-2">更新时间：<?php echo esc_html(wp_date('Y-m-d H:i:s', $fetched_at)); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($request_url !== '') : ?>
    <script>
        // eslint-disable-next-line no-console
        console.log('LskyPro GitHub Releases request url:', <?php echo wp_json_encode($request_url); ?>);
    </script>
<?php endif; ?>

<style>
    .lsky-md h1,.lsky-md h2,.lsky-md h3{margin-top:1rem;margin-bottom:.75rem}
    .lsky-md p{margin-bottom:.75rem}
    .lsky-md ul,.lsky-md ol{padding-left:1.25rem}
    .lsky-md pre{overflow:auto;padding:1rem;border-radius:.375rem;background:var(--bs-gray-100)}
    .lsky-md code{padding:.1rem .25rem;border-radius:.25rem;background:var(--bs-gray-100)}
    .lsky-md pre code{padding:0;background:transparent}
    .lsky-md blockquote{padding:.5rem 1rem;border-left:.25rem solid var(--bs-border-color);color:var(--bs-secondary-color);margin:0 0 .75rem}
</style>

<?php if ($content_html !== '') : ?>
    <div class="p-3 bg-light border rounded lsky-md">
        <?php echo wp_kses_post($content_html); ?>
    </div>
<?php else : ?>
    <div class="alert alert-info" role="alert">
        暂无可显示的更新信息。
    </div>
<?php endif; ?>
