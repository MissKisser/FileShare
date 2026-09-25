<?php
/**
 * 管理后台路由与业务逻辑。
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// ============================================================
// 管理员认证
// ============================================================
// ADMIN_SESSION_NAME 常量已在 src/config.php 定义

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
 * 管理员登录：校验密码并签发 session
 *
 * @param string $password 用户提交的明文密码
 * @return array [success=>bool, message=>string]
 */
function adminLogin($password) {
    // 按 IP 维度速率限制
    $rateLimitKey = 'admin_login_' . getRealIP();
    if (isRateLimitedByKey($rateLimitKey, 10, 60)) {
        return ['success' => false, 'message' => '尝试次数过多，请稍后再试'];
    }

    if (empty(ADMIN_PASSWORD)) {
        return ['success' => false, 'message' => '管理员密码未配置'];
    }

    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        recordRateLimitByKey($rateLimitKey);
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
 * 管理员登出：删除数据库 session 并清除 PHP session 标记
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
/**
 * 管理后台入口路由：分发 login / logout / dashboard / items / logs / settings / batch-thumbnails
 * 登录与登出页面免认证，其他页面需 isAdminLoggedIn() 校验
 */
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
                // 保存后 redirect 到 settings 页,模板从 session 读取消息
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

/**
 * 汇总 dashboard 页面所需的统计数据与最近活动
 *
 * @return array 含 stats / disk / today_uploads / today_downloads / recent_uploads / recent_downloads
 */
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


/**
 * 拉取后台 items 列表（支持搜索关键字 / 类型筛选 / 排序 / 分页）
 *
 * @return array 含 items / query / type_filter / pagination
 */
function getAdminItemsData() {
    $query = $_GET['q'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';
    $sort = $_GET['sort'] ?? 'time';
    $sortOrder = $_GET['order'] ?? 'desc';
    // 后台分页（默认 50/页）
    // 设计意图见 docs/design/DESIGN_INTENT.md §2.2
    $perPage = 50;
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = countSearchItems($query, $typeFilter, '');
    $items = searchItems($query, $typeFilter, '', $sort, $sortOrder, $perPage, $offset);

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    return [
        'items' => $items,
        'query' => $query,
        'type_filter' => $typeFilter,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
        ],
    ];
}

/**
 * 拉取后台 logs 页面（上传日志 + 下载日志，统一分页 50/页）
 *
 * @return array 含 upload_logs / download_logs / total_uploads / total_downloads / page / per_page
 */
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

/**
 * 拉取后台 settings 页面所需数据：按 category 分组并按固定类别顺序输出
 *
 * @return array 含 groups（有序分组列表）
 */
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
 *
 * @return array<string, array> key → [type, min, max, pattern?, hint]
 *  type: 'int' | 'bytes' | 'bool' | 'ip_list' | 'text' | 'string'
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
 *
 * @param string $input 待解析字符串（如 "2MB"）
 * @return int|false 字节数，无效输入返回 false
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
 * 按规则校验并规范化一个设置值
 *
 * @param string $key 设置 key（用于查找规则；未知 key 走 string 默认规则）
 * @param mixed $raw 原始输入
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

/**
 * 处理 settings 保存请求：CSRF 校验 → 逐项校验 → 写库；错误 / 成功消息写入 session 由模板读取
 */
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
    $_SESSION['admin_message_type'] = 'success';
}
