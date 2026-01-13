<?php

declare(strict_types=1);

namespace LskyPro\Support;

final class Options
{
    public const KEY = 'lsky_pro_options';

    /**
     * @return array<string, string|int>
     */
    public static function defaults(): array
    {
        return [
            'lsky_pro_api_url' => '',
            'lsky_pro_token' => '',
            'storage_id' => '1',
            'album_id' => '0',
            'process_remote_images' => 0,

            'exclude_site_icon' => 1,
            'exclude_ajax_actions' => "avatar\n",
            'exclude_referer_contains' => '',
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
}
