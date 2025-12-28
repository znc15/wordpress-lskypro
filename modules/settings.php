<?php

if (!defined('ABSPATH')) {
    exit;
}

function lsky_pro_default_options() {
    return array(
        'lsky_pro_api_url' => '',
        'lsky_pro_token' => '',
        'storage_id' => '1',
        // 全局默认相册（0 表示不指定）
        'album_id' => '0',
        'process_remote_images' => 0,

        // 上传排除：默认排除站点图标；头像/用户中心上传可按 action/referer 配置排除。
        'exclude_site_icon' => 1,
        // 一行一个关键字，命中（doing_ajax 且 action 包含关键字）则跳过图床上传。
        'exclude_ajax_actions' => "avatar\n",
        // 一行一个关键字，命中 referer 则跳过图床上传（可选）。
        'exclude_referer_contains' => '',
    );
}

function lsky_pro_get_options_normalized($options = null) {
    if (!is_array($options)) {
        $options = get_option('lsky_pro_options');
    }
    if (!is_array($options)) {
        $options = array();
    }

    $defaults = lsky_pro_default_options();
    return array_merge($defaults, $options);
}

// 设置保存时的清洗与验证
function lsky_pro_validate_settings($input) {
    $previous = lsky_pro_get_options_normalized();

    if (!is_array($input)) {
        $input = array();
    }

    $clean = array();

    // URL / Token
    $api_url_raw = isset($input['lsky_pro_api_url']) ? (string) $input['lsky_pro_api_url'] : '';
    $api_url = trim(esc_url_raw($api_url_raw));
    $api_url = rtrim($api_url, '/');

    // 强制新版接口：必须以 /api/v2 结尾
    if ($api_url !== '' && !preg_match('~/api/v2$~', $api_url)) {
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_api_url_error',
            'API 地址必须以 /api/v2 结尾，例如：https://your-domain.com/api/v2',
            'error'
        );
        return $previous;
    }

    $clean['lsky_pro_api_url'] = $api_url;

    $token_raw = isset($input['lsky_pro_token']) ? (string) $input['lsky_pro_token'] : '';
    $token = trim(sanitize_text_field($token_raw));
    $clean['lsky_pro_token'] = $token;

    // 存储 ID
    $storage_id = isset($input['storage_id']) ? absint($input['storage_id']) : 0;
    if ($storage_id <= 0) {
        $storage_id = 1;
    }
    $clean['storage_id'] = (string) $storage_id;

    // 相册 ID（0 表示不指定）
    $album_id = isset($input['album_id']) ? absint($input['album_id']) : 0;
    $clean['album_id'] = (string) $album_id;

    // 复选框：未勾选时 WordPress 不会提交该键
    $clean['process_remote_images'] = (!empty($input['process_remote_images']) && (string) $input['process_remote_images'] === '1') ? 1 : 0;

    // 上传排除
    $clean['exclude_site_icon'] = (!empty($input['exclude_site_icon']) && (string) $input['exclude_site_icon'] === '1') ? 1 : 0;

    $exclude_actions_raw = isset($input['exclude_ajax_actions']) ? (string) $input['exclude_ajax_actions'] : '';
    $exclude_actions_raw = str_replace(array("\r\n", "\r"), "\n", $exclude_actions_raw);
    $lines = array_filter(array_map('trim', explode("\n", $exclude_actions_raw)), function($v) {
        return $v !== '';
    });
    $clean['exclude_ajax_actions'] = $lines ? implode("\n", $lines) . "\n" : '';

    $exclude_referer_raw = isset($input['exclude_referer_contains']) ? (string) $input['exclude_referer_contains'] : '';
    $exclude_referer_raw = str_replace(array("\r\n", "\r"), "\n", $exclude_referer_raw);
    $lines = array_filter(array_map('trim', explode("\n", $exclude_referer_raw)), function($v) {
        return $v !== '';
    });
    $clean['exclude_referer_contains'] = $lines ? implode("\n", $lines) . "\n" : '';

    // Cron 密码（如果有被提交）：只保存 hash 到独立 option
    if (!empty($input['cron_password'])) {
        $current_hash = (string) get_option('lsky_pro_cron_password', '');
        $new_hash = wp_hash_password((string) $input['cron_password']);
        if ($current_hash !== $new_hash) {
            update_option('lsky_pro_cron_password', $new_hash);
        }
    }

    // 若 URL/Token 不完整，不做远端验证，直接保存清洗后的值
    if ($api_url === '' || $token === '') {
        return array_merge($previous, $clean);
    }

    // 验证 Token：优先用 WP HTTP API（避免依赖 curl 扩展）
    $profile_url = $api_url . '/user/profile';
    $response = wp_remote_get(
        $profile_url,
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        )
    );

    if (is_wp_error($response)) {
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_error',
            'Token 验证失败：' . $response->get_error_message(),
            'error'
        );
        return $previous;
    }

    $http_code = (int) wp_remote_retrieve_response_code($response);
    $body_raw = (string) wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_error',
            'Token 验证失败（HTTP ' . $http_code . '），请检查 API 地址和 Token 是否正确',
            'error'
        );
        return $previous;
    }

    $result = json_decode($body_raw, true);
    if (!is_array($result)) {
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_error',
            'API 响应解析失败，请检查设置',
            'error'
        );
        return $previous;
    }

    if (!isset($result['status']) || $result['status'] !== 'success') {
        $msg = isset($result['message']) ? (string) $result['message'] : 'API 响应异常，请检查设置';
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_error',
            $msg,
            'error'
        );
        return $previous;
    }

    // 校验相册 ID：不指定(0) 允许；指定时必须在相册列表中。
    if ($album_id > 0) {
        $uploader = new LskyProUploader();
        $albums = $uploader->get_all_albums('', 100);
        if ($albums === false) {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_album_error',
                '相册列表获取失败，无法保存相册选择：' . $uploader->getError(),
                'error'
            );
            // 不阻断其它设置，但保持旧相册值
            $clean['album_id'] = (string) ($previous['album_id'] ?? '0');
        } else {
            $found = false;
            foreach ($albums as $a) {
                if (is_array($a) && isset($a['id']) && (int) $a['id'] === $album_id) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                add_settings_error(
                    'lsky_pro_options',
                    'lsky_pro_album_error',
                    '相册不存在或无权限访问，无法保存相册选择（ID: ' . $album_id . '）',
                    'error'
                );
                $clean['album_id'] = (string) ($previous['album_id'] ?? '0');
            }
        }
    }

    add_settings_error(
        'lsky_pro_options',
        'lsky_pro_token_success',
        '设置已保存，Token 验证成功！',
        'success'
    );

    return array_merge($previous, $clean);
}

// 修改注册设置部分
function lsky_pro_settings_init() {
    // 注册设置
    register_setting('lsky_pro_options', 'lsky_pro_options', array(
        'type' => 'array',
        'default' => lsky_pro_default_options(),
        'sanitize_callback' => 'lsky_pro_validate_settings',
    ));

    // 添加设置区块
    add_settings_section(
        'lsky_pro_settings_section',
        '基本设置',
        'lsky_pro_settings_section_callback',
        'lsky-pro-settings'
    );

    // 添加设置字段
    $fields = array(
        array(
            'id' => 'lsky_pro_api_url',
            'title' => 'API地址',
            'callback' => 'lsky_pro_api_url_render'
        ),
        array(
            'id' => 'lsky_pro_token',
            'title' => 'Token',
            'callback' => 'lsky_pro_token_render'
        ),
        array(
            'id' => 'storage_id',
            'title' => '存储ID',
            'callback' => 'lsky_pro_storage_id_callback'
        ),
        array(
            'id' => 'album_id',
            'title' => '相册',
            'callback' => 'lsky_pro_album_id_callback'
        ),
        array(
            'id' => 'process_remote_images',
            'title' => '远程图片处理',
            'callback' => 'lsky_pro_process_remote_images_callback'
        ),
        array(
            'id' => 'exclude_site_icon',
            'title' => '排除站点图标',
            'callback' => 'lsky_pro_exclude_site_icon_callback'
        ),
        array(
            'id' => 'exclude_ajax_actions',
            'title' => '排除头像上传（AJAX action）',
            'callback' => 'lsky_pro_exclude_ajax_actions_callback'
        ),
        array(
            'id' => 'exclude_referer_contains',
            'title' => '排除头像上传（Referer 关键字）',
            'callback' => 'lsky_pro_exclude_referer_contains_callback'
        )
    );

    // 注册所有字段
    foreach ($fields as $field) {
        add_settings_field(
            $field['id'],
            $field['title'],
            $field['callback'],
            'lsky-pro-settings',
            'lsky_pro_settings_section'
        );
    }
}
add_action('admin_init', 'lsky_pro_settings_init');

// 更新设置字段渲染函数
function lsky_pro_api_url_render() {
    $options = lsky_pro_get_options_normalized();
    ?>
    <div class="input-group" style="max-width: 520px;">
        <span class="input-group-text">URL</span>
        <input
            type="url"
            name="lsky_pro_options[lsky_pro_api_url]"
            value="<?php echo esc_attr($options['lsky_pro_api_url'] ?? ''); ?>"
            class="form-control regular-text"
            placeholder="https://your-domain.com/api/v2"
            required
        >
    </div>
    <p class="description">示例：<code>https://your-domain.com/api/v2</code></p>
    <?php
}

function lsky_pro_token_render() {
    $options = lsky_pro_get_options_normalized();
    ?>
    <div class="input-group" style="max-width: 520px;">
        <span class="input-group-text">Token</span>
        <input
            type="text"
            name="lsky_pro_options[lsky_pro_token]"
            value="<?php echo esc_attr($options['lsky_pro_token'] ?? ''); ?>"
            class="form-control regular-text"
            placeholder="在 LskyPro 后台生成的访问令牌"
            required
        >
    </div>
    <p class="description">用于上传鉴权。建议仅授予必要权限。</p>

    <p class="description">示例：<code>1|JVaaB4K7ves2G16DU2O9YvjCO9m8c9SmM7eRXt86cb22710f</code></p>
    <?php
}

function lsky_pro_storage_id_callback() {
    $options = lsky_pro_get_options_normalized();
    $storage_id = isset($options['storage_id']) ? (string) $options['storage_id'] : '1';

    // 获取存储列表（统一走 uploader）
    $uploader = new LskyProUploader();
    $storages = $uploader->get_strategies();

    if ($storages === false) {
        echo '<input class="form-control" style="max-width: 220px;" type="number" name="lsky_pro_options[storage_id]" value="' . esc_attr($storage_id) . '" min="1">';
        echo '<div class="alert alert-danger mt-2 mb-0" style="max-width: 520px;">获取存储策略失败：' . esc_html($uploader->getError()) . '</div>';
        return;
    }

    if (empty($storages)) {
        echo '<input class="form-control" style="max-width: 220px;" type="number" name="lsky_pro_options[storage_id]" value="' . esc_attr($storage_id) . '" min="1">';
        echo '<div class="alert alert-warning mt-2 mb-0" style="max-width: 520px;">未获取到存储列表，可先手动填写存储 ID。</div>';
        return;
    }

    // 如果当前配置的 id 不在列表中，默认选中第一个可用 id
    $available_ids = array();
    foreach ($storages as $s) {
        if (is_array($s) && isset($s['id'])) {
            $available_ids[] = (string) $s['id'];
        }
    }
    if (!empty($available_ids) && !in_array((string) $storage_id, $available_ids, true)) {
        $storage_id = $available_ids[0];
    }

    // 显示下拉选择框
    ?>
    <select class="form-select" style="max-width: 520px;" name='lsky_pro_options[storage_id]' id='lsky_pro_storage_id'>
        <?php foreach ($storages as $storage): ?>
            <?php
            $id = isset($storage['id']) ? $storage['id'] : '';
            $name = isset($storage['name']) ? $storage['name'] : '';
            ?>
            <option value='<?php echo esc_attr($id); ?>' 
                    <?php selected($storage_id, $id); ?>>
                <?php echo esc_html($name !== '' ? $name : ('ID: ' . $id)); ?>
                (ID: <?php echo esc_html($id); ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

function lsky_pro_album_id_callback() {
    $options = lsky_pro_get_options_normalized();
    $album_id = isset($options['album_id']) ? absint($options['album_id']) : 0;

    $uploader = new LskyProUploader();
    $albums = $uploader->get_all_albums('', 100);

    // 不做“手动输入”降级：仅提供下拉选择。
    if ($albums === false) {
        ?>
        <select class="form-select" style="max-width: 520px;" name='lsky_pro_options[album_id]' id='lsky_pro_album_id'>
            <?php if ($album_id > 0): ?>
                <option value='<?php echo esc_attr((string) $album_id); ?>' selected>
                    <?php echo esc_html('当前相册 ID: ' . $album_id . '（无法加载相册列表）'); ?>
                </option>
            <?php endif; ?>
            <option value='0' <?php selected($album_id, 0); ?>>不指定相册（不上传 album_id）</option>
        </select>
        <div class="notice notice-error inline" style="max-width: 520px;"><p>获取相册列表失败：<?php echo esc_html($uploader->getError()); ?></p></div>
        <p class="description">用于上传时可选携带 <code>album_id</code>；未指定则不会携带该字段。</p>
        <?php
        return;
    }

    $available_ids = array();
    foreach ($albums as $a) {
        if (is_array($a) && isset($a['id'])) {
            $available_ids[] = (int) $a['id'];
        }
    }
    $available_ids = array_values(array_unique(array_filter($available_ids)));
    if (!empty($available_ids) && $album_id > 0 && !in_array($album_id, $available_ids, true)) {
        $album_id = 0;
    }

    ?>
    <select class="form-select" style="max-width: 520px;" name='lsky_pro_options[album_id]' id='lsky_pro_album_id'>
        <option value='0' <?php selected($album_id, 0); ?>>不指定相册（不上传 album_id）</option>
        <?php if (empty($albums)): ?>
            <option value="" disabled selected>未获取到任何相册（请检查 Token 权限或接口返回）</option>
        <?php endif; ?>
        <?php foreach ($albums as $album): ?>
            <?php
            if (!is_array($album)) {
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
            <option value='<?php echo esc_attr((string) $id); ?>' <?php selected($album_id, $id); ?>>
                <?php echo esc_html($label); ?> (ID: <?php echo esc_html((string) $id); ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (empty($albums)): ?>
        <div class="notice notice-warning inline" style="max-width: 520px;"><p>
            未获取到任何相册。请确认 Token 具备读取相册权限，或接口返回结构与预期不一致。<?php echo $uploader->getError() ? '（提示：' . esc_html($uploader->getError()) . '）' : ''; ?>
        </p></div>
    <?php endif; ?>
    <p class="description">用于上传时可选携带 <code>album_id</code>；未指定则不会携带该字段。</p>
    <?php
}

function lsky_pro_settings_section_callback() {
    echo '<div class="alert alert-info" style="max-width: 760px;">请填写 LskyPro 图床的连接信息。保存后会进行一次 Token 验证。</div>';
}

function lsky_pro_process_remote_images_callback() {
    $options = lsky_pro_get_options_normalized();
    ?>
    <div class="form-check">
        <input
            class="form-check-input"
            type='checkbox'
            id="lsky_pro_process_remote_images"
            name='lsky_pro_options[process_remote_images]'
            value="1"
            <?php checked(isset($options['process_remote_images']) && $options['process_remote_images'] == 1); ?>
        >
        <label class="form-check-label" for="lsky_pro_process_remote_images">自动处理文章中的远程图片</label>
    </div>
    <p class="description">保存文章时，自动将远程图片上传到图床。</p>
    <?php
}

function lsky_pro_exclude_site_icon_callback() {
    $options = lsky_pro_get_options_normalized();
    ?>
    <div class="form-check">
        <input
            class="form-check-input"
            type='checkbox'
            id="lsky_pro_exclude_site_icon"
            name='lsky_pro_options[exclude_site_icon]'
            value="1"
            <?php checked(isset($options['exclude_site_icon']) && (int) $options['exclude_site_icon'] === 1); ?>
        >
        <label class="form-check-label" for="lsky_pro_exclude_site_icon">站点图标（Site Icon）不上传图床，保持本地文件</label>
    </div>
    <p class="description">建议开启：站点图标上传/裁剪流程通常依赖本地文件，上传图床并删除本地文件可能导致异常。</p>
    <?php
}

function lsky_pro_exclude_ajax_actions_callback() {
    $options = lsky_pro_get_options_normalized();
    $value = isset($options['exclude_ajax_actions']) ? (string) $options['exclude_ajax_actions'] : '';
    ?>
    <textarea
        class="form-control"
        style="max-width: 520px; min-height: 90px;"
        name="lsky_pro_options[exclude_ajax_actions]"
        placeholder="avatar\n"
    ><?php echo esc_textarea($value); ?></textarea>
    <p class="description">一行一个关键字：当 <code>admin-ajax.php</code> 上传请求的 <code>action</code> 包含该关键字时，跳过图床上传。默认已包含 <code>avatar</code>。</p>
    <?php
}

function lsky_pro_exclude_referer_contains_callback() {
    $options = lsky_pro_get_options_normalized();
    $value = isset($options['exclude_referer_contains']) ? (string) $options['exclude_referer_contains'] : '';
    ?>
    <textarea
        class="form-control"
        style="max-width: 520px; min-height: 90px;"
        name="lsky_pro_options[exclude_referer_contains]"
        placeholder="/user/\n/order\n"
    ><?php echo esc_textarea($value); ?></textarea>
    <p class="description">可选：一行一个关键字，若上传请求的 Referer URL 包含该关键字，则跳过图床上传。适合用户中心上传头像但 action 不含 avatar 的场景。</p>
    <?php
}
