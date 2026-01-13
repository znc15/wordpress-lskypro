<?php

declare(strict_types=1);

namespace LskyPro\Module;

use LskyPro\Uploader;
use LskyPro\Support\Options;

final class UserPolicy
{
    public function register(): void
    {
        \add_action('show_user_profile', [$this, 'render'], 20);
        \add_action('edit_user_profile', [$this, 'render'], 20);

        \add_action('personal_options_update', [$this, 'save']);
        \add_action('edit_user_profile_update', [$this, 'save']);
    }

    public function render(\WP_User $user): void
    {
        if (!\current_user_can('edit_user', (int) $user->ID)) {
            return;
        }

        $storageOverride = (int) \absint((string) \get_user_meta((int) $user->ID, Options::USER_META_STORAGE_ID, true));
        $albumOverride = (int) \absint((string) \get_user_meta((int) $user->ID, Options::USER_META_ALBUM_ID, true));

        $uploader = new Uploader();
        $storages = $uploader->get_strategies();
        $albums = $uploader->get_all_albums('', 100);

        ?>
        <h2>LskyPro 上传策略</h2>
        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th><label for="lsky_pro_user_storage_id">存储策略（Storage）</label></th>
                <td>
                    <?php if (\is_array($storages) && !empty($storages)): ?>
                        <select name="lsky_pro_user_storage_id" id="lsky_pro_user_storage_id">
                            <option value="0" <?php \selected($storageOverride, 0); ?>>继承默认（按管理员/普通用户默认策略）</option>
                            <?php foreach ($storages as $s): ?>
                                <?php
                                if (!\is_array($s)) {
                                    continue;
                                }
                                $id = isset($s['id']) ? (int) $s['id'] : 0;
                                if ($id <= 0) {
                                    continue;
                                }
                                $name = isset($s['name']) ? (string) $s['name'] : '';
                                ?>
                                <option value="<?php echo \esc_attr((string) $id); ?>" <?php \selected($storageOverride, $id); ?>>
                                    <?php echo \esc_html($name !== '' ? $name : ('ID: ' . (string) $id)); ?> (ID: <?php echo \esc_html((string) $id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">可用来实现“管理员发文走带水印策略、普通用户走无水印策略”。</p>
                    <?php else: ?>
                        <input type="number" min="0" name="lsky_pro_user_storage_id" id="lsky_pro_user_storage_id" value="<?php echo \esc_attr((string) $storageOverride); ?>" class="regular-text" />
                        <p class="description">0=继承默认；>0=覆盖 Storage ID。当前无法加载存储列表：<?php echo \esc_html((string) $uploader->getError()); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="lsky_pro_user_album_id">相册（Album，可选）</label></th>
                <td>
                    <?php if (\is_array($albums)): ?>
                        <select name="lsky_pro_user_album_id" id="lsky_pro_user_album_id">
                            <option value="0" <?php \selected($albumOverride, 0); ?>>继承默认（按管理员/普通用户默认相册/全局相册）</option>
                            <?php foreach ($albums as $a): ?>
                                <?php
                                if (!\is_array($a)) {
                                    continue;
                                }
                                $id = isset($a['id']) ? (int) $a['id'] : 0;
                                if ($id <= 0) {
                                    continue;
                                }
                                $name = isset($a['name']) ? (string) $a['name'] : '';
                                ?>
                                <option value="<?php echo \esc_attr((string) $id); ?>" <?php \selected($albumOverride, $id); ?>>
                                    <?php echo \esc_html($name !== '' ? $name : ('ID: ' . (string) $id)); ?> (ID: <?php echo \esc_html((string) $id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">0=继承默认；>0=覆盖上传时携带的 album_id。</p>
                    <?php else: ?>
                        <input type="number" min="0" name="lsky_pro_user_album_id" id="lsky_pro_user_album_id" value="<?php echo \esc_attr((string) $albumOverride); ?>" class="regular-text" />
                        <p class="description">0=继承默认；>0=覆盖 album_id。当前无法加载相册列表：<?php echo \esc_html((string) $uploader->getError()); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            </tbody>
        </table>
        <?php

        \wp_nonce_field('lsky_pro_user_policy_save', 'lsky_pro_user_policy_nonce');
    }

    public function save(int $userId): void
    {
        if (!\current_user_can('edit_user', $userId)) {
            return;
        }

        if (!isset($_POST['lsky_pro_user_policy_nonce']) || !\is_string($_POST['lsky_pro_user_policy_nonce'])) {
            return;
        }

        $nonce = \sanitize_text_field(\wp_unslash($_POST['lsky_pro_user_policy_nonce']));
        if (!\wp_verify_nonce($nonce, 'lsky_pro_user_policy_save')) {
            return;
        }

        $storageOverride = isset($_POST['lsky_pro_user_storage_id']) ? (int) \absint((string) \wp_unslash($_POST['lsky_pro_user_storage_id'])) : 0;
        $albumOverride = isset($_POST['lsky_pro_user_album_id']) ? (int) \absint((string) \wp_unslash($_POST['lsky_pro_user_album_id'])) : 0;

        if ($storageOverride > 0) {
            \update_user_meta($userId, Options::USER_META_STORAGE_ID, (string) $storageOverride);
        } else {
            \delete_user_meta($userId, Options::USER_META_STORAGE_ID);
        }

        if ($albumOverride > 0) {
            \update_user_meta($userId, Options::USER_META_ALBUM_ID, (string) $albumOverride);
        } else {
            \delete_user_meta($userId, Options::USER_META_ALBUM_ID);
        }
    }
}
