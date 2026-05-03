<?php
/**
 * 请求处理器
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// 禁止上传的可执行文件扩展名
define('BLOCKED_EXTENSIONS', ['php', 'phtml', 'php3', 'php5', 'phar', 'phps', 'pht', 'php7', 'php8', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'msi', 'jar', 'war', 'asp', 'aspx', 'jsp']);

// 允许的MIME类型
define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/ico',
    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv', 'text/html', 'text/xml',
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip',
    'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/flac',
    'video/mp4', 'video/webm', 'video/ogg', 'video/x-matroska', 'video/quicktime',
    'application/json', 'application/xml', 'application/javascript', 'application/octet-stream'
]);

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

/**
 * 验证文件类型安全性
 */
function validateFileType($filename, $tmpPath) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, BLOCKED_EXTENSIONS)) {
        return ['valid' => false, 'error' => "禁止上传可执行文件类型: .{$ext}"];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($mimeType && !in_array($mimeType, ALLOWED_MIME_TYPES)) {
        if (strpos($mimeType, 'php') !== false || strpos($mimeType, 'x-php') !== false) {
            return ['valid' => false, 'error' => "禁止上传PHP相关文件"];
        }
    }

    return ['valid' => true];
}

function handleRequest() {
    $data = loadData();
    
    // 处理文件上传
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
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

// 获取真实IP地址（带验证）
function getRealIP() {
    $ip = null;

    // 按优先级检查各HTTP头
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }

    // 验证IP格式，防止IP欺骗
    if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $ip;
    }

    // 回退到REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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