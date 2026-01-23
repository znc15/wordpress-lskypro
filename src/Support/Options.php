<?php

declare(strict_types=1);

namespace LskyPro\Support;

final class Options
{
    public const KEY = 'lsky_pro_options';

    public const USER_META_STORAGE_ID = 'lsky_pro_storage_id';
    public const USER_META_ALBUM_ID = 'lsky_pro_album_id';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'lsky_pro_api_url' => '',
            'lsky_pro_token' => '',
            'storage_id' => '1',
            'album_id' => '0',
            'process_remote_images' => 0,

            // Per-role defaults. 0 means "inherit global".
            'default_storage_id_admin' => '0',
            'default_storage_id_user' => '0',
            'default_album_id_admin' => '0',
            'default_album_id_user' => '0',

            // Role groups (WordPress roles). When set, user strategy is chosen by role membership.
            // If a user matches multiple groups, admin group wins.
            'admin_role_group' => ['administrator'],
            'user_role_group' => ['subscriber'],

            // Post lifecycle
            'delete_remote_images_on_post_delete' => 1,

            // Also delete WordPress media attachments referenced by the post when permanently deleting.
            // Note: if the same attachment is reused by multiple posts, enabling this will remove it everywhere.
            'delete_wp_attachments_on_post_delete' => 1,

            // Upload lifecycle
            'delete_local_files_after_upload' => 0,

            'exclude_site_icon' => 1,
            'exclude_ajax_actions' => "avatar\n",
            'exclude_referer_contains' => '',
            'keyword_routing_rules' => [],
        ];
    }

    /**
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public static function normalized(?array $options = null): array
    {
        if (!\is_array($options)) {
            $options = \get_option(self::KEY);
        }
        if (!\is_array($options)) {
            $options = [];
        }

        return \array_merge(self::defaults(), $options);
    }

    public static function resolveStorageIdForUser(int $userId, ?array $options = null): int
    {
        $options = self::normalized($options);

        // User override.
        if ($userId > 0 && \function_exists('get_user_meta')) {
            $override = (int) \absint((string) \get_user_meta($userId, self::USER_META_STORAGE_ID, true));
            if ($override > 0) {
                return $override;
            }
        }

        $global = (int) \absint((string) ($options['storage_id'] ?? '1'));
        if ($global <= 0) {
            $global = 1;
        }

        if ($userId <= 0) {
            return $global;
        }

        $adminGroup = $options['admin_role_group'] ?? [];
        $userGroup = $options['user_role_group'] ?? [];
        if (!\is_array($adminGroup)) {
            $adminGroup = [];
        }
        if (!\is_array($userGroup)) {
            $userGroup = [];
        }

        $roles = [];
        if (\function_exists('get_userdata')) {
            $u = \get_userdata($userId);
            if ($u instanceof \WP_User && \is_array($u->roles)) {
                $roles = $u->roles;
            }
        }

        $isAdmin = false;
        if (!empty($roles) && !empty($adminGroup)) {
            $isAdmin = !empty(\array_intersect($roles, $adminGroup));
        } elseif (\function_exists('user_can')) {
            // Backward-compatible fallback.
            $isAdmin = \user_can($userId, 'manage_options');
        }

        $key = $isAdmin ? 'default_storage_id_admin' : 'default_storage_id_user';
        $roleDefault = (int) \absint((string) ($options[$key] ?? '0'));
        if ($roleDefault > 0) {
            return $roleDefault;
        }

        return $global;
    }

    public static function resolveAlbumIdForUser(int $userId, ?array $options = null): int
    {
        $options = self::normalized($options);

        if ($userId > 0 && \function_exists('get_user_meta')) {
            $override = (int) \absint((string) \get_user_meta($userId, self::USER_META_ALBUM_ID, true));
            if ($override > 0) {
                return $override;
            }
        }

        $global = (int) \absint((string) ($options['album_id'] ?? '0'));
        if ($userId <= 0) {
            return $global;
        }

        $adminGroup = $options['admin_role_group'] ?? [];
        $userGroup = $options['user_role_group'] ?? [];
        if (!\is_array($adminGroup)) {
            $adminGroup = [];
        }
        if (!\is_array($userGroup)) {
            $userGroup = [];
        }

        $roles = [];
        if (\function_exists('get_userdata')) {
            $u = \get_userdata($userId);
            if ($u instanceof \WP_User && \is_array($u->roles)) {
                $roles = $u->roles;
            }
        }

        $isAdmin = false;
        if (!empty($roles) && !empty($adminGroup)) {
            $isAdmin = !empty(\array_intersect($roles, $adminGroup));
        } elseif (\function_exists('user_can')) {
            $isAdmin = \user_can($userId, 'manage_options');
        }

        $key = $isAdmin ? 'default_album_id_admin' : 'default_album_id_user';
        $roleDefault = (int) \absint((string) ($options[$key] ?? '0'));
        if ($roleDefault > 0) {
            return $roleDefault;
        }

        return $global;
    }
}
