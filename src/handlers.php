<?php
/**
 * 请求处理器
 * 作者：Hackerdallas
 *
 * 重构为 SQLite 数据库操作，新增分享、预览、批量操作等路由
 *
 * 设计意图见 docs/DESIGN_INTENT.md
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// 仍然依赖 Nginx 兜底：uploads/ 目录禁止 PHP 脚本被服务器解析。
/**
 * 文件类型校验
 *
 * 始终返回 valid=true（不再基于扩展名/MIME 白名单拒绝）。函数保留以兼容
 * functions.php / chunk_upload.php 的现有调用点。
 *
 * @param string $filename 原始文件名
 * @param string $tmpPath  临时文件路径
 * @return array{valid: bool}
 */
function validateFileType($filename, $tmpPath) {
    return ['valid' => true];
}

/**
 * 大文件密码验证按会话/IP 维度的限流检查
 *
 * @param int $maxAttempts   窗口内最大尝试次数
 * @param int $windowSeconds 窗口大小（秒）
 * @return bool true=已被限流
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
 *
 * @return void
 */
function recordRateLimitAttempt() {
    if (!isset($_SESSION['large_file_password_attempts'])) {
        $_SESSION['large_file_password_attempts'] = [];
    }
    $_SESSION['large_file_password_attempts'][] = time();
}

/**
 * 按任意 key 维度的速率限制检查
 *
 * @param string $key         限流键名（用于隔离不同维度）
 * @param int    $maxAttempts 窗口内最大尝试次数
 * @param int    $windowSeconds 窗口大小（秒）
 * @return bool true=已被限流（应拒绝）
 */
function isRateLimitedByKey($key, $maxAttempts = 5, $windowSeconds = 60) {
    $now = time();
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    $attempts = &$_SESSION[$key];
    $attempts = array_filter($attempts, function ($timestamp) use ($now, $windowSeconds) {
        return $now - $timestamp < $windowSeconds;
    });
    $attempts = array_values($attempts);
    return count($attempts) >= $maxAttempts;
}

/**
 * 记录一次按 key 维度的尝试
 *
 * @param string $key 限流键名
 * @return void
 */
function recordRateLimitByKey($key) {
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    $_SESSION[$key][] = time();
}

/**
 * 验证 CSRF Token
 *
 * @return bool true=校验通过
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
 *
 * 设计意图见 docs/DESIGN_INTENT.md
 */
function handleRequest() {
    // ===== 缩略图懒生成端点 =====
    if (isset($_GET['action']) && $_GET['action'] === 'thumb' && isset($_GET['item_id'])) {
        require_once __DIR__ . '/thumbnail.php';
        header('Content-Type: application/json; charset=utf-8');

        $itemId = intval($_GET['item_id']);
        if ($itemId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的 item_id']);
            exit;
        }

        $newVal = generateItemThumbnail($itemId);
        echo json_encode([
            'success' => true,
            'thumbnail_path' => $newVal,
            'status' => getThumbnailStatus($newVal),
        ]);
        exit;
    }

    // 分享密码验证 POST 必须在分享页渲染之前匹配（避免被 ?s= 吞掉）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_share_password') {
        handleSharePasswordVerify();
        return;
    }

    // ===== 分享页面路由 =====
    if (isset($_GET['s'])) {
        handleSharePage();
        return;
    }

    // ===== 预览路由 =====
    if (isset($_GET['preview'])) {
        handlePreview();
        return;
    }

    // ===== 原始文件输出路由（PDF.js 等需要） =====
    if (isset($_GET['raw'])) {
        handleRawFile();
        return;
    }

    // ===== 鉴权缩略图路由（密码保护文件不直接暴露 /uploads/*.thumb.jpg） =====
    if (isset($_GET['thumb'])) {
        handleThumbnailServe();
        return;
    }

    // ===== 压缩包预览路由 =====
    if (isset($_GET['archive'])) {
        require_once __DIR__ . '/archive.php';
        handleArchiveRequest();
        return;
    }

    // ===== API 路由 =====
    if (isset($_GET['api'])) {
        require_once __DIR__ . '/api.php';
        handleApiRequest();
        return;
    }

    // ===== 管理后台路由 =====
    // 支持 /admin/xxx 路径和 ?admin=xxx 两种方式
    $adminPage = null;
    if (isset($_GET['admin'])) {
        $adminPage = $_GET['admin'];
    } elseif (preg_match('#^/admin(/(.+))?$#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
        $adminPage = isset($m[2]) ? $m[2] : 'login';
    }
    if ($adminPage !== null) {
        $_GET['admin'] = $adminPage;
        // admin.php 已在 index.php 顶层无条件 require_once，无需重复加载
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

    // ===== 搜索/过滤 AJAX 路由 =====
    if (isset($_GET['action']) && $_GET['action'] === 'search') {
        handleSearch();
        return;
    }

    // ===== 批量删除路由 =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
        handleBatchDelete();
        return;
    }

    // ===== owner 自删除路由（通过管理链接 ?s=&manage= 触发） =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'owner_delete') {
        handleOwnerDelete();
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

/**
 * 获取真实 IP
 *
 * 默认信任 REMOTE_ADDR；仅在 REMOTE_ADDR 属于可信代理时读取
 * X-Forwarded-For / X-Real-IP / Client-IP 头。
 *
 * @return string 客户端 IP（不可解析时为 'unknown'）
 */
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

/**
 * 判断 IP 是否在 CIDR 段内
 *
 * @param string $ip   IP 地址
 * @param string $cidr CIDR（如 10.0.0.0/8）或单 IP
 * @return bool
 */
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

/**
 * 文件上传处理
 *
 * 路由：POST + $_FILES['files']
 *
 * @return void
 */
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
        $accessPassword = $_POST['access_password'] ?? ''; // 访问密码
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

                // 复用 createFileItem（类型校验、去重、move、INSERT、日志）
                $result = createFileItem(
                    $originalName,
                    $files['tmp_name'][$i],
                    $files['size'][$i],
                    $duration,
                    $accessPassword,
                    false // 普通上传，调用 move_uploaded_file
                );
                if ($result['error'] !== null) {
                    $errors[] = $result['error'];
                    continue;
                }

                $item = $result['item'];
                $ownerToken = $result['owner_token'];
                $uploadCount++;
                $uploadedItems[] = [
                    'id' => $item['id'],
                    'name' => $originalName,
                    'share_code' => $item['share_code'],
                    'share_url' => getBaseUrl() . '?s=' . $item['share_code'],
                    // owner 管理链接：创建者凭此可删除自己上传
                    'manage_url' => getBaseUrl() . '?s=' . $item['share_code'] . '&manage=' . $ownerToken,
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
        // 异常：记 error_log + 模糊提示（避免泄露 SQL 路径）
        error_log('handleFileUpload failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        echo json_encode([
            'success' => false,
            'message' => '服务器内部错误，请稍后重试'
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

/**
 * 文本保存处理
 *
 * 路由：POST + $_POST['text']
 *
 * @return void
 */
function handleTextSave() {
    // CSRF验证
    if (!validateCSRF()) {
        $_SESSION['message'] = '安全验证失败，请刷新页面重试';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $text = $_POST['text'] ?? '';
    $duration = intval($_POST['text_duration'] ?? 600);
    $accessPassword = $_POST['access_password'] ?? ''; // 访问密码

    if (!empty(trim($text))) {
        // 复用 createTextItem（INSERT、日志）
        $result = createTextItem($text, $duration, $accessPassword);
        $shareCode = $result['item']['share_code'];
        $ownerToken = $result['owner_token'];

        // 跳转到 share 页（带 manage 参数让创建者首次就能看到管理入口）
        $_SESSION['message'] = '文本保存成功！分享链接：' . getBaseUrl() . '?s=' . $shareCode;
        // 用 302 跳到 ?s=&manage= 而不是 ?s=，让首次访问者直接看到 "删除我的上传" 入口
        header('Location: ' . getBaseUrl() . '?s=' . $shareCode . '&manage=' . $ownerToken);
        exit; // 直接 exit，避免被下面的 $_SERVER['PHP_SELF'] header 覆盖
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * 单项删除处理
 *
 * 路由：POST + $_POST['delete']
 *
 * @return void
 */
function handleDelete() {
    header('Content-Type: application/json; charset=utf-8');

    // 只接受POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CSRF Token验证
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = intval($_POST['delete'] ?? -1);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => '参数无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $item = getItemById($id);
    if (!$item) {
        echo json_encode(['success' => false, 'message' => '项目不存在或已删除'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 密码保护检查：有密码的 item 需要验证访问密码
    if (!empty($item['password'])) {
        $accessPassword = $_POST['access_password'] ?? '';
        if ($accessPassword === '') {
            echo json_encode(['success' => false, 'message' => '此内容已设置密码保护，需输入密码才能删除'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!password_verify($accessPassword, $item['password'])) {
            echo json_encode(['success' => false, 'message' => '密码错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 执行删除
    if (deleteItemById($id)) {
        logAdminAction('delete', $id, $item['share_code'] ?? null, $item['type'] ?? null);
        echo json_encode(['success' => true, 'message' => '删除成功'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * 批量删除处理
 *
 * 路由：POST + $_POST['action']=batch_delete
 *
 * @return void
 */
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
    // 规范化：去重 + 强制 int + 过滤无效
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($i) { return $i > 0; });
    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => '未选择任何项目'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 收集前端传来的密码（格式：passwords[<id>] = <password>）
    $passwords = $_POST['passwords'] ?? [];
    if (!is_array($passwords)) {
        $passwords = [];
    }

    // 分离：无需密码的 vs 需要密码验证的
    $directDeleteIds = [];
    $passwordRequiredIds = [];
    $failedIds = [];

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, password FROM items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemMap = [];
    foreach ($items as $item) {
        $itemMap[(int)$item['id']] = $item;
    }

    foreach ($ids as $id) {
        if (!isset($itemMap[$id])) {
            // 项目不存在，跳过（不泄露信息）
            continue;
        }
        $item = $itemMap[$id];
        if (empty($item['password'])) {
            // 无密码，直接可删
            $directDeleteIds[] = $id;
        } else {
            // 有密码，需要验证
            $pwd = $passwords[$id] ?? '';
            if ($pwd !== '' && password_verify($pwd, $item['password'])) {
                $directDeleteIds[] = $id; // 密码正确，加入可删列表
            } else {
                $failedIds[] = $id; // 密码缺失或错误
            }
        }
    }

    // 执行删除
    $deletedCount = 0;
    $deletedCodes = [];
    if (!empty($directDeleteIds)) {
        $result = batchDeleteItems($directDeleteIds);
        $deletedCount = $result['deleted_count'];
        $deletedCodes = $result['deleted'];
        // 审计日志
        foreach ($deletedCodes as $deletedId => $deletedCode) {
            logAdminAction('batch_delete', (int)$deletedId, $deletedCode, null);
        }
    }

    $message = '';
    if ($deletedCount > 0 && count($failedIds) > 0) {
        $message = "成功删除 {$deletedCount} 个项目，" . count($failedIds) . " 个加密项密码错误";
    } elseif ($deletedCount > 0) {
        $message = "成功删除 {$deletedCount} 个项目";
    } elseif (count($failedIds) > 0) {
        $message = '加密项密码验证失败，无法删除';
    } else {
        $message = '没有可删除的项目';
    }

    echo json_encode([
        'success' => $deletedCount > 0,
        'message' => $message,
        'deleted_count' => $deletedCount,
        'failed_count' => count($failedIds),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Owner 自删除
 *
 * 路由：POST + $_POST['action']=owner_delete，通过管理链接 ?s=&manage= 触发。
 *
 * 凭证即 manage_url 里的明文 token（256 位熵，DB 仅存 sha256）。
 * 不需要 CSRF / admin 登录；验证失败统一返回 403 + 模糊信息（防 enumeration）。
 * 复用 deleteItemById → deleteItemsAtomically，事务/FK/引用计数/审计全自动。
 *
 * @return void
 */
function handleOwnerDelete() {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $shareCode = trim($_POST['share_code'] ?? '');
    $token = trim($_POST['manage'] ?? '');
    if (empty($shareCode) || empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 长度校验：sha256 hex 64 位，token 长度上限 256
    if (strlen($shareCode) > 32 || strlen($token) > 256) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数格式错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenHash = hash('sha256', $token);
    $db = getDB();
    $stmt = $db->prepare('SELECT id, share_code, type FROM items WHERE share_code = ? AND owner_token_hash = ?');
    $stmt->execute([$shareCode, $tokenHash]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // 统一错误信息（防 enumeration）
    if (!$item) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '管理链接无效或已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 原子删除（事务、FK 清理、引用计数 unlink、rollback）
    if (deleteItemById((int)$item['id'])) {
        // 审计日志：item_id 写 NULL（避开 FK 约束），share_code / type 写到 filename 列
        logAdminAction('owner_delete', (int)$item['id'], $item['share_code'], $item['type']);
        // 清理 session 标记
        unset($_SESSION['owner_confirmed_' . $shareCode]);
        unset($_SESSION['owner_token_' . $shareCode]);
        echo json_encode(['success' => true, 'message' => '已删除'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '删除失败'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * 下载处理
 *
 * 路由：?download=N
 *
 * @return void
 */
function handleDownload() {
    $id = intval($_GET['download']);
    $item = getItemById($id);

    if ($item && $item['type'] === 'file') {
        // 检查密码保护
        if (!empty($item['password'])) {
            $unlockedKey = 'unlocked_' . $item['share_code'];
            if (empty($_SESSION[$unlockedKey])) {
                // 需要密码验证，重定向到分享页
                header('Location: ' . $_SERVER['PHP_SELF'] . '?s=' . $item['share_code']);
                exit;
            }
        }

        if (file_exists($item['path'])) {
            // 增加下载计数
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

/**
 * 分享页面处理
 *
 * 路由：?s=<share_code>（可选 ?manage=<owner_token> 触发 owner 验证）
 *
 * @return void
 */
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

    // 检查密码保护
    $unlocked = true;
    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $code;
        if (empty($_SESSION[$unlockedKey])) {
            $unlocked = false;
        }
    }

    // Owner token 验证（独立于密码，URL 带 ?manage=<token> 时触发；明文 token 不入 session）
    $isOwner = false;
    $manageToken = '';
    $manageTokenFromUrl = trim($_GET['manage'] ?? '');
    if (!empty($manageTokenFromUrl)) {
        $db = getDB();
        $tokenHash = hash('sha256', $manageTokenFromUrl);
        $chk = $db->prepare('SELECT id FROM items WHERE share_code = ? AND owner_token_hash = ?');
        $chk->execute([$code, $tokenHash]);
        if ($chk->fetch()) {
            $isOwner = true;
            $manageToken = $manageTokenFromUrl;
            $_SESSION['owner_confirmed_' . $code] = true;
        }
    } elseif (!empty($_SESSION['owner_confirmed_' . $code])) {
        // 本会话已验过 owner；manageToken 仍为空（删除要求 URL 持续带 ?manage=）
        $isOwner = true;
    }

    define('SHARE_PAGE', true);
    require_once __DIR__ . '/../templates/share.php';
    exit;
}

/**
 * 分享密码验证
 *
 * 路由：POST + $_POST['action']=verify_share_password
 *
 * @return void
 */
function handleSharePasswordVerify() {
    header('Content-Type: application/json; charset=utf-8');

    if (!validateCSRF()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '安全验证失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $shareCode = $_POST['share_code'] ?? '';
    $password = $_POST['password'] ?? '';

    // 速率限制：按 share_code + IP 维度，10 次/60 秒
    // bcrypt password_verify 单次 ~100ms，无限制会被字典攻击
    $ip = getRealIP();
    $rateLimitKey = 'share_pwd_' . sha1($shareCode . '|' . $ip);
    if (isRateLimitedByKey($rateLimitKey, 10, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => '尝试次数过多，请稍后再试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $item = getItemByCode($shareCode);
    if (!$item) {
        // 项目不存在也记录一次尝试（避免攻击者通过响应区分 code 是否存在）
        recordRateLimitByKey($rateLimitKey);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($item['password'])) {
        // 无密码项目不计入限流（避免误锁合法访问）
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (password_verify($password, $item['password'])) {
        $_SESSION['unlocked_' . $shareCode] = true;
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        // 仅失败时记录（成功不计数，避免合法用户误锁）
        recordRateLimitByKey($rateLimitKey);
        echo json_encode(['success' => false, 'message' => '密码错误'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * 预览处理
 *
 * 路由：?preview=<share_code>
 *
 * @return void
 */
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
        // 图片：直接输出（SVG 强制 attachment + sandbox CSP，防止 SVG 内嵌脚本执行）

        if ($ext === 'svg') {
            header('Content-Type: image/svg+xml');
            header('Content-Disposition: attachment; filename="' . htmlspecialchars(basename($item['name']), ENT_QUOTES, 'UTF-8') . '"');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($item['path']));
            readfile($item['path']);
            exit;
        }
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
        // PDF：渲染 PDF.js 预览页面
        $baseUrl = getBaseUrl();
        $pdfUrl = $baseUrl . '?raw=' . $item['id'];
        $pageTitle = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
        $theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : '';
        $isDark = $theme === 'dark' || (empty($theme) && (
            isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) &&
            $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark'
        ));
        $themeAttr = $isDark ? ' data-theme="dark"' : '';
        $cssDir = '/assets/css/';
        $jsDir = '/assets/js/';
        $v = APP_VERSION;
        echo '<!DOCTYPE html><html lang="zh-CN"' . $themeAttr . '><head>' .
            '<meta charset="UTF-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1.0">' .
            '<title>' . $pageTitle . ' - PDF 预览</title>' .
            '<link rel="icon" type="image/x-icon" href="/favicon.ico">' .
            '<link rel="stylesheet" href="' . $cssDir . 'variables.css?v=' . $v . '">' .
            '<link rel="stylesheet" href="' . $cssDir . 'reset.css?v=' . $v . '">' .
            '<style>' .
            'body{background:var(--bg-primary,#fff);color:var(--text-primary,#111);font-family:Noto Sans SC,sans-serif;margin:0}' .
            '.pdf-toolbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:center;gap:12px;padding:10px 16px;background:var(--card-bg,#fff);border-bottom:1px solid var(--border-color,#e5e7eb);box-shadow:0 2px 4px rgba(0,0,0,.05)}' .
            '.pdf-toolbar button{background:var(--bg-secondary,#f5f5f5);border:1px solid var(--border-color,#e5e7eb);border-radius:4px;padding:6px 12px;cursor:pointer;font-size:14px;color:var(--text-primary,#111)}' .
            '.pdf-toolbar button:disabled{opacity:.4;cursor:default}' .
            '.pdf-toolbar button:hover:not(:disabled){background:var(--accent-blue,#3b82f6);color:#fff}' .
            '#pdfPageInfo{font-size:14px;color:var(--text-secondary,#666);min-width:60px;text-align:center}' .
            '.pdf-canvas-wrap{display:flex;justify-content:center;padding:20px;overflow:auto;background:var(--bg-secondary,#f5f5f5);min-height:calc(100vh - 52px)}' .
            '#pdfCanvas{box-shadow:0 2px 8px rgba(0,0,0,.15);max-width:100%}' .
            '.pdf-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-secondary,#666);text-decoration:none;font-size:14px;margin-right:auto}' .
            '.pdf-back:hover{color:var(--accent-blue,#3b82f6)}' .
            '.pdf-download{margin-left:auto}' .
            '</style></head><body>' .
            '<div class="pdf-toolbar">' .
            '<a href="' . $baseUrl . '?s=' . htmlspecialchars($item['share_code']) . '" class="pdf-back">&larr; 返回</a>' .
            '<button id="pdfPrev" disabled>&#9664; 上一页</button>' .
            '<span id="pdfPageInfo">-</span>' .
            '<button id="pdfNext">下一页 &#9654;</button>' .
            '<button id="pdfZoomOut">-</button>' .
            '<button id="pdfZoomIn">+</button>' .
            '<a href="' . $baseUrl . '?download=' . $item['id'] . '" class="pdf-download" style="text-decoration:none;background:var(--bg-secondary,#f5f5f5);border:1px solid var(--border-color,#e5e7eb);border-radius:4px;padding:6px 12px;font-size:14px;color:var(--text-primary,#111)">下载</a>' .
            '</div>' .
            '<div class="pdf-canvas-wrap">' .
            '<canvas id="pdfCanvas"></canvas>' .
            '</div>' .
            '<div id="pdfViewerContainer" data-src="' . htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') . '" style="display:none"></div>' .
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>' .
            '<script src="' . $jsDir . 'pdf-preview.js?v=' . $v . '"></script>' .
            '</body></html>';
        exit;
    } elseif (in_array($ext, ['md', 'markdown'])) {
        // Markdown：渲染 HTML 页面
        $mdContent = file_get_contents($item['path']);
        $pageTitle = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
        $mdJson = json_encode($mdContent, JSON_UNESCAPED_UNICODE);
        $baseUrl = getBaseUrl();
        $theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : '';
        $isDark = $theme === 'dark' || (empty($theme) && (
            isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) &&
            $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark'
        ));
        $themeAttr = $isDark ? ' data-theme="dark"' : '';
        $cssDir = '/assets/css/';
        $jsDir = '/assets/js/';
        $v = APP_VERSION;
        echo '<!DOCTYPE html><html lang="zh-CN"' . $themeAttr . '><head>' .
            '<meta charset="UTF-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1.0">' .
            '<title>' . $pageTitle . ' - 预览</title>' .
            '<link rel="icon" type="image/x-icon" href="/favicon.ico">' .
            '<link rel="stylesheet" href="' . $cssDir . 'variables.css?v=' . $v . '">' .
            '<link rel="stylesheet" href="' . $cssDir . 'reset.css?v=' . $v . '">' .
            '<style>' .
            'body{background:var(--bg-primary,#fff);color:var(--text-primary,#111);font-family:Noto Sans SC,sans-serif;padding:24px;max-width:860px;margin:0 auto;line-height:1.7}' .
            '#markdownBody h1,#markdownBody h2,#markdownBody h3,#markdownBody h4{margin:1.2em 0 .6em;font-weight:600}' .
            '#markdownBody h1{font-size:1.8em;border-bottom:1px solid var(--border-color,#e5e7eb);padding-bottom:.3em}' .
            '#markdownBody h2{font-size:1.5em;border-bottom:1px solid var(--border-color,#e5e7eb);padding-bottom:.3em}' .
            '#markdownBody h3{font-size:1.25em}' .
            '#markdownBody code{background:var(--bg-secondary,#f5f5f5);padding:2px 6px;border-radius:3px;font-size:.9em;font-family:JetBrains Mono,monospace}' .
            '#markdownBody pre{background:var(--bg-secondary,#f5f5f5);padding:16px;border-radius:6px;overflow-x:auto}' .
            '#markdownBody pre code{background:none;padding:0}' .
            '#markdownBody blockquote{border-left:4px solid var(--accent-blue,#3b82f6);margin:1em 0;padding:.5em 1em;color:var(--text-secondary,#666)}' .
            '#markdownBody table{border-collapse:collapse;width:100%;margin:1em 0}' .
            '#markdownBody th,#markdownBody td{border:1px solid var(--border-color,#e5e7eb);padding:8px 12px;text-align:left}' .
            '#markdownBody th{background:var(--bg-secondary,#f5f5f5)}' .
            '#markdownBody img{max-width:100%;border-radius:4px}' .
            '#markdownBody input[type="checkbox"]{margin-right:6px}' .
            '.md-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-secondary,#666);text-decoration:none;font-size:14px;margin-bottom:16px}' .
            '.md-back:hover{color:var(--accent-blue,#3b82f6)}' .
            '</style></head><body>' .
            '<a href="' . $baseUrl . '?s=' . htmlspecialchars($item['share_code']) . '" class="md-back">&larr; 返回分享页</a>' .
            '<div id="markdownBody"></div>' .
            '<script id="markdownSource" type="text/plain">' . htmlspecialchars($mdContent, ENT_NOQUOTES, 'UTF-8') . '</script>' .
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/9.1.6/marked.min.js"></script>' .
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js"></script>' .
            '<script src="' . $jsDir . 'markdown.js?v=' . $v . '"></script>' .
            '</body></html>';
        exit;
    }

    // 文本类文件：用 Prism.js 语法高亮预览
    $textExts = array('html', 'htm', 'txt', 'css', 'js', 'json', 'xml', 'log', 'csv',
                      'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'hpp',
                      'sh', 'bat', 'ps1', 'sql', 'yaml', 'yml', 'toml', 'ini',
                      'conf', 'cfg', 'env', 'gitignore', 'dockerfile',
                      'php', 'ts', 'tsx', 'jsx', 'vue', 'svelte');
    if (in_array($ext, $textExts)) {
        $textContent = file_get_contents($item['path']);
        // 限制预览大小（2MB）
        if (strlen($textContent) > 2 * 1024 * 1024) {
            $textContent = substr($textContent, 0, 2 * 1024 * 1024) . "\n\n... 文件过大，仅显示前 2MB ...";
        }
        $pageTitle = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
        $textJson = json_encode($textContent, JSON_UNESCAPED_UNICODE);
        $baseUrl = getBaseUrl();
        $theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : '';
        $isDark = $theme === 'dark' || (empty($theme) && (
            isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) &&
            $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark'
        ));
        $themeAttr = $isDark ? ' data-theme="dark"' : '';
        $cssDir = '/assets/css/';
        $v = APP_VERSION;

        // Prism 语言映射
        $langMap = array(
            'html' => 'markup', 'htm' => 'markup', 'xml' => 'markup', 'svg' => 'markup',
            'js' => 'javascript', 'ts' => 'typescript', 'tsx' => 'tsx', 'jsx' => 'jsx',
            'py' => 'python', 'rb' => 'ruby', 'go' => 'go', 'rs' => 'rust',
            'java' => 'java', 'c' => 'c', 'cpp' => 'cpp', 'h' => 'c', 'hpp' => 'cpp',
            'sh' => 'bash', 'bat' => 'batch', 'ps1' => 'powershell',
            'sql' => 'sql', 'json' => 'json', 'yaml' => 'yaml', 'yml' => 'yaml',
            'php' => 'php', 'css' => 'css', 'vue' => 'markup', 'svelte' => 'markup',
            'toml' => 'ini', 'ini' => 'ini', 'env' => 'bash',
            'conf' => 'bash', 'cfg' => 'bash', 'csv' => 'csv',
        );
        $prismLang = isset($langMap[$ext]) ? $langMap[$ext] : 'plaintext';

        echo '<!DOCTYPE html><html lang="zh-CN"' . $themeAttr . '><head>' .
            '<meta charset="UTF-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1.0">' .
            '<title>' . $pageTitle . ' - 预览</title>' .
            '<link rel="icon" type="image/x-icon" href="/favicon.ico">' .
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">' .
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">' .
            '<link rel="stylesheet" href="' . $cssDir . 'variables.css?v=' . $v . '">' .
            '<link rel="stylesheet" href="' . $cssDir . 'reset.css?v=' . $v . '">' .
            '<style>' .
            'body{background:var(--bg-primary,#fff);color:var(--text-primary,#111);font-family:Noto Sans SC,sans-serif;margin:0}' .
            '.preview-header{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:12px;padding:10px 16px;background:var(--card-bg,#fff);border-bottom:1px solid var(--border-color,#e5e7eb);box-shadow:0 2px 4px rgba(0,0,0,.05)}' .
            '.preview-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-secondary,#666);text-decoration:none;font-size:14px;margin-right:auto}' .
            '.preview-back:hover{color:var(--accent-blue,#3b82f6)}' .
            '.preview-download{text-decoration:none;background:var(--bg-secondary,#f5f5f5);border:1px solid var(--border-color,#e5e7eb);border-radius:4px;padding:6px 12px;font-size:14px;color:var(--text-primary,#111)}' .
            '.preview-body{max-width:100%;padding:0;overflow:auto}' .
            'pre[class*=language-]{margin:0;border-radius:0;font-size:13px}' .
            '</style></head><body>' .
            '<div class="preview-header">' .
            '<a href="' . $baseUrl . '?s=' . htmlspecialchars($item['share_code']) . '" class="preview-back">&larr; 返回</a>' .
            '<a href="' . $baseUrl . '?download=' . $item['id'] . '" class="preview-download">下载</a>' .
            '</div>' .
            '<div class="preview-body">' .
            '<pre class="line-numbers"><code id="previewCode" class="language-' . $prismLang . '"></code></pre>' .
            '</div>' .
            '<script>var previewContent = ' . $textJson . ';</script>' .
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>' .
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>' .
            '<script>' .
            '(function(){' .
            'var el=document.getElementById("previewCode");' .
            'el.textContent=previewContent;' .
            'Prism.highlightElement(el);' .
            '})();' .
            '</script>' .
            '</body></html>';
        exit;
    }

    // 其他类型不支持在线预览，重定向回分享页
    if (!empty($item['share_code'])) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?s=' . $item['share_code']);
    } else {
        header('Location: ' . $_SERVER['PHP_SELF']);
    }
    exit;
}

/**
 * 流式输出文件（支持 Range 请求，用于视频/音频）
 *
 * Range 处理：
 *   - 多 range（含逗号）或非法语法 → 忽略 Range，返回 200 全量
 *   - 越界（start >= size）→ 416 Requested Range Not Satisfiable
 *   - 开放右端（bytes=100-）→ end 默认 size-1
 *   - end 超过 size-1 → 夹紧到 size-1
 *   - fopen/fseek 失败保护
 *
 * @param string $path     文件路径
 * @param string $mimeType Content-Type
 * @return void
 */
function streamFile($path, $mimeType) {
    if (!is_file($path)) {
        http_response_code(404);
        echo '文件不存在';
        exit;
    }
    $size = filesize($path);
    if ($size <= 0) {
        http_response_code(500);
        echo '文件为空';
        exit;
    }

    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');

    $start = 0;
    $end = $size - 1;
    $isPartial = false;

    if (isset($_SERVER['HTTP_RANGE'])) {
        $rangeHeader = $_SERVER['HTTP_RANGE'];
        // 仅接受单 range：bytes=<start>-<end>(可选) 形式
        if (preg_match('#^bytes=(\d+)-(\d*)$#', $rangeHeader, $m)) {
            $reqStart = (int)$m[1];
            $reqEnd = ($m[2] !== '') ? (int)$m[2] : $size - 1;

            if ($reqStart >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            if ($reqEnd >= $size) {
                $reqEnd = $size - 1; // 夹紧
            }
            if ($reqStart > $reqEnd) {

            } else {
                $start = $reqStart;
                $end = $reqEnd;
                $isPartial = true;
            }
        }

    }

    if ($isPartial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    header('Content-Length: ' . ($end - $start + 1));
    header('Content-Disposition: inline');

    $fp = @fopen($path, 'rb');
    if (!$fp) {
        http_response_code(500);
        echo '无法打开文件';
        exit;
    }
    if ($start > 0) {
        fseek($fp, $start);
    }
    $remaining = $end - $start + 1;
    $bufferSize = 8192;
    while ($remaining > 0 && !feof($fp)) {
        $read = min($bufferSize, $remaining);
        $chunk = fread($fp, $read);
        if ($chunk === false) {
            break; // 读错误，停止输出已读部分
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
}

/**
 * 原始文件输出（供 PDF.js 等前端组件使用）
 *
 * 路由：?raw=N
 *
 * @return void
 */
function handleRawFile() {
    $id = intval($_GET['raw']);
    if ($id <= 0) {
        http_response_code(400);
        echo '无效参数';
        exit;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM items WHERE id = ? AND type = \'file\'');
    $stmt->execute(array($id));
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item || empty($item['path']) || !file_exists($item['path'])) {
        http_response_code(404);
        echo '文件不存在';
        exit;
    }

    // 密码保护检查
    if (!empty($item['password'])) {
        $code = $item['share_code'];
        $unlockedKey = 'unlocked_' . $code;
        if (empty($_SESSION[$unlockedKey])) {
            http_response_code(403);
            echo '需要密码';
            exit;
        }
    }

    $mimeType = $item['mime_type'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($item['path']));
    header('Cache-Control: private, max-age=3600');
    readfile($item['path']);
    exit;
}

/**
 * 处理压缩包在线预览请求
 *
 * 路由：?archive=list&item_id=N 或 ?archive=read&item_id=N&path=xxx
 *
 * @return void
 */
function handleArchiveRequest() {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['archive'];
    $itemId = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(array('error' => '缺少 item_id 参数'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM items WHERE id = ? AND type = \'file\'');
    $stmt->execute(array($itemId));
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(array('error' => '文件不存在'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 验证为压缩包类型
    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    $archiveExts = array('zip', 'tar', 'gz', 'tgz');
    if (!in_array($ext, $archiveExts)) {
        http_response_code(400);
        echo json_encode(array('error' => '非压缩包文件'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 密码保护检查
    if (!empty($item['password'])) {
        $code = $item['share_code'];
        $unlockedKey = 'unlocked_' . $code;
        if (empty($_SESSION[$unlockedKey])) {
            http_response_code(403);
            echo json_encode(array('error' => '需要先通过分享页输入密码'), JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'list') {
        $result = archiveList($item);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'read') {
        $innerPath = $_GET['path'] ?? '';
        if (empty($innerPath)) {
            http_response_code(400);
            echo json_encode(array('error' => '缺少 path 参数'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = archiveRead($item, $innerPath);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(array('error' => '未知操作: ' . $action), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * 搜索处理
 *
 * 路由：?action=search&q=&type=&category=&sort=&order=
 *
 * @return void
 */
function handleSearch() {
    header('Content-Type: application/json; charset=utf-8');

    $query = $_GET['q'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';
    $categoryFilter = $_GET['category'] ?? '';
    $sort = $_GET['sort'] ?? 'time';
    $sortOrder = $_GET['order'] ?? 'desc';

    $items = searchItems($query, $typeFilter, $categoryFilter, $sort, $sortOrder);

    // 不返回 content：密码保护走 ?s= 解锁后看；未设密码的最多给 150 字符 preview
    $result = [];
    foreach ($items as $item) {
        $isText = ($item['type'] === 'text');
        $hasPw  = !empty($item['password']);
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
            'has_password' => $hasPw,
            // 密码保护文本给遮蔽预览（前3字符+****），未设密码最多 150 字符 preview
            'content_preview' => $isText
                ? ($hasPw ? maskContent($item['content'] ?? '') : mb_substr($item['content'] ?? '', 0, 150))
                : null,
        ];
    }

    echo json_encode(['success' => true, 'items' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 鉴权缩略图输出
 *
 * 路由：?thumb=N。缩略图统一通过本路由读取并执行密码解锁检查（仅当对应
 * share_code 已解锁时才输出）。
 *
 * @return void
 */
function handleThumbnailServe() {
    $id = intval($_GET['thumb']);
    if ($id <= 0) {
        http_response_code(400);
        echo 'Invalid thumb id';
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, type, share_code, password, thumbnail_path FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item || $item['type'] !== 'file' || empty($item['thumbnail_path'])) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    // 密码保护检查
    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $item['share_code'];
        if (empty($_SESSION[$unlockedKey])) {
            http_response_code(403);
            echo '需要先通过分享页输入密码';
            exit;
        }
    }

    $thumbPath = UPLOAD_DIR . basename($item['thumbnail_path']);
    if (!is_file($thumbPath)) {
        http_response_code(404);
        echo 'Thumb file missing';
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($thumbPath));
    header('Cache-Control: private, max-age=300');
    readfile($thumbPath);
    exit;
}

// ============================================================
// 辅助函数
// ============================================================

/**
 * 获取站点基础 URL
 *
 * @return string 以 / 结尾的 base URL（如 https://host/）
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = $scriptName !== '' ? dirname($scriptName) : '';
    // Windows 平台 PHP CLI / 部分配置下 dirname 返回 '\'，需要规范化
    $path = str_replace('\\', '/', $path);
    $base = $protocol . '://' . $host . ($path === '/' || $path === '\\' ? '' : $path);
    // 确保末尾有斜杠，避免拼接 assets/css/... 时缺少分隔符
    if (substr($base, -1) !== '/') {
        $base .= '/';
    }
    return $base;
}
