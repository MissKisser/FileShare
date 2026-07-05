<?php
/**
 * PHPUnit 启动入口
 *
 * 加载 Composer 自动加载（如果存在）+ 应用 config/database/functions。
 * 单元测试中如需独立 DB，可通过 TEST_DB_PATH 环境变量切换。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ACCESS_ALLOWED', true);
define('ROOT_DIR', dirname(__DIR__));
define('PUBLIC_DIR', ROOT_DIR);
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads/');
define('STORAGE_DIR', PUBLIC_DIR . '/storage/');
define('DATA_FILE', STORAGE_DIR . 'data.json');

// Composer 自动加载（如果 vendor/ 存在）
$autoload = ROOT_DIR . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // 无 Composer 时手动加载核心文件
    require_once ROOT_DIR . '/src/config.php';
    require_once ROOT_DIR . '/src/database.php';
    require_once ROOT_DIR . '/src/functions.php';
}

require_once __DIR__ . '/helpers/HttpHelper.php';
