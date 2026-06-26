<?php
/**
 * 请求处理器
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// 安全限制由 Nginx 配置保障（uploads 目录禁止执行脚本）
// 以下仅保留基本检查，防止明显恶意文件

/**
 * 验证文件类型安全性（白名单机制）
 */
function validateFileType($filename, $tmpPath) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // 扩展名白名单校验
    if (!in_array($ext, ALLOWED_FILE_EXTENSIONS, true)) {
        return ['valid' => false, 'error' => "不支持的文件类型: .{$ext}"];
    }

    // MIME 类型白名单校验（如果 finfo 可用）
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        // 清理 MIME，例如 image/svg+xml; charset=utf-8 -> image/svg+xml
        if ($mimeType) {
            $parts = explode(';', $mimeType);
            $mimeType = strtolower(trim($parts[0]));
        }

        if ($mimeType && !in_array($mimeType, ALLOWED_FILE_MIMES, true)) {
            return ['valid' => false, 'error' => "不支持的文件类型(MIME: {$mimeType})"];
        }
    }

    return ['valid' => true];
}

/**
 * 检查当前会话/IP 是否超过大文件密码验证频率限制
 * 返回 true 表示被限制
 */
function isRateLimited($maxAttempts = 5, $windowSeconds = 60) {
    $now = time();
    if (!isset($_SESSION['large_file_password_attempts'])) {
        $_SESSION['large_file_password_attempts'] = [];
    }
    $attempts = &$_SESSION['large_file_password_attempts'];
    // 清理过期记录
    $attempts = array_filter($attempts, function ($timestamp) use ($now, $windowSeconds) {
        return $now - $timestamp < $windowSeconds;
    });
    $attempts = array_values($attempts);

    return count($attempts) >= $maxAttempts;
}

/**
 * 记录一次大文件密码验证尝试
 */
function recordRateLimitAttempt() {
    if (!isset($_SESSION['large_file_password_attempts'])) {
        $_SESSION['large_file_password_attempts'] = [];
    }
    $_SESSION['large_file_password_attempts'][] = time();
}

/**
 * 验证CSRF Token
 */
function validateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'], $token);
}

function handleRequest() {
    $data = loadData();

    // 处理大文件密码预校验请求（AJAX 调用）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_large_file_password') {
        header('Content-Type: application/json; charset=utf-8');

        // CSRF 校验
        if (!validateCSRF()) {
            http_response_code(403);
            echo json_encode(['verified' => false, 'message' => '安全验证失败，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 速率限制：5 次/分钟
        if (isRateLimited(5, 60)) {
            http_response_code(429);
            echo json_encode(['verified' => false, 'message' => '尝试次数过多，请稍后再试'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        recordRateLimitAttempt();

        $pwd = $_POST['password'] ?? '';
        if (verifyLargeFilePassword($pwd)) {
            echo json_encode(['verified' => true]);
        } else {
            echo json_encode(['verified' => false, 'message' => '密码错误']);
        }
        exit;
    }

    // 处理文件上传
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
        // 大文件密码二次校验（防绕过前端）
        $hasLargeFile = false;
        if (isset($_FILES['files']['size']) && is_array($_FILES['files']['size'])) {
            foreach ($_FILES['files']['size'] as $size) {
                if ($size > MAX_FILE_SIZE_NORMAL) {
                    $hasLargeFile = true;
                    break;
                }
            }
        } elseif (isset($_FILES['files']['size']) && $_FILES['files']['size'] > MAX_FILE_SIZE_NORMAL) {
            $hasLargeFile = true;
        }

        if ($hasLargeFile) {
            $pwd = $_POST['large_file_password'] ?? '';
            if (!verifyLargeFilePassword($pwd)) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => '大文件上传密码错误或缺失'
                ]);
                exit;
            }
            // 再次校验：单文件大小不能超过密码授权上限
            if (isset($_FILES['files']['size']) && is_array($_FILES['files']['size'])) {
                foreach ($_FILES['files']['size'] as $size) {
                    if ($size > MAX_FILE_SIZE_LARGE) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(413);
                        echo json_encode([
                            'success' => false,
                            'message' => '文件超过授权上传上限（' . (MAX_FILE_SIZE_LARGE / 1024 / 1024) . 'MB）'
                        ]);
                        exit;
                    }
                }
            }
        }

        // 普通文件大小限制（不需密码）
        if (isset($_FILES['files']['size']) && is_array($_FILES['files']['size'])) {
            foreach ($_FILES['files']['size'] as $size) {
                if ($size > MAX_FILE_SIZE_NORMAL) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(413);
                    echo json_encode([
                        'success' => false,
                        'message' => '文件超过普通上传上限（' . (MAX_FILE_SIZE_NORMAL / 1024 / 1024) . 'MB），请使用大文件密码'
                    ]);
                    exit;
                }
            }
        }

        handleFileUpload($data);
        return;
    }

    // 处理文本保存
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
        handleTextSave($data);
        return;
    }
    
    // 处理删除（仅POST）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
        handleDelete($data);
        return;
    }
    
    // 处理下载
    if (isset($_GET['download'])) {
        handleDownload($data);
        return;
    }
}

// 获取真实IP地址
// 默认信任 REMOTE_ADDR；仅在 REMOTE_ADDR 属于可信代理时读取 X-Forwarded-For 等头
function getRealIP() {
    // 可通过环境变量或 .env 配置可信代理，例如 TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
    $trustedProxies = [];
    $trustedEnv = getenv('TRUSTED_PROXIES');
    if ($trustedEnv) {
        $trustedProxies = array_map('trim', explode(',', $trustedEnv));
    }

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($trustedProxies && $remoteAddr !== 'unknown') {
        foreach ($trustedProxies as $cidr) {
            if (ipInCidr($remoteAddr, $cidr)) {
                // 信任该代理，尝试从 forwarded 头获取真实 IP
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    foreach ($ips as $candidate) {
                        $candidate = trim($candidate);
                        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            return $candidate;
                        }
                    }
                }
                if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $_SERVER['HTTP_X_REAL_IP'];
                }
                if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $_SERVER['HTTP_CLIENT_IP'];
                }
                break;
            }
        }
    }

    return $remoteAddr;
}

// 辅助函数：判断 IP 是否在 CIDR 段内
function ipInCidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $bits) = explode('/', $cidr);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    $mask = -1 << (32 - (int)$bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

// 记录上传日志
function logUpload($filename, $filesize, $duration) {
    $logFile = STORAGE_DIR . 'upload_log.json';
    
    $logs = [];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logs = json_decode($content, true) ?: [];
    }

    $logs[] = [
        'ip' => getRealIP(),
        'filename' => $filename,
        'filesize' => $filesize,
        'upload_time' => time(),
        'duration' => $duration,
        'expire_time' => $duration === 0 ? 0 : (time() + $duration),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    if (count($logs) > 500) {
        $logs = array_slice($logs, -500);
    }

    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 获取上传日志
function getUploadLogs($limit = 50) {
    $logFile = STORAGE_DIR . 'upload_log.json';
    
    if (!file_exists($logFile)) {
        return [];
    }
    
    $content = file_get_contents($logFile);
    $logs = json_decode($content, true) ?: [];
    
    return array_slice(array_reverse($logs), 0, $limit);
}

// 处理文件上传
function handleFileUpload(&$data) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF验证
    if (!validateCSRF()) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $duration = intval($_POST['duration'] ?? 600);
        $files = $_FILES['files'];
        $uploadCount = 0;
        $errors = [];

        $fileCount = count($files['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $originalName = $files['name'][$i];

                // 文件类型安全验证
                $validation = validateFileType($originalName, $files['tmp_name'][$i]);
                if (!$validation['valid']) {
                    $errors[] = $validation['error'];
                    continue;
                }

                // 净化文件名：移除危险字符，限制长度
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
                $safeName = substr($safeName, 0, 200);
                $filename = time() . '_' . uniqid() . '_' . $safeName;
                $filepath = UPLOAD_DIR . $filename;

                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }

                if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                    $expire = $duration === 0 ? 0 : time() + $duration;
                    $data[] = [
                        'type' => 'file',
                        'name' => $originalName,
                        'path' => $filepath,
                        'size' => $files['size'][$i],
                        'time' => time(),
                        'expire' => $expire,
                        'ip' => getRealIP()
                    ];

                    logUpload($originalName, $files['size'][$i], $duration);

                    $uploadCount++;
                } else {
                    $errors[] = "文件 {$originalName} 移动失败";
                }
            } else {
                $errors[] = "文件 {$files['name'][$i]} 上传错误（代码：{$files['error'][$i]}）";
            }
        }

        if ($uploadCount > 0) {
            saveData($data);
        }

        $response = [
            'success' => $uploadCount > 0,
            'message' => $uploadCount > 0 ? "成功上传 {$uploadCount} 个文件" : '上传失败',
            'uploaded' => $uploadCount,
            'errors' => $errors
        ];

        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        echo json_encode($response, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        echo json_encode([
            'success' => false,
            'message' => '上传异常：' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// 处理文本保存
function handleTextSave(&$data) {
    // CSRF验证
    if (!validateCSRF()) {
        $_SESSION['message'] = '安全验证失败，请刷新页面重试';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $text = $_POST['text'] ?? '';
    $duration = intval($_POST['text_duration'] ?? 600);
    if (!empty(trim($text))) {
        $data[] = [
            'type' => 'text',
            'content' => $text,
            'time' => time(),
            'expire' => $duration === 0 ? 0 : time() + $duration,
            'ip' => getRealIP()
        ];
        saveData($data);

        logUpload('文本内容 (' . mb_substr($text, 0, 20) . '...)', strlen($text), $duration);

        $_SESSION['message'] = '文本保存成功！';
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理删除（仅POST + CSRF验证）
function handleDelete(&$data) {
    // 只接受POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // CSRF Token验证
    session_start();
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['message'] = '安全验证失败！';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $index = intval($_POST['delete'] ?? -1);
    if ($index >= 0 && isset($data[$index])) {
        if ($data[$index]['type'] === 'file' && file_exists($data[$index]['path'])) {
            @unlink($data[$index]['path']);
        }
        unset($data[$index]);
        $data = array_values($data);
        saveData($data);
        $_SESSION['message'] = '删除成功！';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理下载
function handleDownload(&$data) {
    $index = intval($_GET['download']);
    if (isset($data[$index]) && $data[$index]['type'] === 'file') {
        $file = $data[$index];
        if (file_exists($file['path'])) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . htmlspecialchars(basename($file['name']), ENT_QUOTES, 'UTF-8') . '"');
            header('Content-Length: ' . filesize($file['path']));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            readfile($file['path']);
            exit;
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}