<?php

declare(strict_types=1);

namespace {
    $GLOBALS['__options'] = [
        'lsky_pro_options' => [],
    ];

    function get_option($key)
    {
        return $GLOBALS['__options'][$key] ?? null;
    }

    function update_option($key, $value)
    {
        $GLOBALS['__options'][$key] = $value;
        return true;
    }

    function add_settings_error($setting, $code, $message, $type = 'error')
    {
        return true;
    }

    function sanitize_text_field($str)
    {
        return is_string($str) ? trim($str) : '';
    }

    function esc_url_raw($url)
    {
        return is_string($url) ? $url : '';
    }

    function esc_attr($text)
    {
        return is_string($text) ? $text : '';
    }

    function esc_html($text)
    {
        return is_string($text) ? $text : '';
    }

    function esc_textarea($text)
    {
        return is_string($text) ? $text : '';
    }

    function wp_roles()
    {
        return (object) ['role_names' => ['administrator' => 'Administrator']];
    }

    function sanitize_key($key)
    {
        $key = strtolower((string) $key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key);
        return $key ?? '';
    }

    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}

namespace {
    require_once __DIR__ . '/../src/Support/Options.php';
    require_once __DIR__ . '/../src/Module/Settings.php';

    function assertSame($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function test_keyword_rules_validation(): void
    {
        $settings = new \LskyPro\Module\Settings();
        $input = [
            'keyword_routing_rules' => [
                [
                    'keywords' => "foo\nbar",
                    'storage_id' => '2',
                    'album_id' => '0',
                ],
                [
                    'keywords' => "  ",
                    'storage_id' => '0',
                    'album_id' => '',
                ],
            ],
        ];

        $out = $settings->validate_settings($input);
        $rules = $out['keyword_routing_rules'] ?? [];

        assertSame(1, count($rules), 'keeps only valid rule');
        assertSame(['foo', 'bar'], $rules[0]['keywords'], 'splits keywords');
        assertSame('2', $rules[0]['storage_id'], 'storage id kept');
        assertSame('0', $rules[0]['album_id'], 'album id kept');
    }

    function test_keyword_rules_render(): void
    {
        $GLOBALS['__options']['lsky_pro_options'] = [
            'keyword_routing_rules' => [
                [
                    'keywords' => ['foo', 'bar'],
                    'storage_id' => '2',
                    'album_id' => '0',
                ],
            ],
        ];

        $settings = new \LskyPro\Module\Settings();
        \ob_start();
        $settings->keyword_routing_rules_callback();
        $html = (string) \ob_get_clean();
        assertSame(true, \strpos($html, 'lsky-keyword-rules') !== false, 'renders keyword rules');
    }

    test_keyword_rules_validation();
    test_keyword_rules_render();
    fwrite(STDOUT, "OK\n");
}
