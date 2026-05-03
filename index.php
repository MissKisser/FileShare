<?php
session_start();
session_regenerate_id(true);

// 生成CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
