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
    $settings = $db->query('SELECT * FROM settings ORDER BY key')->fetchAll();

    return [
        'settings' => $settings,
    ];
}

function handleAdminSettingsSave() {
    if (!validateCSRF()) {
        return;
    }

    $settings = $_POST['settings'] ?? [];
    foreach ($settings as $key => $value) {
        setSetting($key, $value);
    }

    $_SESSION['message'] = '设置已保存';
}
