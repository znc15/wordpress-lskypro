<?php

declare(strict_types=1);

namespace {
    $GLOBALS['__meta'] = [];
    $GLOBALS['__options'] = [
        'lsky_pro_options' => [
            'lsky_pro_api_url' => 'https://lsky.test/api',
        ],
    ];

    function get_post_meta($postId, $key = '', $single = false)
    {
        $postId = (int) $postId;
        if ($key === '') {
            return $GLOBALS['__meta'][$postId] ?? [];
        }
        $value = $GLOBALS['__meta'][$postId][$key] ?? '';
        return $single ? $value : [$value];
    }

    function update_post_meta($postId, $key, $value)
    {
        $postId = (int) $postId;
        if (!isset($GLOBALS['__meta'][$postId])) {
            $GLOBALS['__meta'][$postId] = [];
        }
        $GLOBALS['__meta'][$postId][$key] = $value;
        return true;
    }

    function get_option($key)
    {
        return $GLOBALS['__options'][$key] ?? null;
    }

    function maybe_unserialize($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        $trim = trim($value);
        if ($trim === '') {
            return $value;
        }
        $un = @unserialize($value);
        return $un !== false || $value === 'b:0;' ? $un : $value;
    }

    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }

    function get_site_url()
    {
        return 'https://example.com';
    }

    function wp_upload_dir()
    {
        return [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => __DIR__ . '/uploads',
        ];
    }

    function wp_mkdir_p($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return true;
    }

    function wp_remote_get($url, array $args = [])
    {
        return [
            'response' => ['code' => 200],
            'body' => 'stub-image',
        ];
    }

    function is_wp_error($thing)
    {
        return false;
    }

    function wp_remote_retrieve_response_code($response)
    {
        return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }

    function wp_remote_retrieve_body($response)
    {
        return isset($response['body']) ? (string) $response['body'] : '';
    }

    function attachment_url_to_postid($url)
    {
        return $url === 'https://example.com/wp-content/uploads/cover.jpg' ? 10 : 0;
    }

    function get_attached_file($attachmentId)
    {
        return __DIR__ . '/uploads/cover.jpg';
    }

    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }

    if (!function_exists('error_log')) {
        function error_log($message)
        {
            return true;
        }
    }
}

namespace LskyPro {
    class Uploader
    {
        private ?string $error = null;

        public function setUploadLogContextFromPost($postId, $context)
        {
            return true;
        }

        public function clearUploadLogContext()
        {
            return true;
        }

        public function upload($filePath)
        {
            if (strpos($filePath, 'cover.jpg') !== false) {
                return 'https://lsky.test/local.jpg';
            }
            return 'https://lsky.test/remote.jpg';
        }

        public function getLastUploadedPhotoId()
        {
            return 42;
        }

        public function getError()
        {
            return $this->error;
        }
    }
}

namespace LskyPro\Support {
    class UploadExclusions
    {
        public static function shouldUpload(array $args = [], array $requestContext = []): bool
        {
            return true;
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/Remote.php';

    function assertSame($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function test_process_zib_other_data_replaces_urls(): void
    {
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }
        file_put_contents($uploadsDir . '/cover.jpg', 'local');

        $postId = 101;
        $GLOBALS['__meta'][$postId] = [
            'zib_other_data' => [
                'thumbnail_url' => 'https://remote.test/remote.jpg?x=1',
                'cover_image' => 'https://example.com/wp-content/uploads/cover.jpg',
            ],
            '_lsky_pro_processed_urls' => [],
            '_lsky_pro_processed_photo_ids' => [],
        ];

        $remote = new \LskyPro\Remote();
        $remote->process_zib_other_data($postId);

        $updated = $GLOBALS['__meta'][$postId]['zib_other_data'];
        assertSame('https://lsky.test/remote.jpg', $updated['thumbnail_url'], 'thumbnail_url replaced');
        assertSame('https://lsky.test/local.jpg', $updated['cover_image'], 'cover_image replaced');

        $cache = $GLOBALS['__meta'][$postId]['_lsky_pro_processed_urls'];
        assertSame('https://lsky.test/remote.jpg', $cache['https://remote.test/remote.jpg'], 'cache remote');
        assertSame('https://lsky.test/local.jpg', $cache['https://example.com/wp-content/uploads/cover.jpg'], 'cache local');
    }

    test_process_zib_other_data_replaces_urls();

    fwrite(STDOUT, "OK\n");
}
