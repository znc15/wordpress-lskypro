<?php

if (!defined('ABSPATH')) {
    exit;
}

// 修改设置验证函数
function lsky_pro_validate_settings($input) {
    // 兼容字段：旧版使用 strategy_id，新版上传使用 storage_id
    if (!isset($input['storage_id']) && isset($input['strategy_id'])) {
        $input['storage_id'] = $input['strategy_id'];
    }

    $api_url = $input['lsky_pro_api_url'];
    $token = $input['lsky_pro_token'];

    if (!empty($api_url) && !empty($token)) {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => rtrim($api_url, '/') . '/user/profile',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token
            ),
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($http_code !== 200) {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_token_error',
                'Token验证失败，请检查API地址和Token是否正确',
                'error'
            );
            return get_option('lsky_pro_options');
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_token_error',
                'API响应异常，请检查设置',
                'error'
            );
            return get_option('lsky_pro_options');
        }

        if (isset($result['status']) && $result['status'] !== true && $result['status'] !== 'success') {
            add_settings_error(
                'lsky_pro_options',
                'lsky_pro_token_error',
                'API响应异常，请检查设置',
                'error'
            );
            return get_option('lsky_pro_options');
        }

        // 验证成功，显示成功消息
        add_settings_error(
            'lsky_pro_options',
            'lsky_pro_token_success',
            '设置已保存，Token验证成功！',
            'success'
        );
    }

    // 处理 Cron 密码
    if (!empty($input['cron_password'])) {
        // 如果密码有变化，则更新哈希值
        $current_hash = get_option('lsky_pro_cron_password');
        $new_hash = wp_hash_password($input['cron_password']);

        if ($current_hash !== $new_hash) {
            update_option('lsky_pro_cron_password', $new_hash);
        }

        // 从选项中移除明文密码
        unset($input['cron_password']);
    }

    return $input;
}

// 修改注册设置部分
function lsky_pro_settings_init() {
    // 注册设置
    register_setting('lsky_pro_options', 'lsky_pro_options', array(
        'type' => 'array',
        'default' => array(
            'lsky_pro_api_url' => '',
            'lsky_pro_token' => '',
            'strategy_id' => '1',
            'process_remote_images' => 0
        )
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
            'id' => 'strategy_id',
            'title' => '存储策略ID',
            'callback' => 'lsky_pro_strategy_id_callback'
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
    $options = get_option('lsky_pro_options');
    ?>
    <input type="text" name="lsky_pro_options[lsky_pro_api_url]" 
           value="<?php echo esc_attr($options['lsky_pro_api_url'] ?? ''); ?>" 
           class="regular-text" required>
    <p class="description">例如：https://your-domain.com/api/v2</p>
    <?php
}

function lsky_pro_token_render() {
    $options = get_option('lsky_pro_options');
    ?>
    <input type="text" name="lsky_pro_options[lsky_pro_token]" 
           value="<?php echo esc_attr($options['lsky_pro_token'] ?? ''); ?>" 
           class="regular-text" required>
    <p class="description">访问令牌，用于图片上传验证</p>
    <?php
}

function lsky_pro_strategy_id_callback() {
    $options = get_option('lsky_pro_options');
    $strategy_id = isset($options['strategy_id']) ? $options['strategy_id'] : '1';

    // 获取存储策略列表（统一走 uploader，便于兼容新版/旧版返回结构）
    $uploader = new LskyProUploader();
    $strategies = $uploader->get_strategies();

    if ($strategies === false) {
        echo '<input type="number" name="lsky_pro_options[strategy_id]" value="' . esc_attr($strategy_id) . '" min="1">';
        echo '<p class="description" style="color: #dc3232;">获取存储策略失败：' . esc_html($uploader->getError()) . '</p>';
        return;
    }

    if (empty($strategies)) {
        echo '<input type="number" name="lsky_pro_options[strategy_id]" value="' . esc_attr($strategy_id) . '" min="1">';
        echo '<p class="description">未获取到存储策略列表，请手动填写策略/存储 ID。</p>';
        return;
    }

    // 如果当前配置的 id 不在列表中，默认选中第一个可用 id
    $available_ids = array();
    foreach ($strategies as $s) {
        if (is_array($s) && isset($s['id'])) {
            $available_ids[] = (string) $s['id'];
        }
    }
    if (!empty($available_ids) && !in_array((string) $strategy_id, $available_ids, true)) {
        $strategy_id = $available_ids[0];
    }

    // 显示下拉选择框
    ?>
    <select name='lsky_pro_options[strategy_id]' id='lsky_pro_strategy_id'>
        <?php foreach ($strategies as $strategy): ?>
            <?php
            $id = isset($strategy['id']) ? $strategy['id'] : (isset($strategy['storage_id']) ? $strategy['storage_id'] : '');
            $name = isset($strategy['name']) ? $strategy['name'] : (isset($strategy['title']) ? $strategy['title'] : '');
            ?>
            <option value='<?php echo esc_attr($id); ?>' 
                    <?php selected($strategy_id, $id); ?>>
                <?php echo esc_html($name !== '' ? $name : ('ID: ' . $id)); ?>
                (ID: <?php echo esc_html($id); ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

function lsky_pro_settings_section_callback() {
    echo '请填写LskyPro图床的API设置';
}

function lsky_pro_process_remote_images_callback() {
    $options = get_option('lsky_pro_options');
    ?>
    <label>
        <input type='checkbox' name='lsky_pro_options[process_remote_images]' 
               value="1"
               <?php checked(isset($options['process_remote_images']) && $options['process_remote_images'] == 1); ?>>
        自动处理文章中的远程图片
    </label>
    <p class="description">保存文章时，自动将远程图片上传到图床</p>
    <?php
}
