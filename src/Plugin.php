<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Module\Admin;
use LskyPro\Module\Ajax;
use LskyPro\Module\Media;
use LskyPro\Module\Settings;
use LskyPro\Module\UserPolicy;
use LskyPro\Support\Options;

final class Plugin
{
    public static function init(): void
    {
        if (!\defined('ABSPATH')) {
            return;
        }

        self::migrateOptionsToV2();

        // Modules (hooks, UI, ajax)
        (new Settings())->register();
        (new Admin())->register();
        (new Media())->register();
        (new Ajax())->register();
        (new UserPolicy())->register();

        // Core handlers
        new UploadHandler();
        new PostHandler();
        new Batch();
    }

    private static function migrateOptionsToV2(): void
    {
        $options = \get_option(Options::KEY);
        if (!\is_array($options)) {
            return;
        }

        $changed = false;

        if (isset($options['strategy_id'])) {
            $options['storage_id'] = (string) \absint($options['strategy_id']);
            $changed = true;
        }

        if (isset($options['storage_id'])) {
            $storageId = \absint($options['storage_id']);
            if ($storageId <= 0) {
                $options['storage_id'] = '1';
                $changed = true;
            } else {
                $options['storage_id'] = (string) $storageId;
            }
        }

        foreach (['strategy_id', 'permission', 'expired_at'] as $legacyKey) {
            if (\array_key_exists($legacyKey, $options)) {
                unset($options[$legacyKey]);
                $changed = true;
            }
        }

        if ($changed) {
            \update_option(Options::KEY, $options);
        }
    }
}
