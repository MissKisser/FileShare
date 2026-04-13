<?php
/**
 * 系统配置
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

// 目录配置
define('ROOT_DIR', dirname(__DIR__));
define('PUBLIC_DIR', ROOT_DIR);
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads/');
define('STORAGE_DIR', PUBLIC_DIR . '/storage/');
define('DATA_FILE', STORAGE_DIR . 'data.json');

// 创建必要目录
$dirs = [UPLOAD_DIR, STORAGE_DIR];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// PHP 配置
@ini_set('upload_max_filesize', '5000M');
@ini_set('post_max_size', '5000M');
@ini_set('max_execution_time', '6000');
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// 错误处理
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
