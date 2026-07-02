<?php
/**
 * 请求处理器
 * 作者：Hackerdallas
 * 
 * 重构为 SQLite 数据库操作，新增分享、预览、批量操作等路由
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

/**
 * 主请求路由分发
 */
function handleRequest() {
    // ===== 分享页面路由（F1） =====
    if (isset($_GET['s'])) {
        handleSharePage();
        return;
    }

    // ===== 预览路由（F5） =====
    if (isset($_GET['preview'])) {
        handlePreview();
        return;
    }

    // ===== API 路由（F3） =====
    if (isset($_GET['api'])) {
        require_once __DIR__ . '/api.php';
        handleApiRequest();
        return;
    }

    // ===== 管理后台路由（F9） =====
    if (isset($_GET['admin'])) {
        require_once __DIR__ . '/admin.php';
        handleAdminRequest();
        return;
    }

    // ===== 迁移路由 =====
    if (isset($_GET['action']) && $_GET['action'] === 'migrate') {
        require_once __DIR__ . '/migrate.php';
        header('Content-Type: application/json; charset=utf-8');
        $result = runMigration();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== 搜索/过滤 AJAX 路由（F6） =====
    if (isset($_GET['action']) && $_GET['action'] === 'search') {
        handleSearch();
        return;
    }

    // ===== 批量删除路由（F7） =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
        handleBatchDelete();
        return;
    }

    // ===== 分享密码验证路由（F2） =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_share_password') {
        handleSharePasswordVerify();
        return;
    }

    // ===== 大文件密码预校验请求（AJAX 调用） =====
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

    // ===== 文件上传 =====
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

        // 普通文件大小限制
        if (!$hasLargeFile && isset($_FILES['files']['size']) && is_array($_FILES['files']['size'])) {
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

        handleFileUpload();
        return;
    }

    // ===== 文本保存 =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
        handleTextSave();
        return;
    }
    
    // ===== 删除（仅POST） =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
        handleDelete();
        return;
    }
    
    // ===== 下载 =====
    if (isset($_GET['download'])) {
        handleDownload();
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

// ============================================================
// 文件上传处理
// ============================================================
function handleFileUpload() {
    $db = getDB();
    header('Content-Type: application/json; charset=utf-8');

    // CSRF验证
    if (!validateCSRF()) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $duration = intval($_POST['duration'] ?? 600);
        $accessPassword = $_POST['access_password'] ?? ''; // F2 访问密码
        $files = $_FILES['files'];
        $uploadCount = 0;
        $errors = [];
        $uploadedItems = []; // 返回上传成功项目的信息

        // 统一为数组结构
        if (!is_array($files['name'])) {
            foreach ($files as $key => $value) {
                $files[$key] = [$value];
            }
        }

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

                // 计算文件哈希（F10 去重）
                $fileHash = hash_file('sha256', $files['tmp_name'][$i]);

                // 检查是否有相同哈希的文件已存在
                $dupStmt = $db->prepare('SELECT id, path, share_code FROM items WHERE file_hash = ? AND type = \'file\' LIMIT 1');
                $dupStmt->execute([$fileHash]);
                $duplicate = $dupStmt->fetch();

                // 净化文件名
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
                $safeName = substr($safeName, 0, 200);

                if ($duplicate && !empty($duplicate['path']) && file_exists($duplicate['path'])) {
                    // 去重：复用已有文件路径
                    $filepath = $duplicate['path'];
                } else {
                    // 新文件
                    $filename = time() . '_' . uniqid() . '_' . $safeName;
                    $filepath = UPLOAD_DIR . $filename;

                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0755, true);
                    }

                    if (!move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                        $errors[] = "文件 {$originalName} 移动失败";
                        continue;
                    }
                }

                // 检测 MIME 类型
                $mimeType = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $filepath);
                    finfo_close($finfo);
                }

                $expire = $duration === 0 ? 0 : time() + $duration;
                $shareCode = generateShareCode($db);

                // 密码处理
                $passwordHash = null;
                if (!empty($accessPassword)) {
                    $passwordHash = password_hash($accessPassword, PASSWORD_BCRYPT);
                }

                // 插入数据库
                $stmt = $db->prepare('
                    INSERT INTO items (share_code, type, name, path, size, file_hash, mime_type, password, download_count, ip, user_agent, time, expire, duration)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $shareCode,
                    'file',
                    $originalName,
                    $filepath,
                    $files['size'][$i],
                    $fileHash,
                    $mimeType,
                    $passwordHash,
                    0,
                    getRealIP(),
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    time(),
                    $expire,
                    $duration
                ]);

                $itemId = $db->lastInsertId();

                // 记录上传日志
                logUploadToDb($itemId, $originalName, $files['size'][$i], $duration);

                $uploadCount++;
                $uploadedItems[] = [
                    'id' => $itemId,
                    'name' => $originalName,
                    'share_code' => $shareCode,
                    'share_url' => getBaseUrl() . '?s=' . $shareCode,
                    'size' => $files['size'][$i],
                ];
            } else {
                $errors[] = "文件 {$files['name'][$i]} 上传错误（代码：{$files['error'][$i]}）";
            }
        }

        $response = [
            'success' => $uploadCount > 0,
            'message' => $uploadCount > 0 ? "成功上传 {$uploadCount} 个文件" : '上传失败',
            'uploaded' => $uploadCount,
            'errors' => $errors,
            'items' => $uploadedItems,
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

// ============================================================
// 文本保存处理
// ============================================================
function handleTextSave() {
    // CSRF验证
    if (!validateCSRF()) {
        $_SESSION['message'] = '安全验证失败，请刷新页面重试';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $text = $_POST['text'] ?? '';
    $duration = intval($_POST['text_duration'] ?? 600);
    $accessPassword = $_POST['access_password'] ?? ''; // F2 访问密码

    if (!empty(trim($text))) {
        $db = getDB();
        $expire = $duration === 0 ? 0 : time() + $duration;
        $shareCode = generateShareCode($db);

        // 密码处理
        $passwordHash = null;
        if (!empty($accessPassword)) {
            $passwordHash = password_hash($accessPassword, PASSWORD_BCRYPT);
        }

        $stmt = $db->prepare('
            INSERT INTO items (share_code, type, content, size, password, download_count, ip, user_agent, time, expire, duration)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $shareCode,
            'text',
            $text,
            strlen($text),
            $passwordHash,
            0,
            getRealIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            time(),
            $expire,
            $duration
        ]);

        $itemId = $db->lastInsertId();

        // 记录日志
        logUploadToDb($itemId, '文本内容 (' . mb_substr($text, 0, 20) . '...)', strlen($text), $duration);

        $_SESSION['message'] = '文本保存成功！分享链接：' . getBaseUrl() . '?s=' . $shareCode;
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================================
// 删除处理
// ============================================================
function handleDelete() {
    // 只接受POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // CSRF Token验证
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['message'] = '安全验证失败！';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $id = intval($_POST['delete'] ?? -1);
    if ($id > 0) {
        if (deleteItemById($id)) {
            $_SESSION['message'] = '删除成功！';
        } else {
            $_SESSION['message'] = '项目不存在或已删除';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================================
// 批量删除处理（F7）
// ============================================================
function handleBatchDelete() {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF验证
    if (!validateCSRF()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '安全验证失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [$ids];
    }

    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => '未选择任何项目'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = batchDeleteItems($ids);
    echo json_encode([
        'success' => $result['deleted'] > 0,
        'message' => "成功删除 {$result['deleted']} 个项目",
        'deleted' => $result['deleted'],
        'errors' => $result['errors'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 下载处理
// ============================================================
function handleDownload() {
    $id = intval($_GET['download']);
    $item = getItemById($id);

    if ($item && $item['type'] === 'file') {
        // 检查密码保护（F2）
        if (!empty($item['password'])) {
            $unlockedKey = 'unlocked_' . $item['share_code'];
            if (empty($_SESSION[$unlockedKey])) {
                // 需要密码验证，重定向到分享页
                header('Location: ' . $_SERVER['PHP_SELF'] . '?s=' . $item['share_code']);
                exit;
            }
        }

        if (file_exists($item['path'])) {
            // 增加下载计数（F11）
            incrementDownloadCount($item['id'], getRealIP(), $_SERVER['HTTP_USER_AGENT'] ?? '');

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . htmlspecialchars(basename($item['name']), ENT_QUOTES, 'UTF-8') . '"');
            header('Content-Length: ' . filesize($item['path']));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            readfile($item['path']);
            exit;
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================================
// 分享页面处理（F1）
// ============================================================
function handleSharePage() {
    $code = $_GET['s'] ?? '';
    if (empty($code)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $item = getItemByCode($code);
    if (!$item) {
        http_response_code(404);
        define('SHARE_PAGE', true);
        $shareError = '项目不存在或已过期';
        require_once __DIR__ . '/../templates/share.php';
        exit;
    }

    // 检查是否过期
    if ($item['expire'] > 0 && $item['expire'] < time()) {
        http_response_code(410);
        define('SHARE_PAGE', true);
        $shareError = '该项目已过期';
        require_once __DIR__ . '/../templates/share.php';
        exit;
    }

    // 检查密码保护（F2）
    $unlocked = true;
    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $code;
        if (empty($_SESSION[$unlockedKey])) {
            $unlocked = false;
        }
    }

    define('SHARE_PAGE', true);
    require_once __DIR__ . '/../templates/share.php';
    exit;
}

// ============================================================
// 分享密码验证（F2）
// ============================================================
function handleSharePasswordVerify() {
    header('Content-Type: application/json; charset=utf-8');

    if (!validateCSRF()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '安全验证失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $shareCode = $_POST['share_code'] ?? '';
    $password = $_POST['password'] ?? '';

    $item = getItemByCode($shareCode);
    if (!$item) {
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($item['password'])) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (password_verify($password, $item['password'])) {
        $_SESSION['unlocked_' . $shareCode] = true;
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => '密码错误'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// 预览处理（F5）
// ============================================================
function handlePreview() {
    $code = $_GET['preview'] ?? '';
    $item = getItemByCode($code);

    if (!$item || $item['type'] !== 'file') {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // 检查密码保护
    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $code;
        if (empty($_SESSION[$unlockedKey])) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?s=' . $code);
            exit;
        }
    }

    // 检查文件是否存在
    if (empty($item['path']) || !file_exists($item['path'])) {
        http_response_code(404);
        echo '文件不存在';
        exit;
    }

    // 增加查看计数
    incrementDownloadCount($item['id'], getRealIP(), $_SERVER['HTTP_USER_AGENT'] ?? '');

    // 根据文件类型决定预览方式
    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    $mimeType = $item['mime_type'] ?: 'application/octet-stream';

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];
    $videoExts = ['mp4', 'webm', 'ogv', 'ogg'];
    $audioExts = ['mp3', 'wav', 'aac', 'flac', 'm4a', 'opus'];
    $pdfExts = ['pdf'];

    if (in_array($ext, $imageExts)) {
        // 图片：直接输出
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($item['path']));
        readfile($item['path']);
        exit;
    } elseif (in_array($ext, $videoExts)) {
        // 视频：流式输出（支持 Range 请求）
        streamFile($item['path'], $mimeType);
        exit;
    } elseif (in_array($ext, $audioExts)) {
        // 音频：流式输出
        streamFile($item['path'], $mimeType);
        exit;
    } elseif (in_array($ext, $pdfExts)) {
        // PDF：内联显示
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . htmlspecialchars(basename($item['name']), ENT_QUOTES, 'UTF-8') . '"');
        header('Content-Length: ' . filesize($item['path']));
        readfile($item['path']);
        exit;
    }

    // 其他类型不支持在线预览
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * 流式输出文件（支持 Range 请求，用于视频/音频）
 */
function streamFile($path, $mimeType) {
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;

    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');

    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        $range = str_replace('bytes=', '', $range);
        list($start, $end) = explode('-', $range);
        $start = intval($start);
        $end = empty($end) ? $size - 1 : intval($end);
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    header('Content-Length: ' . ($end - $start + 1));
    header('Content-Disposition: inline');

    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    $bufferSize = 8192;
    while ($remaining > 0 && !feof($fp)) {
        $read = min($bufferSize, $remaining);
        echo fread($fp, $read);
        $remaining -= $read;
        flush();
    }
    fclose($fp);
}

// ============================================================
// 搜索处理（F6）
// ============================================================
function handleSearch() {
    header('Content-Type: application/json; charset=utf-8');

    $query = $_GET['q'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';
    $categoryFilter = $_GET['category'] ?? '';
    $sort = $_GET['sort'] ?? 'time';
    $sortOrder = $_GET['order'] ?? 'desc';

    $items = searchItems($query, $typeFilter, $categoryFilter, $sort, $sortOrder);

    // 格式化输出
    $result = [];
    foreach ($items as $item) {
        $result[] = [
            'id' => $item['id'],
            'share_code' => $item['share_code'],
            'type' => $item['type'],
            'name' => $item['name'],
            'size' => $item['size'],
            'size_formatted' => formatSize($item['size']),
            'time' => $item['time'],
            'time_formatted' => date('Y-m-d H:i', $item['time']),
            'expire' => $item['expire'],
            'expire_formatted' => formatExpire($item['expire']),
            'download_count' => $item['download_count'],
            'has_password' => !empty($item['password']),
            'content_preview' => $item['type'] === 'text' ? mb_substr($item['content'] ?? '', 0, 150) : null,
        ];
    }

    echo json_encode(['success' => true, 'items' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 辅助函数
// ============================================================

/**
 * 获取站点基础 URL
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . '://' . $host . ($path === '/' ? '' : $path);
}
