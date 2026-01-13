<?php

declare(strict_types=1);

namespace LskyPro\Uploader;

trait RequirementsTrait
{
    private function checkRequirements()
    {
        $this->requirements['php_version'] = [
            'name' => 'PHP版本',
            'required' => '7.2.0',
            'current' => \PHP_VERSION,
            'status' => \version_compare(\PHP_VERSION, '7.2.0', '>='),
            'error' => 'PHP版本必须 >= 7.2.0',
        ];

        $required_extensions = [
            'curl' => '必须安装CURL扩展',
            'json' => '必须安装JSON扩展',
            'fileinfo' => '必须安装FileInfo扩展',
        ];

        foreach ($required_extensions as $ext => $error) {
            $this->requirements[$ext] = [
                'name' => \strtoupper((string) $ext) . '扩展',
                'required' => true,
                'current' => \extension_loaded((string) $ext),
                'status' => \extension_loaded((string) $ext),
                'error' => $error,
            ];
        }

        $php_configs = [
            'file_uploads' => [
                'name' => '文件上传',
                'required' => true,
                'error' => '必须启用文件上传功能',
            ],
            'upload_max_filesize' => [
                'name' => '最大上传大小',
                'required' => '10M',
                'error' => '建议设置不小于10M',
            ],
            'post_max_size' => [
                'name' => '最大POST大小',
                'required' => '10M',
                'error' => '建议设置不小于10M',
            ],
            'max_execution_time' => [
                'name' => '最大执行时间',
                'required' => 30,
                'error' => '建议设置不小于30秒',
            ],
            'memory_limit' => [
                'name' => '内存限制',
                'required' => '128M',
                'error' => '建议设置不小于128M',
            ],
        ];

        foreach ($php_configs as $key => $config) {
            $current = \ini_get((string) $key);
            $this->requirements['php_' . $key] = [
                'name' => $config['name'],
                'required' => $config['required'],
                'current' => $current,
                'status' => $this->checkPhpValue($current, $config['required']),
                'error' => $config['error'],
            ];
        }
    }

    private function checkPhpValue($current, $required)
    {
        if (\is_bool($required)) {
            return \filter_var($current, \FILTER_VALIDATE_BOOLEAN) === $required;
        }

        if (\is_numeric($required)) {
            return (int) $current >= (int) $required;
        }

        $current_bytes = $this->convertToBytes((string) $current);
        $required_bytes = $this->convertToBytes((string) $required);

        return $current_bytes >= $required_bytes;
    }

    private function convertToBytes(string $value): int
    {
        $value = \trim($value);
        if ($value === '') {
            return 0;
        }

        $last = \strtolower($value[\strlen($value) - 1]);
        $num = (int) $value;

        switch ($last) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
                // no break
        }

        return $num;
    }

    public function getRequirements()
    {
        return $this->requirements;
    }

    public function checkEnvironment()
    {
        $errors = [];
        foreach ($this->requirements as $requirement) {
            if (\is_array($requirement) && isset($requirement['status']) && !$requirement['status']) {
                $errors[] = (string) ($requirement['error'] ?? '');
            }
        }

        if (!empty($errors)) {
            $this->error = "环境检查失败：\n" . \implode("\n", $errors);
            return false;
        }

        return true;
    }
}
