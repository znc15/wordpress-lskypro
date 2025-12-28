<?php

if (!defined('ABSPATH')) {
    exit;
}

function lsky_pro_default_options() {
    return array(
        'lsky_pro_api_url' => '',
        'lsky_pro_token' => '',
        'storage_id' => '1',
        'process_remote_images' => 0,
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

    // 复选框：未勾选时 WordPress 不会提交该键
    $clean['process_remote_images'] = (!empty($input['process_remote_images']) && (string) $input['process_remote_images'] === '1') ? 1 : 0;

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
            'id' => 'process_remote_images',
            'title' => '远程图片处理',
            'callback' => 'lsky_pro_process_remote_images_callback'
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
    <p class="description">示例：<code>https://your-domain.com/api/v2</code>（通常为 LskyPro v2）。</p>
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
