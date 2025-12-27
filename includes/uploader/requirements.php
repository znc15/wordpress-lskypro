<?php

if (!defined('ABSPATH')) {
    exit;
}

trait LskyProUploaderRequirementsTrait {
    private function checkRequirements() {
        $this->requirements['php_version'] = array(
            'name' => 'PHP版本',
            'required' => '7.2.0',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '7.2.0', '>='),
            'error' => 'PHP版本必须 >= 7.2.0'
        );

        $required_extensions = array(
            'curl' => '必须安装CURL扩展',
            'json' => '必须安装JSON扩展',
            'fileinfo' => '必须安装FileInfo扩展'
        );

        foreach ($required_extensions as $ext => $error) {
            $this->requirements[$ext] = array(
                'name' => strtoupper($ext) . '扩展',
                'required' => true,
                'current' => extension_loaded($ext),
                'status' => extension_loaded($ext),
                'error' => $error
            );
        }

        $php_configs = array(
            'file_uploads' => array(
                'name' => '文件上传',
                'required' => true,
                'error' => '必须启用文件上传功能'
            ),
            'upload_max_filesize' => array(
                'name' => '最大上传大小',
                'required' => '10M',
                'error' => '建议设置不小于10M'
            ),
            'post_max_size' => array(
                'name' => '最大POST大小',
                'required' => '10M',
                'error' => '建议设置不小于10M'
            ),
            'max_execution_time' => array(
                'name' => '最大执行时间',
                'required' => 30,
                'error' => '建议设置不小于30秒'
            ),
            'memory_limit' => array(
                'name' => '内存限制',
                'required' => '128M',
                'error' => '建议设置不小于128M'
            )
        );

        foreach ($php_configs as $key => $config) {
            $current = ini_get($key);
            $this->requirements['php_' . $key] = array(
                'name' => $config['name'],
                'required' => $config['required'],
                'current' => $current,
                'status' => $this->checkPhpValue($current, $config['required']),
                'error' => $config['error']
            );
        }
    }

    private function checkPhpValue($current, $required) {
        if (is_bool($required)) {
            return filter_var($current, FILTER_VALIDATE_BOOLEAN) === $required;
        }

        if (is_numeric($required)) {
            return intval($current) >= intval($required);
        }

        $current_bytes = $this->convertToBytes($current);
        $required_bytes = $this->convertToBytes($required);

        return $current_bytes >= $required_bytes;
    }

    private function convertToBytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;

        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    public function getRequirements() {
        return $this->requirements;
    }

    public function checkEnvironment() {
        $errors = array();
        foreach ($this->requirements as $key => $requirement) {
            if (!$requirement['status']) {
                $errors[] = $requirement['error'];
            }
        }

        if (!empty($errors)) {
            $this->error = "环境检查失败：\n" . implode("\n", $errors);
            return false;
        }

        return true;
    }
}
