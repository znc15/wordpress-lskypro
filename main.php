<?php
/*
Plugin Name: Lsky Pro 图床自动上传插件
Plugin URI:  https://github.com/znc15/wordpress-lskypro
Description: 基于Lsky Pro API开发的将媒体库文件自动上传到图床的插件
Version:     0.0.6
Author:      LittleSheep
Author URI:  https://www.littlesheep.cc/
License:     Apache-2

Apache License
Version 2.0, January 2004
http://www.apache.org/licenses/

TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION

1. Definitions.

"License" shall mean the terms and conditions for use, reproduction,
and distribution as defined by Sections 1 through 9 of this document.

"Licensor" shall mean the copyright owner or entity authorized by
the copyright owner that is granting the License.

"Legal Entity" shall mean the union of the acting entity and all
other entities that control, are controlled by, or are under common
control with that entity. For the purposes of this definition,
"control" means (i) the power, direct or indirect, to cause the
direction or management of such entity, whether by contract or
otherwise, or (ii) ownership of fifty percent (50%) or more of the
outstanding shares, or (iii) beneficial ownership of such entity.

"You" (or "Your") shall mean an individual or Legal Entity
exercising permissions granted by this License.

"Source" form shall mean the preferred form for making modifications,
including but not limited to software source code, documentation
source, and configuration files.

"Object" form shall mean any form resulting from mechanical
transformation or translation of a Source form, including but
not limited to compiled object code, generated documentation,
and conversions to other media types.

"Work" shall mean the work of authorship, whether in Source or
Object form, made available under the License, as indicated by a
copyright notice that is included in or attached to the work
(an example is provided in the Appendix below).

"Derivative Works" shall mean any work, whether in Source or Object
form, that is based on (or derived from) the Work and for which the
editorial revisions, annotations, elaborations, or other modifications
represent, as a whole, an original work of authorship. For the purposes
of this License, Derivative Works shall not include works that remain
separable from, or merely link (or bind by name) to the interfaces of,
the Work and Derivative Works thereof.

"Contribution" shall mean any work of authorship, including
the original version of the Work and any modifications or additions
to that Work or Derivative Works thereof, that is intentionally
submitted to Licensor for inclusion in the Work by the copyright owner
or by an individual or Legal Entity authorized to submit on behalf of
the copyright owner. For the purposes of this definition, "submitted"
means any form of electronic, verbal, or written communication sent
to the Licensor or its representatives, including but not limited to
communication on electronic mailing lists, source code control systems,
and issue tracking systems that are managed by, or on behalf of, the
Licensor for the purpose of discussing and improving the Work, but
excluding communication that is conspicuously marked or otherwise
designated in writing by the copyright owner as "Not a Contribution."

"Contributor" shall mean Licensor and any individual or Legal Entity
on behalf of whom a Contribution has been received by Licensor and
subsequently incorporated within the Work.

2. Grant of Copyright License. Subject to the terms and conditions of
this License, each Contributor hereby grants to You a perpetual,
worldwide, non-exclusive, no-charge, royalty-free, irrevocable
copyright license to reproduce, prepare Derivative Works of,
publicly display, publicly perform, sublicense, and distribute the
Work and such Derivative Works in Source or Object form.

3. Grant of Patent License. Subject to the terms and conditions of
this License, each Contributor hereby grants to You a perpetual,
worldwide, non-exclusive, no-charge, royalty-free, irrevocable
(except as stated in this section) patent license to make, have made,
use, offer to sell, sell, import, and otherwise transfer the Work,
where such license applies only to those patent claims licensable
by such Contributor that are necessarily infringed by their
Contribution(s) alone or by combination of their Contribution(s)
with the Work to which such Contribution(s) was submitted. If You
institute patent litigation against any entity (including a
cross-claim or counterclaim in a lawsuit) alleging that the Work
or a Contribution incorporated within the Work constitutes direct
or contributory patent infringement, then any patent licenses
granted to You under this License for that Work shall terminate
as of the date such litigation is filed.

4. Redistribution. You may reproduce and distribute copies of the
Work or Derivative Works thereof in any medium, with or without
modifications, and in Source or Object form, provided that You
meet the following conditions:

(a) You must give any other recipients of the Work or
Derivative Works a copy of this License; and

(b) You must cause any modified files to carry prominent notices
stating that You changed the files; and

(c) You must retain, in the Source form of any Derivative Works
that You distribute, all copyright, patent, trademark, and
attribution notices from the Source form of the Work,
excluding those notices that do not pertain to any part of
the Derivative Works; and

(d) If the Work includes a "NOTICE" text file as part of its
distribution, then any Derivative Works that You distribute must
include a readable copy of the attribution notices contained
within such NOTICE file, excluding those notices that do not
pertain to any part of the Derivative Works, in at least one
of the following places: within a NOTICE text file distributed
as part of the Derivative Works; within the Source form or
documentation, if provided along with the Derivative Works; or,
within a display generated by the Derivative Works, if and
wherever such third-party notices normally appear. The contents
of the NOTICE file are for informational purposes only and
do not modify the License. You may add Your own attribution
notices within Derivative Works that You distribute, alongside
or as an addendum to the NOTICE text from the Work, provided
that such additional attribution notices cannot be construed
as modifying the License.

You may add Your own copyright statement to Your modifications and
may provide additional or different license terms and conditions
for use, reproduction, or distribution of Your modifications, or
for any such Derivative Works as a whole, provided Your use,
reproduction, and distribution of the Work otherwise complies with
the conditions stated in this License.

5. Submission of Contributions. Unless You explicitly state otherwise,
any Contribution intentionally submitted for inclusion in the Work
by You to the Licensor shall be under the terms and conditions of
this License, without any additional terms or conditions.
Notwithstanding the above, nothing herein shall supersede or modify
the terms of any separate license agreement you may have executed
with Licensor regarding such Contributions.

6. Trademarks. This License does not grant permission to use the trade
names, trademarks, service marks, or product names of the Licensor,
except as required for reasonable and customary use in describing the
origin of the Work and reproducing the content of the NOTICE file.

7. Disclaimer of Warranty. Unless required by applicable law or
agreed to in writing, Licensor provides the Work (and each
Contributor provides its Contributions) on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
implied, including, without limitation, any warranties or conditions
of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A
PARTICULAR PURPOSE. You are solely responsible for determining the
appropriateness of using or redistributing the Work and assume any
risks associated with Your exercise of permissions under this License.

8. Limitation of Liability. In no event and under no legal theory,
whether in tort (including negligence), contract, or otherwise,
unless required by applicable law (such as deliberate and grossly
negligent acts) or agreed to in writing, shall any Contributor be
liable to You for damages, including any direct, indirect, special,
incidental, or consequential damages of any character arising as a
result of this License or out of the use or inability to use the
Work (including but not limited to damages for loss of goodwill,
work stoppage, computer failure or malfunction, or any and all
other commercial damages or losses), even if such Contributor
has been advised of the possibility of such damages.

9. Accepting Warranty or Additional Liability. While redistributing
the Work or Derivative Works thereof, You may choose to offer,
and charge a fee for, acceptance of support, warranty, indemnity,
or other liability obligations and/or rights consistent with this
License. However, in accepting such obligations, You may act only
on Your own behalf and on Your sole responsibility, not on behalf
of any other Contributor, and only if You agree to indemnify,
defend, and hold each Contributor harmless for any liability
incurred by, or claims asserted against, such Contributor by reason
of your accepting any such warranty or additional liability.

END OF TERMS AND CONDITIONS

APPENDIX: How to apply the Apache License to your work.

To apply the Apache License to your work, attach the following
boilerplate notice, with the fields enclosed by brackets "[]"
replaced with your own identifying information. (Don't include
the brackets!)  The text should be enclosed in the appropriate
comment syntax for the file format. We also recommend that a
file or class name and description of purpose be included on the
same "printed page" as the copyright notice for easier
identification within third-party archives.

Copyright [yyyy] [name of copyright owner]

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 */
function image_upload_plugin_menu()
{
    add_menu_page('图床设置', '图床设置', 'manage_options', 'image_upload_plugin_settings', 'image_upload_plugin_settings_page', 'dashicons-format-image', );
}

add_action('admin_menu', 'image_upload_plugin_menu');

function image_upload_plugin_settings_page()
{
    ?>
    <div class="wrap">
        <h2>图片上传插件设置</h2>
        <form method="post" action="options.php">
            <?php settings_fields('image_upload_plugin_settings_group');?>
            <?php do_settings_sections('image_upload_plugin_settings');?>
            <?php submit_button();?>
        </form>
    </div>
    <?php

    $image_host_url = get_option('image_host_url');
    $authorization_token = get_option('authorization_token');

    if (empty($image_host_url) || empty($authorization_token)) {
        echo '<div class="wrap">';
        echo '<h2>图片上传插件设置</h2>';
        echo '<p>请填写图床URL和用户Token以开启插件。</p>';
        echo '</div>';
    } else {
        // 构建API请求URL
        $profile_api_url = $image_host_url . 'api/v1/profile';
        $headers = array(
            'Authorization: ' . $authorization_token,
        );

        $ch = curl_init($profile_api_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $json_response = json_decode($response, true);

        function convert_kb_to_mb($kb_value) {
            return round($kb_value / 1024, 2);
        }

        function curl_get_contents($url, $headers) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            return $response;
        }

        if ($json_response && isset($json_response['status']) && $json_response['status'] === true) {
            $user_info = $json_response['data'];
            echo '<div class="wrap">';
            echo '<h2>用户信息</h2>';
            echo '<ul>';
            echo '<li>用户名：' . $user_info['name'] . '</li>';
            echo '<li>邮箱：' . $user_info['email'] . '</li>';
            echo '<li>容量：' . convert_kb_to_mb($user_info['capacity']) . 'MB</li>';
            echo '<li>图片数量：' . $user_info['image_num'] . '</li>';
            echo '<li>相册数量：' . $user_info['album_num'] . '</li>';
            echo '</ul>';
            echo '</div>';
            $strategies_api_url = $image_host_url . 'api/v1/strategies';
            $strategies_response = curl_get_contents($strategies_api_url, $headers);

            $strategies_json = json_decode($strategies_response, true);

            if ($strategies_json && isset($strategies_json['status']) && $strategies_json['status'] === true) {
                $strategies_data = $strategies_json['data']['strategies'];
                echo '<div class="wrap">';
                echo '<h2>储存策略信息</h2>';
                echo '<ul>';
                foreach ($strategies_data as $strategy) {
                    echo '<li>储存策略名字：' . $strategy['name'] . '</li>';
                    echo '<li>策略ID：' . $strategy['id'] . '</li>';
                }
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<h2>储存策略信息</h2>';
                echo '<p>无法获取策略信息，请检查接口是否可用。</p>';
            }
        } else {
            echo '<div class="wrap">';
            echo '<h1>图片上传插件设置</h1>';
            echo '<p>保存更改成功！</p>';
            echo '<p>无法获取用户信息，请检查图床URL和用户Token是否正确。</p>';
            echo '</div>';
        }
        
    }

}

add_action('admin_init', 'image_upload_plugin_register_settings');
function image_upload_plugin_register_settings()
{
    register_setting('image_upload_plugin_settings_group', 'image_host_url');
    register_setting('image_upload_plugin_settings_group', 'authorization_token');
    register_setting('image_upload_plugin_settings_group', 'strategy_id', array('type' => 'integer', 'default' => 1));

    add_settings_section(
        'image_upload_plugin_settings_section',
        '图床设置',
        'image_upload_plugin_settings_section_callback',
        'image_upload_plugin_settings'
    );

    add_settings_field(
        'strategy_id',
        '策略ID',
        'strategy_id_callback',
        'image_upload_plugin_settings',
        'image_upload_plugin_settings_section'
    );

    add_settings_field(
        'image_host_url',
        '图床URL',
        'image_host_url_callback',
        'image_upload_plugin_settings',
        'image_upload_plugin_settings_section'
    );

    add_settings_field(
        'authorization_token',
        '用户Token',
        'authorization_token_callback',
        'image_upload_plugin_settings',
        'image_upload_plugin_settings_section'
    );
}

function image_upload_plugin_settings_section_callback()
{
    echo '<p>请在下面输入图床URL和用户Token。</p>';
}

function image_host_url_callback()
{
    $value = get_option('image_host_url');
    echo "<input type='text' name='image_host_url' value='$value' />";
    echo "<p>请填写图床的Url，例如：https://img.*****.cc/（Url最后面要带/）</p>";
}

function authorization_token_callback()
{
    $value = get_option('authorization_token');
    echo "<input type='text' name='authorization_token' value='$value' />";
    echo "<p>请填写用户Token，例如：Bearer 4|*****（4|*****为获取的token）</p>";
}

function strategy_id_callback()
{
    $value = get_option('strategy_id', 1);
    echo "<input type='text' name='strategy_id' value='$value' />";
    echo "<p>请填写策略ID，默认为1。</p>";
}

add_filter('wp_get_attachment_url', 'change_image_url', 10, 2);

$plugin_activation_date = get_option('image_upload_plugin_activation_date');

if (!$plugin_activation_date) {
    update_option('image_upload_plugin_activation_date', date('Ymd'));
}

// 处理图片URL
function change_image_url($url, $attachment_id) {

    $plugin_activation_date = get_option('image_upload_plugin_activation_date');

    $upload_date = get_the_time('Ymd', $attachment_id);

    $image_host_url = get_post_meta($attachment_id, 'image_host_url', true);
    if (!empty($image_host_url)) {
        return $image_host_url;
    }

    if ($upload_date >= $plugin_activation_date) {

        $cached_url = get_post_meta($attachment_id, '_image_host_url', true);
        if (!empty($cached_url)) {

            return $cached_url;
        }

        $upload_dir = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];

        $cache_dir = path_join($upload_basedir, 'image_upload_cache');
        wp_mkdir_p($cache_dir);

        $attachment_file = get_attached_file($attachment_id);

        $cache_file = path_join($cache_dir, basename($attachment_file));

        if (!file_exists($cache_file)) {
            copy($attachment_file, $cache_file);
        }
        $image_host_url = get_option('image_host_url') . 'api/v1/upload';
        $authorization_token = get_option('authorization_token');
        $strategy_id = get_option('strategy_id', 1);
        $headers = array(
            'Accept: application/json',
            "Authorization: Bearer $authorization_token",
        );
        $body = array(
            'file' => new CURLFile($cache_file, get_post_mime_type($cache_file), basename($cache_file)),
            'strategy_id' => $strategy_id,
        );

        $ch = curl_init($image_host_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $json_response = json_decode($response, true);

        if ($json_response && isset($json_response['status']) && $json_response['status'] === true) {
            $new_url = $json_response['data']['links']['url'];
            update_post_meta($attachment_id, 'image_host_url', $new_url);
            $url = $new_url;
        }
    }
    return $url;
}

add_filter('wp_generate_attachment_metadata', 'upload_thumbnail_to_image_host', 10, 2);

// 处理创建缩略图
function upload_thumbnail_to_image_host($metadata, $attachment_id) {
    $plugin_activation_date = get_option('image_upload_plugin_activation_date');
    $upload_date = get_the_time('Ymd', $attachment_id);
    $image_host_url = get_post_meta($attachment_id, 'image_host_url', true);
    if (!empty($image_host_url)) {
        return $metadata;
    }
    if ($upload_date >= $plugin_activation_date) {
        $upload_dir = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];
        $cache_dir = path_join($upload_basedir, 'image_upload_cache');
        wp_mkdir_p($cache_dir);
        foreach ($metadata['sizes'] as $size => $size_info) {
            $thumbnail_file = path_join($upload_dir['path'], $size_info['file']);
            $cache_file = path_join($cache_dir, basename($thumbnail_file));
            if (!file_exists($cache_file)) {
                copy($thumbnail_file, $cache_file);
            }
            $response = upload_image_to_host($cache_file, $attachment_id);
            if ($response['status']) {
                $new_url = $response['data']['name'];
                $metadata['sizes'][$size]['file'] = $new_url;
            }
        }
    }
    return $metadata;
}

function upload_image_to_host($file_path, $attachment_id) {
    $image_host_url = get_option('image_host_url') . 'api/v1/upload';
    $authorization_token = get_option('authorization_token');
    $strategy_id = get_option('strategy_id', 1);

    $headers = array(
        'Accept: application/json',
        "Authorization: Bearer $authorization_token",
    );

    $body = array(
        'file' => new CURLFile($file_path, get_post_mime_type($file_path), basename($file_path)),
        'strategy_id' => $strategy_id,
    );

    $ch = curl_init($image_host_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
?>