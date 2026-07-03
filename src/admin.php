<?php
/**
 * 管理后台路由和逻辑
 * 作者：Hackerdallas
 * 
 * 认证方式：环境变量密码 + Session
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// ============================================================
// 管理员认证
// ============================================================

define('ADMIN_SESSION_NAME', 'fileshare_admin');

/**
 * 检查管理员是否已登录
 * 
 * @return bool
 */
function isAdminLoggedIn() {
    if (empty($_SESSION[ADMIN_SESSION_NAME])) {
        return false;
    }

    $db = getDB();
    $sessionId = $_SESSION[ADMIN_SESSION_NAME];
    $stmt = $db->prepare('SELECT * FROM admin_sessions WHERE session_id = ? AND expires_at > ?');
    $stmt->execute([$sessionId, time()]);
    return $stmt->fetch() !== false;
}

/**
 * 管理员登录
 */
function adminLogin($password) {
    if (empty(ADMIN_PASSWORD)) {
        return ['success' => false, 'message' => '管理员密码未配置'];
    }

    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        return ['success' => false, 'message' => '密码错误'];
    }

    $db = getDB();
    $sessionId = bin2hex(random_bytes(32));
    $now = time();
    $lifetime = intval(getSetting('admin_session_lifetime', '7200'));
    $expiresAt = $now + $lifetime;

    $stmt = $db->prepare('
        INSERT INTO admin_sessions (session_id, ip, created_at, expires_at)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$sessionId, getRealIP(), $now, $expiresAt]);

    $_SESSION[ADMIN_SESSION_NAME] = $sessionId;

    return ['success' => true];
}

/**
 * 管理员登出
 */
function adminLogout() {
    if (!empty($_SESSION[ADMIN_SESSION_NAME])) {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM admin_sessions WHERE session_id = ?');
        $stmt->execute([$_SESSION[ADMIN_SESSION_NAME]]);
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }
}

// ============================================================
// 管理后台路由
// ============================================================

function handleAdminRequest() {
    $page = $_GET['admin'] ?? 'login';

    // 登录页面和登录请求不需要认证
    if ($page === 'login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $result = adminLogin($password);
            if ($result['success']) {
                header('Location: /admin/dashboard');
                exit;
            }
            $adminError = $result['message'];
        }
        define('ADMIN_PAGE', true);
        require_once __DIR__ . '/../templates/admin/login.php';
        exit;
    }

    // 登出
    if ($page === 'logout') {
        adminLogout();
        header('Location: /admin/login');
        exit;
    }

    // 其他页面需要认证
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login');
        exit;
    }

    // 清理过期管理员会话
    $db = getDB();
    $db->prepare('DELETE FROM admin_sessions WHERE expires_at < ?')->execute([time()]);

    // ===== 批量缩略图 AJAX =====
    if ($page === 'batch-thumbnails') {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(array('error' => '仅支持 POST'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once __DIR__ . '/thumbnail.php';
        $stmt = $db->query('SELECT id FROM items WHERE type = \'file\' AND (thumbnail_path IS NULL OR thumbnail_path = \'\') ORDER BY id ASC');
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $results = array('total' => count($ids), 'success' => 0, 'failed' => 0, 'details' => array());
        foreach ($ids as $id) {
            $thumbVal = generateItemThumbnail($id);
            if ($thumbVal && strpos($thumbVal, 'failed:') !== 0) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            $results['details'][] = array('id' => $id, 'thumbnail_path' => $thumbVal);
        }
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
        exit;
    }

    switch ($page) {
        case 'dashboard':
            $adminPage = 'dashboard';
            $adminData = getAdminDashboardData();
            break;

        case 'items':
            $adminPage = 'items';
            $adminData = getAdminItemsData();
            break;

        case 'logs':
            $adminPage = 'logs';
            $adminData = getAdminLogsData();
            break;

        case 'settings':
            $adminPage = 'settings';
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                handleAdminSettingsSave();
                // 判断是否 AJAX 请求（双保险：X-Requested-With + Accept）
                $isAjax = (
                    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                );
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    if (!empty($_SESSION['admin_error'])) {
                        $msg = $_SESSION['admin_error'];
                        unset($_SESSION['admin_error']);
                        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
                    } else {
                        $msg = $_SESSION['admin_message'] ?? '已保存';
                        unset($_SESSION['admin_message']);
                        echo json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                    }
                    exit;
                }
                header('Location: /admin/settings');
                exit;
            }
            $adminData = getAdminSettingsData();
            break;

        default:
            $adminPage = 'dashboard';
            $adminData = getAdminDashboardData();
    }

    define('ADMIN_PAGE', true);
    require_once __DIR__ . '/../templates/admin/layout.php';
    exit;
}

// ============================================================
// 管理后台数据获取
// ============================================================

function getAdminDashboardData() {
    $db = getDB();
    $stats = getStorageStats();

    // 今日上传数
    $todayStart = strtotime('today');
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM items WHERE time >= ?');
    $stmt->execute([$todayStart]);
    $todayUploads = $stmt->fetch()['cnt'];

    // 今日下载数
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM download_logs WHERE download_time >= ?');
    $stmt->execute([$todayStart]);
    $todayDownloads = $stmt->fetch()['cnt'];

    // 最近活动（上传+下载，合并）
    $recentUploads = $db->query('SELECT * FROM upload_logs ORDER BY upload_time DESC LIMIT 5')->fetchAll();
    $recentDownloads = $db->query('SELECT * FROM download_logs ORDER BY download_time DESC LIMIT 5')->fetchAll();

    return [
        'stats' => $stats,
        'disk' => getDiskStats(),
        'today_uploads' => $todayUploads,
        'today_downloads' => $todayDownloads,
        'recent_uploads' => $recentUploads,
        'recent_downloads' => $recentDownloads,
    ];
}

function getAdminItemsData() {
    $query = $_GET['q'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';
    $sort = $_GET['sort'] ?? 'time';
    $sortOrder = $_GET['order'] ?? 'desc';

    $items = searchItems($query, $typeFilter, '', $sort, $sortOrder);

    return [
        'items' => $items,
        'query' => $query,
        'type_filter' => $typeFilter,
    ];
}

function getAdminLogsData() {
    $db = getDB();
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    // 上传日志
    $totalUploads = $db->query('SELECT COUNT(*) as cnt FROM upload_logs')->fetch()['cnt'];
    $stmt = $db->prepare('SELECT * FROM upload_logs ORDER BY upload_time DESC LIMIT ? OFFSET ?');
    $stmt->execute([$perPage, $offset]);
    $uploadLogs = $stmt->fetchAll();

    // 下载日志
    $totalDownloads = $db->query('SELECT COUNT(*) as cnt FROM download_logs')->fetch()['cnt'];
    $stmt = $db->prepare('SELECT * FROM download_logs ORDER BY download_time DESC LIMIT ? OFFSET ?');
    $stmt->execute([$perPage, $offset]);
    $downloadLogs = $stmt->fetchAll();

    return [
        'upload_logs' => $uploadLogs,
        'download_logs' => $downloadLogs,
        'total_uploads' => $totalUploads,
        'total_downloads' => $totalDownloads,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function getAdminSettingsData() {
    $db = getDB();
    $rows = $db->query('SELECT key, value, label, description, category, control_type FROM settings ORDER BY category, sort_order, key')->fetchAll();

    // 类别中文标签
    $categoryLabels = [
        'site'     => '站点信息',
        'upload'   => '上传限制',
        'security' => '安全与会话',
        'api'      => 'API',
    ];

    // 按 category 分组
    $grouped = [];
    foreach ($rows as $row) {
        $cat = $row['category'] ?: 'other';
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = $row;
    }

    // 把分组转为有序数组（保证站点→上传→安全→API 顺序，其他类别追加在末尾）
    $orderedGroups = [];
    foreach (['site', 'upload', 'security', 'api'] as $cat) {
        if (!empty($grouped[$cat])) {
            $orderedGroups[] = [
                'key' => $cat,
                'label' => $categoryLabels[$cat] ?? $cat,
                'items' => $grouped[$cat],
            ];
            unset($grouped[$cat]);
        }
    }
    foreach ($grouped as $cat => $items) {
        $orderedGroups[] = [
            'key' => $cat,
            'label' => $categoryLabels[$cat] ?? $cat,
            'items' => $items,
        ];
    }

    return [
        'groups' => $orderedGroups,
    ];
}

/**
 * 设置项校验规则
 * 返回数组：每项 [type, min, max, pattern, hint]
 *  - type: 'int' | 'bytes' | 'bool' | 'ip_list' | 'text' | 'string'
 *  - min/max: 数值范围
 *  - pattern: 正则（可选）
 *  - hint: 错误提示前缀
 */
function getSettingValidationRules() {
    return [
        'site_title'             => ['type' => 'string', 'min' => 1, 'max' => 200, 'hint' => '网站标题'],
        'site_subtitle'          => ['type' => 'string', 'max' => 500, 'hint' => '网站副标题'],
        'default_duration'       => ['type' => 'int',    'min' => 0, 'max' => 31536000, 'hint' => '默认有效期'],
        'max_file_size_normal'   => ['type' => 'bytes',  'min' => 1024, 'max' => 1073741824, 'hint' => '普通上传大小'],
        'max_file_size_large'    => ['type' => 'bytes',  'min' => 1048576, 'max' => 10737418240, 'hint' => '分块上传大小'],
        'admin_session_lifetime' => ['type' => 'int',    'min' => 60, 'max' => 2592000, 'hint' => '后台会话有效期'],
        'api_enabled'            => ['type' => 'bool',   'hint' => '启用 API'],
        'ip_blacklist'           => ['type' => 'ip_list', 'hint' => 'IP 黑名单'],
    ];
}

/**
 * 解析人类友好的字节数（支持 100KB / 2MB / 1GB）
 * @param string $input
 * @return int|false 字节数，无效返回 false
 */
function parseSizeInput($input) {
    $input = trim($input);
    if ($input === '') return false;
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?B)?$/i', $input, $m)) {
        $num = (float)$m[1];
        $unit = strtoupper($m[2] ?? 'B');
        $mult = 1;
        if ($unit === 'KB') $mult = 1024;
        elseif ($unit === 'MB') $mult = 1024 * 1024;
        elseif ($unit === 'GB') $mult = 1024 * 1024 * 1024;
        elseif ($unit === 'TB') $mult = 1024 * 1024 * 1024 * 1024;
        return (int)($num * $mult);
    }
    // 兼容纯数字（视为字节）
    if (ctype_digit($input)) {
        return (int)$input;
    }
    return false;
}

/**
 * 校验并规范化一个设置值
 * @return array [ok=>bool, value=>string, error=>string]
 */
function validateSettingValue($key, $raw) {
    $rules = getSettingValidationRules();
    $rule = isset($rules[$key]) ? $rules[$key] : ['type' => 'string', 'max' => 1000];
    $type = $rule['type'];

    if ($type === 'bool') {
        $v = ($raw === '1' || $raw === 'on' || $raw === 'true') ? '1' : '0';
        return ['ok' => true, 'value' => $v];
    }

    if ($type === 'int') {
        $val = filter_var($raw, FILTER_VALIDATE_INT);
        if ($val === false) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 必须是整数'];
        }
        if (isset($rule['min']) && $val < $rule['min']) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 不能小于 ' . $rule['min']];
        }
        if (isset($rule['max']) && $val > $rule['max']) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 不能大于 ' . $rule['max']];
        }
        return ['ok' => true, 'value' => (string)$val];
    }

    if ($type === 'bytes') {
        $bytes = parseSizeInput((string)$raw);
        if ($bytes === false) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 格式无效，例如 200MB / 2GB'];
        }
        if (isset($rule['min']) && $bytes < $rule['min']) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 不能小于 ' . $rule['min'] . ' 字节'];
        }
        if (isset($rule['max']) && $bytes > $rule['max']) {
            return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 不能大于 ' . $rule['max'] . ' 字节'];
        }
        return ['ok' => true, 'value' => (string)$bytes];
    }

    if ($type === 'ip_list') {
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // 允许 IP 或 CIDR
            if (filter_var($line, FILTER_VALIDATE_IP)) { $clean[] = $line; continue; }
            if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $line)) { $clean[] = $line; continue; }
            return ['ok' => false, 'error' => 'IP 黑名单包含无效项: ' . $line];
        }
        return ['ok' => true, 'value' => implode("\n", $clean)];
    }

    // string
    $val = (string)$raw;
    if (isset($rule['min']) && mb_strlen($val) < $rule['min']) {
        return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 长度不足'];
    }
    if (isset($rule['max']) && mb_strlen($val) > $rule['max']) {
        return ['ok' => false, 'error' => ($rule['hint'] ?? $key) . ' 不能超过 ' . $rule['max'] . ' 字符'];
    }
    return ['ok' => true, 'value' => $val];
}

function handleAdminSettingsSave() {
    if (!validateCSRF()) {
        return;
    }

    $settings = $_POST['settings'] ?? [];
    $errors = [];
    $saved = 0;

    foreach ($settings as $key => $value) {
        $result = validateSettingValue($key, $value);
        if (!$result['ok']) {
            $errors[] = $result['error'];
            continue;
        }
        setSetting($key, $result['value']);
        $saved++;
    }

    if (!empty($errors)) {
        $_SESSION['admin_error'] = implode('；', $errors);
        return;
    }

    $_SESSION['admin_message'] = '已保存 ' . $saved . ' 项设置';
}
