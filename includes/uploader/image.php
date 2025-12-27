<?php

if (!defined('ABSPATH')) {
    exit;
}

trait LskyProUploaderImageTrait {
    private function getMimeType($file_path) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            return $mime_type;
        }

        if (function_exists('mime_content_type')) {
            return mime_content_type($file_path);
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
        );

        return isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
    }

    private function checkImageFile($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            $this->error = '文件不存在或不可读: ' . $file_path;
            return false;
        }

        $filesize = filesize($file_path);
        if ($filesize === false || $filesize === 0) {
            $this->error = '无效的文件大小';
            return false;
        }

        $max_size = 20 * 1024 * 1024;
        if ($filesize > $max_size) {
            $this->error = sprintf(
                '文件大小超过限制: %s (最大: %s)',
                $this->formatFileSize($filesize),
                $this->formatFileSize($max_size)
            );
            return false;
        }

        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            $this->error = '无效的图片文件';
            return false;
        }

        $allowed_types = array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/tiff',
            'image/bmp',
            'image/x-icon',
            'image/vnd.adobe.photoshop',
            'image/webp'
        );

        $allowed_extensions = array(
            'jpeg', 'jpg', 'png', 'gif', 'tif', 'tiff',
            'bmp', 'ico', 'psd', 'webp'
        );

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            $this->error = '不支持的文件扩展名: ' . $ext;
            return false;
        }

        $mime_type = $image_info['mime'];
        if (!in_array($mime_type, $allowed_types)) {
            $this->error = '不支持的文件类型: ' . $mime_type;
            return false;
        }

        return array(
            'mime_type' => $mime_type,
            'width' => $image_info[0],
            'height' => $image_info[1],
            'size' => $filesize,
            'extension' => $ext
        );
    }

    private function formatFileSize($size) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    private function logImageInfo($file_path, $image_info) {
        $this->debug_log(sprintf(
            '图片信息: %s (类型: %s, 尺寸: %dx%d, 大小: %s)',
            basename($file_path),
            $image_info['mime_type'],
            $image_info['width'],
            $image_info['height'],
            $this->formatFileSize($image_info['size'])
        ));
    }
}
