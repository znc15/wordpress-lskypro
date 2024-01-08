<?php
/*
Plugin Name: Lsky Pro 图床自动上传插件
Plugin URI:  https://github.com/znc15/wordpress-lskypro
Description: 基于Lsky Pro API开发的将媒体库文件自动上传到图床的插件
Version:     0.0.1
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
        <h2>图床设置</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('image_upload_plugin_settings');
            do_settings_sections('image_upload_plugin_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">图床Url</th>
                    <td>
                        <input type="text" name="image_upload_host_url"
                            value="<?php echo esc_attr(get_option('image_upload_host_url')); ?>" />
                        <p>请填写图床的Url，例如：https://img.tcbmc.cc（Url最后面不要带/）</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">用户Token</th>
                    <td>
                        <input type="text" name="image_upload_auth_token"
                            value="<?php echo esc_attr(get_option('image_upload_auth_token')); ?>" />
                        <p>请填写用户Token，例如：4|o4wlpDcYYQ7KOt4BCnZcCfKWFZgzdtgrQ5LJs6CS</p>
                    </td>
                </tr>
            </table>
            <?php
            submit_button('保存更改');
            ?>
        </form>

        <?php
        $image_host_url = get_option('image_upload_host_url');
        $auth_token = get_option('image_upload_auth_token');

        if (empty($image_host_url) || empty($auth_token)) {
            ?>
            <h2>图床用户信息</h2>
            <p>请填写图床URL和用户Token以查看用户信息。</p>
            <?php
        } else {
            ?>
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>图床用户信息</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    .table {
                        margin-bottom: 0rem !important;
                    }
                </style>
            </head>

            <body>
                <?php
                $profile_url = $image_host_url . '/api/v1/profile';
                $headers = array(
                    'Accept: application/json',
                    'Authorization: Bearer ' . $auth_token,
                );
                $ch = curl_init($profile_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $response = curl_exec($ch);

                if ($response === false) {
                    ?>
                    <h2>图床用户信息</h2>
                    <p>发生了 cURL 请求错误。请检查您的网络连接或其他配置。</p>
                    <?php
                } else {
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($http_code == 200) {
                        // 解析JSON响应
                        $json_response = json_decode($response, true);

                        if ($json_response !== null) {
                            ?>
                            <h2>图床用户信息</h2>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <?php
                                    $display_fields = array(
                                        'username' => '用户名',
                                        'email' => '邮箱',
                                        'capacity' => '总容量',
                                        'size' => '已使用容量',
                                        'image_num' => '图片数量',
                                        'album_num' => '相册数量'
                                    );

                                    foreach ($json_response['data'] as $key => $value) {
                                        if (array_key_exists($key, $display_fields)) {
                                            ?>
                                            <tr>
                                                <th scope="row">
                                                    <?php echo esc_html($display_fields[$key]); ?>
                                                </th>
                                                <td>
                                                    <?php echo ($key === 'capacity' || $key === 'size') ? format_size($value) : esc_html($value); ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
                                    ?>
                                </table>
                            </div>
                            <?php
                        } else {
                            ?>
                            <h2>图床用户信息</h2>
                            <p>无法解析返回的JSON。请检查您填写的信息。</p>
                            <p>返回的JSON信息：</p>
                            <pre><?php echo esc_html($response); ?></pre>
                            <?php
                        }
                    } else {
                        ?>
                        <h2>图床用户信息</h2>
                        <p>HTTP状态码不是200。请检查您填写的信息。</p>
                        <p>HTTP状态码：
                            <?php echo esc_html($http_code); ?>
                        </p>
                        <p>返回的JSON信息：</p>
                        <pre><?php echo esc_html($response); ?></pre>
                        <?php
                    }
                }

                ?>
            </body>

            </html>
            <?php

            curl_close($ch);
        }

        ?>
    </div>
    <?php
}

// 辅助函数：将容量从KB转换为M
function format_size($size_in_kb)
{
    $size_in_m = $size_in_kb / 1024;
    return sprintf('%.2fM', $size_in_m);
}
// 注册设置项
function image_upload_plugin_register_settings()
{
    register_setting('image_upload_plugin_settings', 'image_upload_host_url');
    register_setting('image_upload_plugin_settings', 'image_upload_auth_token');
}

add_action('admin_init', 'image_upload_plugin_register_settings');

// 添加钩子，拦截WordPress获取图片URL的过程
add_filter('wp_get_attachment_url', 'change_image_url', 10, 2);

// 插件启用日期
$plugin_activation_date = get_option('image_upload_plugin_activation_date');

// 如果插件启用日期不存在，则设置为当前日期
if (!$plugin_activation_date) {
    update_option('image_upload_plugin_activation_date', date('Ymd'));
}

// 处理图片URL
function change_image_url($url, $attachment_id)
{
    // 获取插件启用日期
    $plugin_activation_date = get_option('image_upload_plugin_activation_date');

    // 获取附件的上传时间
    $upload_date = get_the_time('Ymd', $attachment_id);
    $current_date = date('Ymd');

    // 如果附件是在插件启用之后上传的，才进行处理
    if ($upload_date >= $plugin_activation_date) {
        // 获取WordPress上传目录路径
        $upload_dir = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];

        // 构建缓存目录路径
        $cache_dir = path_join($upload_basedir, 'image_upload_cache');
        wp_mkdir_p($cache_dir);

        // 获取附件的本地文件路径
        $attachment_file = get_attached_file($attachment_id);

        // 构建缓存文件路径
        $cache_file = path_join($cache_dir, basename($attachment_file));

        // 如果缓存文件不存在，将附件复制到缓存目录
        if (!file_exists($cache_file)) {
            copy($attachment_file, $cache_file);
        }

        // 获取图床URL和授权Token
        $image_host_url = get_option('image_upload_host_url');
        $auth_token = get_option('image_upload_auth_token');

        // 图床接口需要的请求头
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $auth_token,
        );

        // 构建POST请求
        $body = array(
            'file' => new CURLFile($cache_file, get_post_mime_type($cache_file), basename($cache_file)),
        );

        $ch = curl_init($image_host_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        // 解析JSON响应
        $json_response = json_decode($response, true);

        if ($json_response && isset($json_response['status']) && $json_response['status'] === true) {
            // 获取图床返回的URL
            $new_url = $json_response['data']['links']['url'];

            // 获取图床返回的文件名
            $new_filename = $json_response['data']['origin_name'];

            // 获取WordPress附件的信息
            $attachment_info = get_post_meta($attachment_id, '_wp_attachment_metadata', true);

            // 更新附件的文件名
            $attachment_info['file'] = $new_filename;

            // 更新附件的信息
            update_post_meta($attachment_id, '_wp_attachment_metadata', $attachment_info);

            $url = $new_url;
        }
    }

    return $url;
}

// 添加钩子，拦截WordPress创建缩略图的过程
add_filter('wp_generate_attachment_metadata', 'upload_thumbnail_to_image_host', 10, 2);

// 处理创建缩略图
function upload_thumbnail_to_image_host($metadata, $attachment_id)
{
    $plugin_activation_date = get_option('image_upload_plugin_activation_date');

    $upload_date = get_the_time('Ymd', $attachment_id);

    if ($upload_date >= $plugin_activation_date) {
        $upload_dir = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];

        $cache_dir = path_join($upload_basedir, 'image_upload_cache');
        wp_mkdir_p($cache_dir);

        $attachment_file = get_attached_file($attachment_id);

        $cache_file = path_join($cache_dir, basename($attachment_file));

        if (!file_exists($cache_file)) {
            copy($attachment_file, $cache_file);
        }

        $image_host_url = get_option('image_upload_host_url');
        $auth_token = get_option('image_upload_auth_token');

        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $auth_token,
        );

        $body = array(
            'file' => new CURLFile($cache_file, get_post_mime_type($cache_file), basename($cache_file)),
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

            $new_filename = $json_response['data']['name'];

            $metadata['file'] = $new_filename;

            foreach ($metadata['sizes'] as $size => $size_info) {
                $metadata['sizes'][$size]['file'] = $new_filename;
            }
        }
    }

    return $metadata;
}
?>