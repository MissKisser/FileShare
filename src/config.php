<?php
/**
 * 系统配置
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) {
    exit('Access Denied');
}

// 应用版本号，修改会改变静态资源 URL 路径以破坏浏览器缓存
define('APP_VERSION', '1.0.5');

// 路径常量
define('ROOT_DIR', dirname(__DIR__));
define('PUBLIC_DIR', ROOT_DIR);
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads/');
define('STORAGE_DIR', PUBLIC_DIR . '/storage/');
define('DATA_FILE', STORAGE_DIR . 'data.json');

// 确保运行所需目录存在
$dirs = [UPLOAD_DIR, STORAGE_DIR];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============================================================
// 环境变量加载
// ============================================================

/**
 * 从系统环境变量或项目 .env 文件读取配置
 *
 * @param string $key 变量名
 * @param string $default 未找到时返回的默认值
 * @return string 读取到的字符串值
 *
 * 设计意图见 docs/DESIGN_INTENT.md
 */
function loadEnvVar($key, $default = '') {
    // 优先读取进程环境变量
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    // 回退：解析项目根目录下的 .env 文件
    $envPath = ROOT_DIR . '/.env';
    if (file_exists($envPath) && is_readable($envPath)) {
        $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $prefix = $key . '=';
            if (strpos($line, $prefix) === 0) {
                $value = substr($line, strlen($prefix));
                return trim($value, '"\'');
            }
        }
    }

    return $default;
}

// ============================================================
// 上传配置
// ============================================================
// upload_max_filesize / post_max_size 由 php.ini / .user.ini 控制
// （PHP_INI_PERDIR 模式，运行期 ini_set 无效），业务层另设上限兜底。

// 业务层大小限制
define('MAX_FILE_SIZE_NORMAL', 200 * 1024 * 1024);          // 普通上传上限
define('MAX_FILE_SIZE_LARGE', 2048 * 1024 * 1024);          // 密码验证后大文件上限
define('UPLOAD_THRESHOLD_FOR_PASSWORD', 200 * 1024 * 1024); // 触发大文件密码验证的阈值

// 大文件上传密码（必填，启动时校验）
$largeFilePassword = loadEnvVar('LARGE_FILE_PASSWORD');
if (empty($largeFilePassword)) {
    die('Error: LARGE_FILE_PASSWORD is not configured. Please set the LARGE_FILE_PASSWORD environment variable or add it to .env');
}
define('LARGE_FILE_PASSWORD', $largeFilePassword);

// 管理员后台登录密码
define('ADMIN_PASSWORD', loadEnvVar('ADMIN_PASSWORD', ''));

// 管理员会话在 PHP session 中的键名，admin.php 与 handlers.php 共用
define('ADMIN_SESSION_NAME', 'fileshare_admin');

// REST API 总开关
define('API_ENABLED', loadEnvVar('API_ENABLED', '1') === '1');

// 站点显示标题
define('SITE_TITLE', loadEnvVar('SITE_TITLE', '文件上传与文本存储系统'));

// 运行时 ini 配置（PHP_INI_ALL，可在脚本中调整）
@ini_set('max_execution_time', '600');
@ini_set('max_input_time', '600');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// 错误输出级别
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

/**
 * 允许上传的文件扩展名白名单
 *
 * 设计意图见 docs/DESIGN_INTENT.md
 */
define('ALLOWED_FILE_EXTENSIONS', [
    // 图片
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
    // 矢量图
    'svg',
    // 视频
    'mp4', 'webm', 'ogv', 'ogg', 'avi', 'mov', 'mkv',
    // 音频
    'mp3', 'wav', 'aac', 'flac', 'm4a', 'opus',
    // 文档
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'rtf', 'md', 'markdown',
    // 代码与文本
    'js', 'ts', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift',
    'kt', 'scala', 'rb', 'sh', 'bash', 'ps1', 'bat', 'cmd',
    'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'sql', 'yaml', 'yml', 'txt', 'ini', 'conf', 'log',
    // 压缩包
    'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'lz4',
    // Android 安装包
    'apk'
]);

/**
 * 允许上传的 MIME 类型白名单
 *
 * 设计意图见 docs/DESIGN_INTENT.md
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
    // 代码与文本
    'text/javascript', 'application/javascript', 'application/json', 'text/xml',
    'application/xml', 'text/html', 'text/css', 'text/x-python',
    'text/x-shellscript', 'application/x-sh', 'text/x-c', 'text/x-c++',
    // 压缩包
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
    'application/x-tar', 'application/gzip', 'application/x-gzip', 'application/x-bzip2',
    'application/x-xz',
    // Android 安装包
    'application/vnd.android.package-archive'
]);

/**
 * 校验大文件上传密码
 *
 * @param string $password 用户提交的明文密码
 * @return bool 密码匹配返回 true，否则 false
 *
 * 设计意图见 docs/DESIGN_INTENT.md
 */
function verifyLargeFilePassword($password) {
    if (!is_string($password)) return false;
    $expected = LARGE_FILE_PASSWORD;
    return hash_equals($expected, $password);
}
