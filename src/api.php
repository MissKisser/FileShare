<?php
/**
 * RESTful API 路由和认证逻辑
 * 作者：Hackerdallas
 * 
 * 动态 Token + 刷新机制
 * 所有 API 响应返回 JSON
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// ============================================================
// API 响应辅助函数
// ============================================================

function apiResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function apiError($message, $statusCode = 400) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Token 认证
// ============================================================

/**
 * 生成 API Token
 * 
 * @param PDO $db
 * @param string $name Token 名称
 * @param string $permissions 权限（read,write,admin）
 * @param int $expiresIn 有效期秒数（0=永不过期）
 * @return array ['access_token' => string, 'expires_at' => int]
 */
function generateApiToken($db, $name, $permissions = 'read,write', $expiresIn = 3600) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = time();
    $expiresAt = $expiresIn > 0 ? ($now + $expiresIn) : 0;

    $stmt = $db->prepare('
        INSERT INTO api_tokens (token_hash, name, permissions, last_used, expires_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$tokenHash, $name, $permissions, $now, $expiresAt, $now]);

    return [
        'access_token' => $token,
        'expires_at' => $expiresAt,
    ];
}

/**
 * 生成刷新 Token
 */
function generateRefreshToken($db, $name) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = time();
    $expiresAt = $now + (7 * 86400); // 7 天

    $stmt = $db->prepare('
        INSERT INTO api_tokens (token_hash, name, permissions, last_used, expires_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$tokenHash, $name . '_refresh', 'refresh', $now, $expiresAt, $now]);

    return [
        'refresh_token' => $token,
        'expires_at' => $expiresAt,
    ];
}

/**
 * 验证 API Token
 * 
 * @param string $requiredPermission 需要的权限 (read/write/admin)
 * @return array Token 信息或终止请求
 */
function validateApiToken($requiredPermission = 'read') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';

    // 从 Authorization Header 提取 Token
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    // 也支持查询参数
    if (empty($token)) {
        $token = $_GET['token'] ?? '';
    }

    if (empty($token)) {
        apiError('缺少认证 Token', 401);
    }

    $tokenHash = hash('sha256', $token);
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM api_tokens WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
    $tokenInfo = $stmt->fetch();

    if (!$tokenInfo) {
        apiError('无效的 Token', 401);
    }

    // 检查过期
    if ($tokenInfo['expires_at'] > 0 && $tokenInfo['expires_at'] < time()) {
        apiError('Token 已过期', 401);
    }

    // 检查权限
    $permissions = explode(',', $tokenInfo['permissions']);
    if (!in_array($requiredPermission, $permissions) && !in_array('admin', $permissions)) {
        apiError('权限不足', 403);
    }

    // 更新最后使用时间
    $updateStmt = $db->prepare('UPDATE api_tokens SET last_used = ? WHERE id = ?');
    $updateStmt->execute([time(), $tokenInfo['id']]);

    return $tokenInfo;
}

// ============================================================
// API 路由分发
// ============================================================

function handleApiRequest() {
    if (!API_ENABLED) {
        apiError('API 已禁用', 403);
    }

    $endpoint = $_GET['api'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // 不需要认证的端点
    if ($endpoint === 'auth/token' && $method === 'POST') {
        handleApiAuthToken();
        return;
    }
    if ($endpoint === 'auth/refresh' && $method === 'POST') {
        handleApiAuthRefresh();
        return;
    }

    // 需要认证的端点
    switch ($endpoint) {
        case 'items':
            if ($method === 'GET') {
                validateApiToken('read');
                handleApiListItems();
            } elseif ($method === 'DELETE') {
                validateApiToken('write');
                handleApiDeleteItem();
            } else {
                apiError('不支持的请求方法', 405);
            }
            break;

        case 'item':
            if ($method === 'GET') {
                validateApiToken('read');
                handleApiGetItem();
            } elseif ($method === 'DELETE') {
                validateApiToken('write');
                handleApiDeleteItem();
            } else {
                apiError('不支持的请求方法', 405);
            }
            break;

        case 'upload':
            if ($method === 'POST') {
                validateApiToken('write');
                handleApiUpload();
            } else {
                apiError('不支持的请求方法', 405);
            }
            break;

        case 'text':
            if ($method === 'POST') {
                validateApiToken('write');
                handleApiTextSave();
            } else {
                apiError('不支持的请求方法', 405);
            }
            break;

        case 'stats':
            if ($method === 'GET') {
                validateApiToken('read');
                handleApiStats();
            } else {
                apiError('不支持的请求方法', 405);
            }
            break;

        default:
            apiError('未知的 API 端点', 404);
    }
}

// ============================================================
// 认证端点
// ============================================================

function handleApiAuthToken() {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';

    if (empty($password)) {
        apiError('请提供密码', 400);
    }

    if (empty(ADMIN_PASSWORD)) {
        apiError('管理员密码未配置', 500);
    }

    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        apiError('密码错误', 401);
    }

    $db = getDB();
    $accessToken = generateApiToken($db, 'api_access', 'read,write,admin', 3600);
    $refreshToken = generateRefreshToken($db, 'api_access');

    apiResponse([
        'success' => true,
        'access_token' => $accessToken['access_token'],
        'access_token_expires_at' => $accessToken['expires_at'],
        'refresh_token' => $refreshToken['refresh_token'],
        'refresh_token_expires_at' => $refreshToken['expires_at'],
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]);
}

function handleApiAuthRefresh() {
    $input = json_decode(file_get_contents('php://input'), true);
    $refreshToken = $input['refresh_token'] ?? '';

    if (empty($refreshToken)) {
        apiError('请提供 refresh_token', 400);
    }

    $tokenHash = hash('sha256', $refreshToken);
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND permissions = ?');
    $stmt->execute([$tokenHash, 'refresh']);
    $tokenInfo = $stmt->fetch();

    if (!$tokenInfo) {
        apiError('无效的 refresh_token', 401);
    }

    if ($tokenInfo['expires_at'] > 0 && $tokenInfo['expires_at'] < time()) {
        apiError('refresh_token 已过期，请重新登录', 401);
    }

    // 删除旧的 access token（同名的）
    $name = str_replace('_refresh', '', $tokenInfo['name']);
    $db->prepare('DELETE FROM api_tokens WHERE name = ? AND permissions != ?')
       ->execute([$name, 'refresh']);

    // 生成新的 access token
    $newAccessToken = generateApiToken($db, $name, 'read,write,admin', 3600);

    apiResponse([
        'success' => true,
        'access_token' => $newAccessToken['access_token'],
        'access_token_expires_at' => $newAccessToken['expires_at'],
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]);
}

// ============================================================
// 项目列表
// ============================================================

function handleApiListItems() {
    $query = $_GET['q'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';
    $categoryFilter = $_GET['category'] ?? '';
    $sort = $_GET['sort'] ?? 'time';
    $sortOrder = $_GET['order'] ?? 'desc';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(1, intval($_GET['per_page'] ?? 20)));

    $items = searchItems($query, $typeFilter, $categoryFilter, $sort, $sortOrder);

    // 分页
    $total = count($items);
    $offset = ($page - 1) * $perPage;
    $items = array_slice($items, $offset, $perPage);

    $result = [];
    foreach ($items as $item) {
        $result[] = formatItemForApi($item);
    }

    apiResponse([
        'success' => true,
        'items' => $result,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
        ],
    ]);
}

// ============================================================
// 获取单个项目
// ============================================================

function handleApiGetItem() {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        apiError('请提供有效的项目 ID', 400);
    }

    $item = getItemById($id);
    if (!$item) {
        apiError('项目不存在', 404);
    }

    apiResponse([
        'success' => true,
        'item' => formatItemForApi($item),
    ]);
}

// ============================================================
// 删除项目
// ============================================================

function handleApiDeleteItem() {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        // 也支持 body 中的 ID
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
    }

    if ($id <= 0) {
        apiError('请提供有效的项目 ID', 400);
    }

    $item = getItemById($id);
    if (!$item) {
        apiError('项目不存在', 404);
    }

    if (deleteItemById($id)) {
        apiResponse(['success' => true, 'message' => '删除成功']);
    } else {
        apiError('删除失败', 500);
    }
}

// ============================================================
// API 文件上传
// ============================================================

function handleApiUpload() {
    if (!isset($_FILES['files'])) {
        apiError('请提供文件', 400);
    }

    $db = getDB();
    $duration = intval($_POST['duration'] ?? 600);
    $accessPassword = $_POST['access_password'] ?? '';
    $files = $_FILES['files'];
    $uploadCount = 0;
    $errors = [];
    $uploadedItems = [];

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

            $validation = validateFileType($originalName, $files['tmp_name'][$i]);
            if (!$validation['valid']) {
                $errors[] = $validation['error'];
                continue;
            }

            $fileHash = hash_file('sha256', $files['tmp_name'][$i]);

            $dupStmt = $db->prepare('SELECT id, path FROM items WHERE file_hash = ? AND type = \'file\' LIMIT 1');
            $dupStmt->execute([$fileHash]);
            $duplicate = $dupStmt->fetch();

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
            $safeName = substr($safeName, 0, 200);

            if ($duplicate && !empty($duplicate['path']) && file_exists($duplicate['path'])) {
                $filepath = $duplicate['path'];
            } else {
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

            $mimeType = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $filepath);
                finfo_close($finfo);
            }

            $expire = $duration === 0 ? 0 : time() + $duration;
            $shareCode = generateShareCode($db);
            $passwordHash = !empty($accessPassword) ? password_hash($accessPassword, PASSWORD_BCRYPT) : null;

            $stmt = $db->prepare('
                INSERT INTO items (share_code, type, name, path, size, file_hash, mime_type, password, download_count, ip, user_agent, time, expire, duration)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $shareCode, 'file', $originalName, $filepath, $files['size'][$i],
                $fileHash, $mimeType, $passwordHash, 0,
                getRealIP(), $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                time(), $expire, $duration
            ]);

            $itemId = $db->lastInsertId();
            logUploadToDb($itemId, $originalName, $files['size'][$i], $duration);

            $uploadCount++;
            $uploadedItems[] = formatItemForApi(getItemById($itemId));
        } else {
            $errors[] = "文件 {$files['name'][$i]} 上传错误（代码：{$files['error'][$i]}）";
        }
    }

    apiResponse([
        'success' => $uploadCount > 0,
        'message' => $uploadCount > 0 ? "成功上传 {$uploadCount} 个文件" : '上传失败',
        'uploaded' => $uploadCount,
        'items' => $uploadedItems,
        'errors' => $errors,
    ], $uploadCount > 0 ? 200 : 400);
}

// ============================================================
// API 文本保存
// ============================================================

function handleApiTextSave() {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';
    $duration = intval($input['duration'] ?? 600);
    $accessPassword = $input['access_password'] ?? '';

    if (empty(trim($text))) {
        apiError('文本内容不能为空', 400);
    }

    $db = getDB();
    $expire = $duration === 0 ? 0 : time() + $duration;
    $shareCode = generateShareCode($db);
    $passwordHash = !empty($accessPassword) ? password_hash($accessPassword, PASSWORD_BCRYPT) : null;

    $stmt = $db->prepare('
        INSERT INTO items (share_code, type, content, size, password, download_count, ip, user_agent, time, expire, duration)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $shareCode, 'text', $text, strlen($text),
        $passwordHash, 0,
        getRealIP(), $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        time(), $expire, $duration
    ]);

    $itemId = $db->lastInsertId();
    logUploadToDb($itemId, '文本内容 (' . mb_substr($text, 0, 20) . '...)', strlen($text), $duration);

    apiResponse([
        'success' => true,
        'message' => '文本保存成功',
        'item' => formatItemForApi(getItemById($itemId)),
    ], 201);
}

// ============================================================
// API 统计
// ============================================================

function handleApiStats() {
    $stats = getStorageStats();

    apiResponse([
        'success' => true,
        'stats' => [
            'total_items' => $stats['total_items'],
            'file_count' => $stats['file_count'],
            'text_count' => $stats['text_count'],
            'total_size' => $stats['total_size'],
            'total_size_formatted' => formatSize($stats['total_size']),
            'disk_usage' => $stats['disk_usage'],
            'disk_usage_formatted' => formatSize($stats['disk_usage']),
            'category_sizes' => $stats['category_sizes'],
            'daily_uploads' => $stats['daily_uploads'],
        ],
    ]);
}

// ============================================================
// 格式化项目输出
// ============================================================

function formatItemForApi($item) {
    if (!$item) return null;

    return [
        'id' => $item['id'],
        'share_code' => $item['share_code'],
        'share_url' => getBaseUrl() . '?s=' . $item['share_code'],
        'type' => $item['type'],
        'name' => $item['name'] ?? null,
        'size' => intval($item['size'] ?? 0),
        'size_formatted' => formatSize($item['size'] ?? 0),
        'mime_type' => $item['mime_type'] ?? null,
        'has_password' => !empty($item['password']),
        'download_count' => intval($item['download_count'] ?? 0),
        'ip' => maskIP($item['ip'] ?? ''),
        'time' => intval($item['time'] ?? 0),
        'time_formatted' => date('Y-m-d H:i:s', $item['time'] ?? 0),
        'expire' => intval($item['expire'] ?? 0),
        'expire_formatted' => formatExpire($item['expire'] ?? 0),
        'content_preview' => $item['type'] === 'text' ? mb_substr($item['content'] ?? '', 0, 200) : null,
    ];
}
