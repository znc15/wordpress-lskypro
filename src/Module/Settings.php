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

        $defaultStorageAdmin = isset($input['default_storage_id_admin']) ? \absint($input['default_storage_id_admin']) : 0;
        $defaultStorageUser = isset($input['default_storage_id_user']) ? \absint($input['default_storage_id_user']) : 0;
        $clean['default_storage_id_admin'] = (string) $defaultStorageAdmin;
        $clean['default_storage_id_user'] = (string) $defaultStorageUser;

        $defaultAlbumAdmin = isset($input['default_album_id_admin']) ? \absint($input['default_album_id_admin']) : 0;
        $defaultAlbumUser = isset($input['default_album_id_user']) ? \absint($input['default_album_id_user']) : 0;
        $clean['default_album_id_admin'] = (string) $defaultAlbumAdmin;
        $clean['default_album_id_user'] = (string) $defaultAlbumUser;

        $roles = \function_exists('wp_roles') ? \wp_roles() : null;
        $roleNames = ($roles && isset($roles->role_names) && \is_array($roles->role_names)) ? $roles->role_names : [];
        $validRoleKeys = \array_keys($roleNames);

        $sanitizeRoleGroup = static function ($raw) use ($validRoleKeys): array {
            if (\is_string($raw)) {
                $raw = \array_filter(\array_map('trim', \explode("\n", \str_replace(["\r\n", "\r"], "\n", $raw))));
            }
            if (!\is_array($raw)) {
                return [];
            }
            $keys = [];
            foreach ($raw as $r) {
                $k = \sanitize_key((string) $r);
                if ($k !== '' && (empty($validRoleKeys) || \in_array($k, $validRoleKeys, true))) {
                    $keys[] = $k;
                }
            }
            return \array_values(\array_unique($keys));
        };

        $clean['admin_role_group'] = $sanitizeRoleGroup($input['admin_role_group'] ?? []);
        $clean['user_role_group'] = $sanitizeRoleGroup($input['user_role_group'] ?? []);

        $clean['delete_remote_images_on_post_delete'] = (!empty($input['delete_remote_images_on_post_delete']) && (string) $input['delete_remote_images_on_post_delete'] === '1') ? 1 : 0;
        $clean['delete_wp_attachments_on_post_delete'] = (!empty($input['delete_wp_attachments_on_post_delete']) && (string) $input['delete_wp_attachments_on_post_delete'] === '1') ? 1 : 0;

        $clean['delete_local_files_after_upload'] = (!empty($input['delete_local_files_after_upload']) && (string) $input['delete_local_files_after_upload'] === '1') ? 1 : 0;

        $clean['disable_wp_image_sizes'] = (!empty($input['disable_wp_image_sizes']) && (string) $input['disable_wp_image_sizes'] === '1') ? 1 : 0;

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

        $rawRules = $input['keyword_routing_rules'] ?? [];
        $keywordRules = [];
        if (\is_array($rawRules)) {
            foreach ($rawRules as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }

                $keywordsRaw = $rule['keywords'] ?? '';
                $keywordsText = \is_array($keywordsRaw) ? \implode("\n", $keywordsRaw) : (string) $keywordsRaw;
                $keywordsText = \str_replace(["\r\n", "\r"], "\n", $keywordsText);
                $parts = \preg_split('/[,\n]+/', $keywordsText);
                if (!\is_array($parts)) {
                    $parts = [];
                }

                $keywords = [];
                foreach ($parts as $part) {
                    $part = \sanitize_text_field((string) $part);
                    $part = \strtolower(\trim($part));
                    if ($part !== '') {
                        $keywords[] = $part;
                    }
                }
                $keywords = \array_values(\array_unique($keywords));

                if (empty($keywords)) {
                    continue;
                }

                $storageId = \absint($rule['storage_id'] ?? 0);
                if ($storageId <= 0) {
                    continue;
                }

                $albumIdRaw = $rule['album_id'] ?? '';
                $albumId = $albumIdRaw === '' ? 0 : \absint($albumIdRaw);

                $keywordRules[] = [
                    'keywords' => $keywords,
                    'storage_id' => (string) $storageId,
                    'album_id' => (string) $albumId,
                ];
            }
        }
        $clean['keyword_routing_rules'] = $keywordRules;

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
            ['id' => 'default_storage_id_admin', 'title' => '管理员默认存储策略', 'callback' => [$this, 'default_storage_admin_callback']],
            ['id' => 'default_storage_id_user', 'title' => '普通用户默认存储策略', 'callback' => [$this, 'default_storage_user_callback']],
            ['id' => 'default_album_id_admin', 'title' => '管理员默认相册', 'callback' => [$this, 'default_album_admin_callback']],
            ['id' => 'default_album_id_user', 'title' => '普通用户默认相册', 'callback' => [$this, 'default_album_user_callback']],
            ['id' => 'keyword_routing_rules', 'title' => '关键字策略规则', 'callback' => [$this, 'keyword_routing_rules_callback']],
            ['id' => 'admin_role_group', 'title' => '管理员用户组（WP 角色）', 'callback' => [$this, 'admin_role_group_callback']],
            ['id' => 'user_role_group', 'title' => '普通用户组（WP 角色）', 'callback' => [$this, 'user_role_group_callback']],
            ['id' => 'process_remote_images', 'title' => '远程图片处理', 'callback' => [$this, 'process_remote_images_callback']],
            ['id' => 'delete_remote_images_on_post_delete', 'title' => '删除文章时删除图床图片', 'callback' => [$this, 'delete_remote_images_on_post_delete_callback']],
            ['id' => 'delete_wp_attachments_on_post_delete', 'title' => '删除文章时删除媒体库附件', 'callback' => [$this, 'delete_wp_attachments_on_post_delete_callback']],
            ['id' => 'delete_local_files_after_upload', 'title' => '上传后删除本地文件', 'callback' => [$this, 'delete_local_files_after_upload_callback']],
            ['id' => 'disable_wp_image_sizes', 'title' => '缩略图/中间尺寸生成', 'callback' => [$this, 'disable_wp_image_sizes_callback']],
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

    private function renderStorageSelect(string $name, int $selectedId, string $inheritLabel): void
    {
        $uploader = new Uploader();
        $storages = $uploader->get_strategies();

        if ($storages === false || !\is_array($storages) || empty($storages)) {
            echo '<input class="lsky-input-small" type="number" name="lsky_pro_options[' . \esc_attr($name) . ']" value="' . \esc_attr((string) $selectedId) . '" min="0">';
            echo '<p class="description">0=继承全局；>0=指定 Storage ID。' . ($storages === false ? ('（获取列表失败：' . \esc_html($uploader->getError()) . '）') : '') . '</p>';
            return;
        }

        echo '<select class="lsky-select" name="lsky_pro_options[' . \esc_attr($name) . ']">';
        echo '<option value="0" ' . \selected($selectedId, 0, false) . '>' . \esc_html($inheritLabel) . '</option>';
        foreach ($storages as $storage) {
            if (!\is_array($storage)) {
                continue;
            }
            $id = isset($storage['id']) ? (int) $storage['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $label = isset($storage['name']) ? (string) $storage['name'] : '';
            $label = $label !== '' ? $label : ('ID: ' . (string) $id);
            echo '<option value="' . \esc_attr((string) $id) . '" ' . \selected($selectedId, $id, false) . '>' . \esc_html($label) . ' (ID: ' . \esc_html((string) $id) . ')</option>';
        }
        echo '</select>';
    }

    private function renderAlbumSelect(string $name, int $selectedId, string $inheritLabel): void
    {
        $uploader = new Uploader();
        $albums = $uploader->get_all_albums('', 100);

        if ($albums === false || !\is_array($albums)) {
            echo '<input class="lsky-input-small" type="number" name="lsky_pro_options[' . \esc_attr($name) . ']" value="' . \esc_attr((string) $selectedId) . '" min="0">';
            echo '<p class="description">0=继承全局；>0=指定 album_id。' . ($albums === false ? ('（获取列表失败：' . \esc_html($uploader->getError()) . '）') : '') . '</p>';
            return;
        }

        echo '<select class="lsky-select" name="lsky_pro_options[' . \esc_attr($name) . ']">';
        echo '<option value="0" ' . \selected($selectedId, 0, false) . '>' . \esc_html($inheritLabel) . '</option>';
        foreach ($albums as $album) {
            if (!\is_array($album)) {
                continue;
            }
            $id = isset($album['id']) ? (int) $album['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $label = isset($album['name']) ? (string) $album['name'] : '';
            $label = $label !== '' ? $label : ('ID: ' . (string) $id);
            echo '<option value="' . \esc_attr((string) $id) . '" ' . \selected($selectedId, $id, false) . '>' . \esc_html($label) . ' (ID: ' . \esc_html((string) $id) . ')</option>';
        }
        echo '</select>';
    }

    public function default_storage_admin_callback(): void
    {
        $options = Options::normalized();
        $selected = (int) \absint((string) ($options['default_storage_id_admin'] ?? '0'));
        $this->renderStorageSelect('default_storage_id_admin', $selected, '继承全局存储策略');
        echo '<p class="description">用于实现“管理员发文走带水印策略”。</p>';
    }

    public function default_storage_user_callback(): void
    {
        $options = Options::normalized();
        $selected = (int) \absint((string) ($options['default_storage_id_user'] ?? '0'));
        $this->renderStorageSelect('default_storage_id_user', $selected, '继承全局存储策略');
        echo '<p class="description">用于实现“普通用户发文走无水印策略”。</p>';
    }

    public function default_album_admin_callback(): void
    {
        $options = Options::normalized();
        $selected = (int) \absint((string) ($options['default_album_id_admin'] ?? '0'));
        $this->renderAlbumSelect('default_album_id_admin', $selected, '继承全局相册');
    }

    public function default_album_user_callback(): void
    {
        $options = Options::normalized();
        $selected = (int) \absint((string) ($options['default_album_id_user'] ?? '0'));
        $this->renderAlbumSelect('default_album_id_user', $selected, '继承全局相册');
    }

    public function keyword_routing_rules_callback(): void
    {
        $options = Options::normalized();
        $rules = $options['keyword_routing_rules'] ?? [];
        if (!\is_array($rules)) {
            $rules = [];
        }

        $rows = [];
        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $keywords = $rule['keywords'] ?? [];
            $keywordsText = \is_array($keywords) ? \implode("\n", $keywords) : (string) $keywords;
            $storageId = isset($rule['storage_id']) ? (string) $rule['storage_id'] : '';
            $albumId = isset($rule['album_id']) ? (string) $rule['album_id'] : '0';
            $rows[] = [
                'keywords' => $keywordsText,
                'storage_id' => $storageId,
                'album_id' => $albumId,
            ];
        }

        $nextIndex = \count($rows);
        ?>
        <div class="lsky-keyword-rules" data-next-index="<?php echo \esc_attr((string) $nextIndex); ?>">
            <table class="widefat striped lsky-keyword-rules-table">
                <thead>
                    <tr>
                        <th>关键字</th>
                        <th>存储策略 ID</th>
                        <th>相册 ID</th>
                        <th class="lsky-rule-actions">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr class="lsky-keyword-rules-empty">
                            <td colspan="4">暂无规则</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr class="lsky-keyword-rule-row">
                                <td>
                                    <textarea class="large-text" rows="2" name="lsky_pro_options[keyword_routing_rules][<?php echo \esc_attr((string) $i); ?>][keywords]"><?php echo \esc_textarea($row['keywords']); ?></textarea>
                                </td>
                                <td>
                                    <input class="lsky-input-small" type="number" min="1" name="lsky_pro_options[keyword_routing_rules][<?php echo \esc_attr((string) $i); ?>][storage_id]" value="<?php echo \esc_attr($row['storage_id']); ?>">
                                </td>
                                <td>
                                    <input class="lsky-input-small" type="number" min="0" name="lsky_pro_options[keyword_routing_rules][<?php echo \esc_attr((string) $i); ?>][album_id]" value="<?php echo \esc_attr($row['album_id']); ?>">
                                </td>
                                <td class="lsky-rule-actions">
                                    <button type="button" class="button lsky-rule-remove">删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button lsky-rule-add">添加规则</button>
            </p>
            <script type="text/html" class="lsky-rule-template">
                <tr class="lsky-keyword-rule-row">
                    <td>
                        <textarea class="large-text" rows="2" name="lsky_pro_options[keyword_routing_rules][__INDEX__][keywords]"></textarea>
                    </td>
                    <td>
                        <input class="lsky-input-small" type="number" min="1" name="lsky_pro_options[keyword_routing_rules][__INDEX__][storage_id]" value="">
                    </td>
                    <td>
                        <input class="lsky-input-small" type="number" min="0" name="lsky_pro_options[keyword_routing_rules][__INDEX__][album_id]" value="0">
                    </td>
                    <td class="lsky-rule-actions">
                        <button type="button" class="button lsky-rule-remove">删除</button>
                    </td>
                </tr>
            </script>
            <p class="description">关键词支持逗号或换行分隔；按顺序匹配文件名（basename），命中第一条即使用对应存储策略与相册（相册留空则继承默认）。</p>
        </div>
        <?php
    }

    public function delete_remote_images_on_post_delete_callback(): void
    {
        $options = Options::normalized();
        $checked = isset($options['delete_remote_images_on_post_delete']) && (int) $options['delete_remote_images_on_post_delete'] === 1;
        ?>
        <label>
            <input
                type="checkbox"
                name="lsky_pro_options[delete_remote_images_on_post_delete]"
                value="1"
                <?php \checked($checked); ?>
                onclick="if (this.checked) { return confirm('确定要开启“删除文章时删除图床图片”吗？\\n\\n注意：若同一图床图片被多个文章复用，开启后可能误删。'); } return true;"
            >
            文章被永久删除时（清空回收站/彻底删除），同时删除该文章关联的图床图片
        </label>
        <p class="description">包含：插件处理外链/本站媒体图片时上传到图床并记录的 photo_id；文章内容里引用到的媒体库附件（若附件已写入 <code>_lsky_pro_photo_id</code>）。注意：若同一附件/图床图在多个文章复用，开启后会一起删。</p>
        <?php
    }

    public function delete_wp_attachments_on_post_delete_callback(): void
    {
        $options = Options::normalized();
        $checked = isset($options['delete_wp_attachments_on_post_delete']) && (int) $options['delete_wp_attachments_on_post_delete'] === 1;
        ?>
        <label>
            <input
                type="checkbox"
                name="lsky_pro_options[delete_wp_attachments_on_post_delete]"
                value="1"
                <?php \checked($checked); ?>
                onclick="if (this.checked) { return confirm('危险操作：确定要开启“删除文章时删除媒体库附件”吗？\\n\\n提示：如果附件被多个文章复用，开启后会导致其他文章也失去该媒体。'); } return true;"
            >
            文章被永久删除时，同时删除该文章关联/引用到的媒体库附件（wp_delete_attachment）
        </label>
        <p class="description">危险操作：如果同一附件被多个文章复用，开启后会导致其他文章也失去该媒体。建议仅在你确认站点图片不复用或可接受的情况下开启。</p>
        <?php
    }

    public function delete_local_files_after_upload_callback(): void
    {
        $options = Options::normalized();
        $checked = isset($options['delete_local_files_after_upload']) && (int) $options['delete_local_files_after_upload'] === 1;
        ?>
        <label>
            <input type="checkbox" name="lsky_pro_options[delete_local_files_after_upload]" value="1" <?php \checked($checked); ?>>
            图片成功上传到图床并写入附件信息后，删除本地 uploads 原图与缩略图
        </label>
        <p class="description">谨慎开启：开启后媒体库中将不再保留本地文件，仅保留图床 URL（通过 <code>wp_get_attachment_url</code> 返回）。某些依赖本地文件的功能（如裁剪/重新生成缩略图/离线备份）可能不可用。</p>
        <?php
    }

    public function disable_wp_image_sizes_callback(): void
    {
        $options = Options::normalized();
        $checked = isset($options['disable_wp_image_sizes']) && (int) $options['disable_wp_image_sizes'] === 1;
        ?>
        <label>
            <input type="checkbox" name="lsky_pro_options[disable_wp_image_sizes]" value="1" <?php \checked($checked); ?>>
            禁用 WordPress 默认缩略图/中间尺寸生成（thumbnail/medium/large 等）
        </label>
        <p class="description">说明：该选项不会修改全站“媒体设置”里的尺寸选项，仅在运行时通过 filter 禁用默认尺寸生成。</p>
        <p class="description" style="color: #d63638;">谨慎开启：部分主题/插件可能依赖缩略图/中间尺寸与 srcset，禁用后可能影响显示或响应式图片效果。</p>
        <?php
    }

    private function renderRoleGroupCheckboxes(string $name, array $selected): void
    {
        $roles = \function_exists('wp_roles') ? \wp_roles() : null;
        $roleNames = ($roles && isset($roles->role_names) && \is_array($roles->role_names)) ? $roles->role_names : [];
        if (empty($roleNames)) {
            echo '<p class="description">无法获取站点角色列表。</p>';
            return;
        }

        foreach ($roleNames as $key => $label) {
            $key = \sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $isChecked = \in_array($key, $selected, true);
            echo '<label style="display:inline-block;margin-right:16px;">';
            echo '<input type="checkbox" name="lsky_pro_options[' . \esc_attr($name) . '][]" value="' . \esc_attr($key) . '" ' . \checked($isChecked, true, false) . '>';
            echo ' ' . \esc_html((string) $label) . ' <code>(' . \esc_html($key) . ')</code>';
            echo '</label>';
        }
    }

    public function admin_role_group_callback(): void
    {
        $options = Options::normalized();
        $selected = $options['admin_role_group'] ?? [];
        if (!\is_array($selected)) {
            $selected = [];
        }
        $this->renderRoleGroupCheckboxes('admin_role_group', $selected);
        echo '<p class="description">勾选哪些 WordPress 角色属于“管理员组”。命中该组的用户会走“管理员默认存储策略/相册”。</p>';
    }

    public function user_role_group_callback(): void
    {
        $options = Options::normalized();
        $selected = $options['user_role_group'] ?? [];
        if (!\is_array($selected)) {
            $selected = [];
        }
        $this->renderRoleGroupCheckboxes('user_role_group', $selected);
        echo '<p class="description">勾选哪些 WordPress 角色属于“普通用户组”。若用户同时命中两组，以“管理员组”为准。</p>';
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
