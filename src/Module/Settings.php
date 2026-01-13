<?php

declare(strict_types=1);

namespace LskyPro\Module;

use LskyPro\Uploader;
use LskyPro\Support\Http;
use LskyPro\Support\Options;

final class Settings
{
    public function register(): void
    {
        \add_action('admin_init', [$this, 'settings_init']);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function validate_settings($input): array
    {
        $previous = Options::normalized();

        if (!\is_array($input)) {
            $input = [];
        }

        $clean = [];

        $apiUrlRaw = isset($input['lsky_pro_api_url']) ? (string) $input['lsky_pro_api_url'] : '';
        $apiUrl = \trim(\esc_url_raw($apiUrlRaw));
        $apiUrl = \rtrim($apiUrl, '/');

        if ($apiUrl !== '' && !\preg_match('~/api/v2$~', $apiUrl)) {
            \add_settings_error(
                Options::KEY,
                'lsky_pro_api_url_error',
                'API 地址必须以 /api/v2 结尾，例如：https://your-domain.com/api/v2',
                'error'
            );
            return $previous;
        }

        $clean['lsky_pro_api_url'] = $apiUrl;

        $tokenRaw = isset($input['lsky_pro_token']) ? (string) $input['lsky_pro_token'] : '';
        $token = \trim((string) \sanitize_text_field($tokenRaw));
        $clean['lsky_pro_token'] = $token;

        $storageId = isset($input['storage_id']) ? \absint($input['storage_id']) : 0;
        if ($storageId <= 0) {
            $storageId = 1;
        }
        $clean['storage_id'] = (string) $storageId;

        $albumId = isset($input['album_id']) ? \absint($input['album_id']) : 0;
        $clean['album_id'] = (string) $albumId;

        $clean['process_remote_images'] = (!empty($input['process_remote_images']) && (string) $input['process_remote_images'] === '1') ? 1 : 0;

        $clean['exclude_site_icon'] = (!empty($input['exclude_site_icon']) && (string) $input['exclude_site_icon'] === '1') ? 1 : 0;

        $excludeActionsRaw = isset($input['exclude_ajax_actions']) ? (string) $input['exclude_ajax_actions'] : '';
        $excludeActionsRaw = \str_replace(["\r\n", "\r"], "\n", $excludeActionsRaw);
        $lines = \array_filter(\array_map('trim', \explode("\n", $excludeActionsRaw)), static function ($v): bool {
            return $v !== '';
        });
        $clean['exclude_ajax_actions'] = $lines ? \implode("\n", $lines) . "\n" : '';

        $excludeRefererRaw = isset($input['exclude_referer_contains']) ? (string) $input['exclude_referer_contains'] : '';
        $excludeRefererRaw = \str_replace(["\r\n", "\r"], "\n", $excludeRefererRaw);
        $lines = \array_filter(\array_map('trim', \explode("\n", $excludeRefererRaw)), static function ($v): bool {
            return $v !== '';
        });
        $clean['exclude_referer_contains'] = $lines ? \implode("\n", $lines) . "\n" : '';

        if ($apiUrl === '' || $token === '') {
            return \array_merge($previous, $clean);
        }

        $profileUrl = $apiUrl . '/user/profile';
        $response = Http::requestWithFallback(
            $profileUrl,
            [
                'method' => 'GET',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (\is_wp_error($response)) {
            \add_settings_error(
                Options::KEY,
                'lsky_pro_token_error',
                'Token 验证失败：' . $response->get_error_message(),
                'error'
            );
            return $previous;
        }

        $httpCode = (int) \wp_remote_retrieve_response_code($response);
        $bodyRaw = (string) \wp_remote_retrieve_body($response);

        if ($httpCode !== 200) {
            \add_settings_error(
                Options::KEY,
                'lsky_pro_token_error',
                'Token 验证失败（HTTP ' . $httpCode . '），请检查 API 地址和 Token 是否正确',
                'error'
            );
            return $previous;
        }

        $result = \json_decode($bodyRaw, true);
        if (!\is_array($result)) {
            \add_settings_error(
                Options::KEY,
                'lsky_pro_token_error',
                'API 响应解析失败，请检查设置',
                'error'
            );
            return $previous;
        }

        if (!isset($result['status']) || $result['status'] !== 'success') {
            $msg = isset($result['message']) ? (string) $result['message'] : 'API 响应异常，请检查设置';
            \add_settings_error(Options::KEY, 'lsky_pro_token_error', $msg, 'error');
            return $previous;
        }

        if ($albumId > 0) {
            $uploader = new Uploader();
            $albums = $uploader->get_all_albums('', 100);
            if ($albums === false) {
                \add_settings_error(
                    Options::KEY,
                    'lsky_pro_album_error',
                    '相册列表获取失败，无法保存相册选择：' . $uploader->getError(),
                    'error'
                );
                $clean['album_id'] = (string) ($previous['album_id'] ?? '0');
            } else {
                $found = false;
                foreach ($albums as $a) {
                    if (\is_array($a) && isset($a['id']) && (int) $a['id'] === $albumId) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    \add_settings_error(
                        Options::KEY,
                        'lsky_pro_album_error',
                        '相册不存在或无权限访问，无法保存相册选择（ID: ' . $albumId . '）',
                        'error'
                    );
                    $clean['album_id'] = (string) ($previous['album_id'] ?? '0');
                }
            }
        }

        \add_settings_error(Options::KEY, 'lsky_pro_token_success', '设置已保存，Token 验证成功！', 'success');

        return \array_merge($previous, $clean);
    }

    public function settings_init(): void
    {
        \register_setting(Options::KEY, Options::KEY, [
            'type' => 'array',
            'default' => Options::defaults(),
            'sanitize_callback' => [$this, 'validate_settings'],
        ]);

        \add_settings_section(
            'lsky_pro_settings_section',
            '基本设置',
            [$this, 'settings_section_callback'],
            'lsky-pro-settings'
        );

        $fields = [
            ['id' => 'lsky_pro_api_url', 'title' => 'API地址', 'callback' => [$this, 'api_url_render']],
            ['id' => 'lsky_pro_token', 'title' => 'Token', 'callback' => [$this, 'token_render']],
            ['id' => 'storage_id', 'title' => '存储ID', 'callback' => [$this, 'storage_id_callback']],
            ['id' => 'album_id', 'title' => '相册', 'callback' => [$this, 'album_id_callback']],
            ['id' => 'process_remote_images', 'title' => '远程图片处理', 'callback' => [$this, 'process_remote_images_callback']],
            ['id' => 'exclude_site_icon', 'title' => '排除站点图标', 'callback' => [$this, 'exclude_site_icon_callback']],
            ['id' => 'exclude_ajax_actions', 'title' => '排除头像上传（AJAX action）', 'callback' => [$this, 'exclude_ajax_actions_callback']],
            ['id' => 'exclude_referer_contains', 'title' => '排除头像上传（Referer 关键字）', 'callback' => [$this, 'exclude_referer_contains_callback']],
        ];

        foreach ($fields as $field) {
            \add_settings_field(
                (string) $field['id'],
                (string) $field['title'],
                $field['callback'],
                'lsky-pro-settings',
                'lsky_pro_settings_section'
            );
        }
    }

    public function api_url_render(): void
    {
        $options = Options::normalized();
        ?>
        <div class="lsky-form-group">
            <input
                type="url"
                name="lsky_pro_options[lsky_pro_api_url]"
                value="<?php echo \esc_attr((string) ($options['lsky_pro_api_url'] ?? '')); ?>"
                class="lsky-input"
                placeholder="https://your-domain.com/api/v2"
                required
            >
            <p class="description">
                示例：<code>https://your-domain.com/api/v2</code>
            </p>
        </div>
        <?php
    }

    public function token_render(): void
    {
        $options = Options::normalized();
        ?>
        <div class="lsky-form-group">
            <input
                type="text"
                name="lsky_pro_options[lsky_pro_token]"
                value="<?php echo \esc_attr((string) ($options['lsky_pro_token'] ?? '')); ?>"
                class="lsky-input"
                placeholder="在 LskyPro 后台生成的访问令牌"
                required
            >
            <p class="description">
                用于上传鉴权，建议仅授予必要权限<br>
                示例：<code>1|JVaaB4K7ves2G16DU2O9YvjCO9m8c9SmM7eRXt86cb22710f</code>
            </p>
        </div>
        <?php
    }

    public function storage_id_callback(): void
    {
        $options = Options::normalized();
        $storageId = isset($options['storage_id']) ? (string) $options['storage_id'] : '1';

        $uploader = new Uploader();
        $storages = $uploader->get_strategies();

        if ($storages === false) {
            echo '<input class="lsky-input-small" type="number" name="lsky_pro_options[storage_id]" value="' . \esc_attr($storageId) . '" min="1">';
            echo '<p class="description" style="color: #dc3232;">获取存储策略失败：' . \esc_html($uploader->getError()) . '</p>';
            return;
        }

        if (empty($storages)) {
            echo '<input class="lsky-input-small" type="number" name="lsky_pro_options[storage_id]" value="' . \esc_attr($storageId) . '" min="1">';
            echo '<p class="description" style="color: #d63638;">未获取到存储列表，可先手动填写存储 ID。</p>';
            return;
        }

        $availableIds = [];
        foreach ($storages as $s) {
            if (\is_array($s) && isset($s['id'])) {
                $availableIds[] = (string) $s['id'];
            }
        }
        if (!empty($availableIds) && !\in_array((string) $storageId, $availableIds, true)) {
            $storageId = $availableIds[0];
        }

        ?>
        <select class="lsky-select" name='lsky_pro_options[storage_id]' id='lsky_pro_storage_id'>
            <?php foreach ($storages as $storage): ?>
                <?php
                $id = isset($storage['id']) ? $storage['id'] : '';
                $name = isset($storage['name']) ? $storage['name'] : '';
                ?>
                <option value='<?php echo \esc_attr((string) $id); ?>' <?php \selected($storageId, (string) $id); ?>>
                    <?php echo \esc_html($name !== '' ? (string) $name : ('ID: ' . (string) $id)); ?> (ID: <?php echo \esc_html((string) $id); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function album_id_callback(): void
    {
        $options = Options::normalized();
        $albumId = isset($options['album_id']) ? \absint($options['album_id']) : 0;

        $uploader = new Uploader();
        $albums = $uploader->get_all_albums('', 100);

        if ($albums === false) {
            ?>
            <select class="lsky-select" name='lsky_pro_options[album_id]' id='lsky_pro_album_id'>
                <?php if ($albumId > 0): ?>
                    <option value='<?php echo \esc_attr((string) $albumId); ?>' selected>
                        当前相册 ID: <?php echo \esc_html((string) $albumId); ?>（无法加载相册列表）
                    </option>
                <?php endif; ?>
                <option value='0' <?php \selected($albumId, 0); ?>>不指定相册（不上传 album_id）</option>
            </select>
            <p class="description" style="color: #dc3232;">获取相册列表失败：<?php echo \esc_html($uploader->getError()); ?></p>
            <p class="description">用于上传时可选携带 <code>album_id</code>；未指定则不会携带该字段。</p>
            <?php
            return;
        }

        $availableIds = [];
        foreach ($albums as $a) {
            if (\is_array($a) && isset($a['id'])) {
                $availableIds[] = (int) $a['id'];
            }
        }
        $availableIds = \array_values(\array_unique(\array_filter($availableIds)));
        if (!empty($availableIds) && $albumId > 0 && !\in_array($albumId, $availableIds, true)) {
            $albumId = 0;
        }

        ?>
        <select class="lsky-select" name='lsky_pro_options[album_id]' id='lsky_pro_album_id'>
            <option value='0' <?php \selected($albumId, 0); ?>>不指定相册（不上传 album_id）</option>
            <?php if (empty($albums)): ?>
                <option value="" disabled selected>未获取到任何相册（请检查 Token 权限或接口返回）</option>
            <?php endif; ?>
            <?php foreach ($albums as $album): ?>
                <?php
                if (!\is_array($album)) {
                    continue;
                }
                $id = isset($album['id']) ? (int) $album['id'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $name = isset($album['name']) ? (string) $album['name'] : '';
                $intro = isset($album['intro']) ? (string) $album['intro'] : '';
                $label = $name !== '' ? $name : ('相册 ' . $id);
                if ($intro !== '') {
                    $label .= ' - ' . $intro;
                }
                ?>
                <option value='<?php echo \esc_attr((string) $id); ?>' <?php \selected($albumId, $id); ?>>
                    <?php echo \esc_html($label); ?> (ID: <?php echo \esc_html((string) $id); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (empty($albums)): ?>
            <p class="description" style="color: #d63638;">
                未获取到任何相册。请确认 Token 具备读取相册权限，或接口返回结构与预期不一致。<?php echo $uploader->getError() ? '（提示：' . \esc_html($uploader->getError()) . '）' : ''; ?>
            </p>
        <?php endif; ?>
        <p class="description">用于上传时可选携带 <code>album_id</code>；未指定则不会携带该字段。</p>
        <?php
    }

    public function settings_section_callback(): void
    {
        ?>
        <div class="alert alert-info border-0 shadow-sm">
            <div class="d-flex align-items-start">
                <i class="dashicons dashicons-info" style="font-size: 20px; margin-right: 10px; margin-top: 2px;"></i>
                <div>
                    <strong>配置说明</strong>
                    <p class="mb-0 mt-1">请填写 LskyPro 图床的连接信息。保存后会自动验证 Token 有效性。</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function process_remote_images_callback(): void
    {
        $options = Options::normalized();
        ?>
        <div class="lsky-form-group">
            <label class="lsky-checkbox">
                <input
                    type='checkbox'
                    id="lsky_pro_process_remote_images"
                    name='lsky_pro_options[process_remote_images]'
                    value="1"
                    <?php \checked(isset($options['process_remote_images']) && (int) $options['process_remote_images'] === 1); ?>
                >
                <span>自动处理文章中的远程图片</span>
            </label>
            <p class="description">保存文章时，自动将远程图片上传到图床</p>
        </div>
        <?php
    }

    public function exclude_site_icon_callback(): void
    {
        $options = Options::normalized();
        ?>
        <div class="lsky-form-group">
            <label class="lsky-checkbox">
                <input
                    type='checkbox'
                    id="lsky_pro_exclude_site_icon"
                    name='lsky_pro_options[exclude_site_icon]'
                    value="1"
                    <?php \checked(isset($options['exclude_site_icon']) && (int) $options['exclude_site_icon'] === 1); ?>
                >
                <span>站点图标不上传图床，保持本地文件</span>
            </label>
            <p class="description" style="color: #d63638;">建议开启：站点图标上传/裁剪流程通常依赖本地文件，上传图床并删除本地文件可能导致异常</p>
        </div>
        <?php
    }

    public function exclude_ajax_actions_callback(): void
    {
        $options = Options::normalized();
        $value = isset($options['exclude_ajax_actions']) ? (string) $options['exclude_ajax_actions'] : '';
        ?>
        <div class="lsky-form-group">
            <textarea
                class="lsky-textarea"
                name="lsky_pro_options[exclude_ajax_actions]"
                placeholder="avatar&#10;"
            ><?php echo \esc_textarea($value); ?></textarea>
            <p class="description">一行一个关键字：当 <code>admin-ajax.php</code> 上传请求的 <code>action</code> 包含该关键字时，跳过图床上传。默认已包含 <code>avatar</code></p>
        </div>
        <?php
    }

    public function exclude_referer_contains_callback(): void
    {
        $options = Options::normalized();
        $value = isset($options['exclude_referer_contains']) ? (string) $options['exclude_referer_contains'] : '';
        ?>
        <div class="lsky-form-group">
            <textarea
                class="lsky-textarea"
                name="lsky_pro_options[exclude_referer_contains]"
                placeholder="/user/&#10;/order&#10;"
            ><?php echo \esc_textarea($value); ?></textarea>
            <p class="description">可选：一行一个关键字，若上传请求的 Referer URL 包含该关键字，则跳过图床上传。适合用户中心上传头像但 action 不含 avatar 的场景</p>
        </div>
        <?php
    }
}
