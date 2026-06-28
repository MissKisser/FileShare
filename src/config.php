<?php
/**
 * 系统配置
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) {
    exit('Access Denied');
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

// ============================================================
// 上传配置
// ============================================================
// PHP 运行时配置说明：
// upload_max_filesize / post_max_size 是 PHP_INI_PERDIR 模式，
// ini_set() 无法修改。必须在 php.ini 或 .user.ini 中设置。
// 当前服务器 php.ini 中已配置 1024M（足够大，业务层另有限制）。

// 业务层上传限制
define('MAX_FILE_SIZE_NORMAL', 200 * 1024 * 1024);   // 200MB，普通用户
define('MAX_FILE_SIZE_LARGE', 2048 * 1024 * 1024);    // 2GB，密码验证后
define('UPLOAD_THRESHOLD_FOR_PASSWORD', 200 * 1024 * 1024); // 触发密码验证的阈值

// 大文件上传密码：优先从环境变量读取，其次读取项目根目录 .env 文件
// 注意：部署前必须设置 LARGE_FILE_PASSWORD，否则应用无法启动
$largeFilePassword = getenv('LARGE_FILE_PASSWORD');
if ($largeFilePassword === false || $largeFilePassword === '') {
    $envPath = ROOT_DIR . '/.env';
    if (file_exists($envPath) && is_readable($envPath)) {
        $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, 'LARGE_FILE_PASSWORD=') === 0) {
                $largeFilePassword = substr($line, strlen('LARGE_FILE_PASSWORD='));
                // 去除可能的引号
                $largeFilePassword = trim($largeFilePassword, '"\'');
                break;
            }
        }
    }
}
if (empty($largeFilePassword)) {
    die('Error: LARGE_FILE_PASSWORD is not configured. Please set the LARGE_FILE_PASSWORD environment variable or add it to .env');
}
define('LARGE_FILE_PASSWORD', $largeFilePassword);

// 运行时可调整的配置（PHP_INI_ALL 模式，ini_set 有效）
@ini_set('max_execution_time', '600');
@ini_set('max_input_time', '600');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// 错误处理
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

/**
 * 允许上传的文件扩展名白名单
 */
define('ALLOWED_FILE_EXTENSIONS', [
    // 图片
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
    // 矢量图（SVG 可能包含脚本，但业务上需要，下载时强制 attachment 降低风险）
    'svg',
    // 视频
    'mp4', 'webm', 'ogv', 'ogg', 'avi', 'mov', 'mkv',
    // 音频
    'mp3', 'wav', 'aac', 'flac', 'm4a', 'opus',
    // 文档
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'rtf', 'md', 'markdown',
    // 代码/文本（注意：移除所有可被 web 服务器执行的脚本扩展名）
    'js', 'ts', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift',
    'kt', 'scala', 'rb', 'sh', 'bash', 'ps1', 'bat', 'cmd',
    'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'sql', 'yaml', 'yml', 'txt', 'ini', 'conf', 'log',
    // 压缩包
    'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'lz4'
]);

/**
 * 允许上传的 MIME 类型白名单
 */
define('ALLOWED_FILE_MIMES', [
    // 图片
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-icon',
    'image/svg+xml',
    // 视频
    'video/mp4', 'video/webm', 'video/ogg', 'video/avi', 'video/quicktime', 'video/x-matroska',
    // 音频
    'audio/mpeg', 'audio/wav', 'audio/wave', 'audio/ogg', 'audio/aac', 'audio/flac',
    'audio/mp4', 'audio/opus',
    // 文档
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv', 'text/rtf', 'text/markdown',
    // 代码/文本
    'text/javascript', 'application/javascript', 'application/json', 'text/xml',
    'application/xml', 'text/html', 'text/css', 'text/x-python',
    'text/x-shellscript', 'application/x-sh', 'text/x-c', 'text/x-c++',
    // 压缩包
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
    'application/x-tar', 'application/gzip', 'application/x-gzip', 'application/x-bzip2',
    'application/x-xz'
]);

/**
 * 验证大文件上传密码
 * 使用 hash_equals 防止时序攻击
 */
function verifyLargeFilePassword($password) {
    if (!is_string($password)) return false;
    $expected = LARGE_FILE_PASSWORD;
    return hash_equals($expected, $password);
}
