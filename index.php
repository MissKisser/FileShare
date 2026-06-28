<?php
session_start();
// 仅在首次创建 session 时 regenerate id，避免每次请求都换 session
// 导致前端持有的旧 csrf_token 在新 session 中失效（CSRF 误拒）
if (empty($_SESSION['initialized'])) {
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}


// 生成CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 标记允许访问核心文件
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/handlers.php';

handleRequest();

$data = loadData();
cleanExpired($data);

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

// 获取最近上传日志
$uploadLogs = getUploadLogs(10);

require_once __DIR__ . '/templates/main.php';
