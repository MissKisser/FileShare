<?php
/**
 * SQLite 数据库管理
 * 作者：Hackerdallas
 * 
 * 提供 SQLite 连接单例、建表初始化、JSON 数据迁移
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// 数据库文件路径
define('DB_FILE', STORAGE_DIR . 'fileshare.db');

/**
 * 获取 SQLite 数据库连接（单例模式）
 * 
 * @return PDO
 */
function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // 启用 WAL 模式，提升并发读写性能
        $db->exec('PRAGMA journal_mode=WAL');
        // 启用外键约束
        $db->exec('PRAGMA foreign_keys=ON');
        // 初始化表结构
        initDB($db);
    }
    return $db;
}

/**
 * 初始化数据库表结构
 * 
 * @param PDO $db
 */
function initDB($db) {
    // 检查是否已初始化（通过 items 表是否存在判断）
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='items'")->fetchAll();
    if (!empty($tables)) {
        // 已初始化，仍需运行增量迁移
        runIncrementalMigrations($db);
        return;
    }

    $db->exec("
        -- 项目主表（文件 + 文本）
        CREATE TABLE items (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            share_code      TEXT    NOT NULL UNIQUE,
            type            TEXT    NOT NULL CHECK(type IN ('file','text')),
            name            TEXT,
            content         TEXT,
            path            TEXT,
            size            INTEGER DEFAULT 0,
            file_hash       TEXT,
            mime_type       TEXT,
            password        TEXT,
            download_count  INTEGER DEFAULT 0,
            ip              TEXT    NOT NULL,
            user_agent      TEXT,
            time            INTEGER NOT NULL,
            expire          INTEGER NOT NULL DEFAULT 0,
            duration        INTEGER NOT NULL DEFAULT 600,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE UNIQUE INDEX idx_share_code ON items(share_code);
        CREATE INDEX idx_file_hash ON items(file_hash);
        CREATE INDEX idx_type ON items(type);
        CREATE INDEX idx_expire ON items(expire);

        -- 上传日志表
        CREATE TABLE upload_logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id     INTEGER,
            ip          TEXT    NOT NULL,
            filename    TEXT,
            filesize    INTEGER DEFAULT 0,
            upload_time INTEGER NOT NULL,
            duration    INTEGER DEFAULT 0,
            expire_time INTEGER DEFAULT 0,
            user_agent  TEXT,
            action      TEXT    DEFAULT 'upload',
            FOREIGN KEY (item_id) REFERENCES items(id)
        );
        CREATE INDEX idx_logs_time ON upload_logs(upload_time);

        -- 下载日志表
        CREATE TABLE download_logs (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id         INTEGER NOT NULL,
            ip              TEXT    NOT NULL,
            user_agent      TEXT,
            download_time   INTEGER NOT NULL,
            FOREIGN KEY (item_id) REFERENCES items(id)
        );
        CREATE INDEX idx_dl_item ON download_logs(item_id);

        -- API Token 表
        CREATE TABLE api_tokens (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash  TEXT    NOT NULL UNIQUE,
            name        TEXT    NOT NULL,
            permissions TEXT    DEFAULT 'read',
            last_used   INTEGER,
            expires_at  INTEGER DEFAULT 0,
            created_at  INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX idx_token_hash ON api_tokens(token_hash);

        -- 管理员会话表
        CREATE TABLE admin_sessions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id  TEXT    NOT NULL UNIQUE,
            ip          TEXT,
            created_at  INTEGER NOT NULL,
            expires_at  INTEGER NOT NULL
        );
        CREATE INDEX idx_admin_session ON admin_sessions(session_id);

        -- 系统设置表
        CREATE TABLE settings (
            key         TEXT    PRIMARY KEY,
            value       TEXT    NOT NULL,
            updated_at  INTEGER NOT NULL
        );
    ");

    // 插入默认设置
    $now = time();
    $stmt = $db->prepare('INSERT INTO settings (key, value, updated_at, label, description, category, control_type, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $defaults = [
        ['site_title', '文件上传与文本存储系统', $now, '网站标题', '显示在浏览器标签和页面标题', 'site', 'text', 10],
        ['site_subtitle', '上传文件或文本，生成分享链接', $now, '网站副标题', '显示在主页大标题下方', 'site', 'text', 20],
        ['default_duration', '600', $now, '默认有效期（秒）', '上传项的默认过期时间，0 表示永不过期', 'upload', 'number', 30],
        ['max_file_size_normal', (string)(200 * 1024 * 1024), $now, '普通上传大小上限', '字节数，可使用 1MB/200MB/2GB 等单位', 'upload', 'text', 40],
        ['max_file_size_large', (string)(2048 * 1024 * 1024), $now, '分块上传大小上限', '超出此大小将走分块上传流程', 'upload', 'text', 50],
        ['ip_blacklist', '', $now, 'IP 黑名单', '每行一个 IP 或 CIDR，留空表示不限制', 'security', 'textarea', 60],
        ['admin_session_lifetime', '7200', $now, '后台会话有效期（秒）', '管理员登录态保持时间', 'security', 'number', 70],
        ['api_enabled', '1', $now, '启用 API', '关闭后所有 /api/* 请求返回 403', 'api', 'switch', 80],
    ];
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }

    // 运行增量迁移（首次建表后也需补齐新字段）
    runIncrementalMigrations($db);
}

/**
 * 增量迁移：幂等添加新字段
 */
function runIncrementalMigrations($db) {
    $logCols = array_column(
        $db->query("PRAGMA table_info(upload_logs)")->fetchAll(),
        'name'
    );
    if (!in_array('session_id', $logCols)) {
        $db->exec("ALTER TABLE upload_logs ADD COLUMN session_id TEXT");
    }
    if (!in_array('chunk_count', $logCols)) {
        $db->exec("ALTER TABLE upload_logs ADD COLUMN chunk_count INTEGER");
    }
    if (!in_array('received_chunks', $logCols)) {
        $db->exec("ALTER TABLE upload_logs ADD COLUMN received_chunks TEXT");
    }
    if (!in_array('status', $logCols)) {
        $db->exec("ALTER TABLE upload_logs ADD COLUMN status TEXT DEFAULT 'uploading'");
    }

    $itemCols = array_column(
        $db->query("PRAGMA table_info(items)")->fetchAll(),
        'name'
    );
    if (!in_array('thumbnail_path', $itemCols)) {
        $db->exec("ALTER TABLE items ADD COLUMN thumbnail_path TEXT");
    }

    // settings 表元数据列（用于后台中文化 / 分组 / 控件类型）
    $settingsCols = array_column(
        $db->query("PRAGMA table_info(settings)")->fetchAll(),
        'name'
    );
    if (!in_array('label', $settingsCols)) {
        $db->exec("ALTER TABLE settings ADD COLUMN label TEXT");
    }
    if (!in_array('description', $settingsCols)) {
        $db->exec("ALTER TABLE settings ADD COLUMN description TEXT");
    }
    if (!in_array('category', $settingsCols)) {
        $db->exec("ALTER TABLE settings ADD COLUMN category TEXT");
    }
    if (!in_array('control_type', $settingsCols)) {
        $db->exec("ALTER TABLE settings ADD COLUMN control_type TEXT");
    }
    if (!in_array('sort_order', $settingsCols)) {
        $db->exec("ALTER TABLE settings ADD COLUMN sort_order INTEGER DEFAULT 0");
    }

    // 一次性回填已存在行的元数据（重复执行无副作用：仅当 label 为空时写入）
    $meta = [
        'site_title'             => ['网站标题',          '显示在浏览器标签和页面标题',           'site',   'text',     10],
        'site_subtitle'          => ['网站副标题',        '显示在主页大标题下方',                 'site',   'text',     20],
        'default_duration'       => ['默认有效期（秒）',   '上传项的默认过期时间，0 表示永不过期',  'upload', 'number',   30],
        'max_file_size_normal'   => ['普通上传大小上限',   '字节数，可使用 1MB/200MB/2GB 等单位',  'upload', 'text',     40],
        'max_file_size_large'    => ['分块上传大小上限',   '超出此大小将走分块上传流程',            'upload', 'text',     50],
        'ip_blacklist'           => ['IP 黑名单',         '每行一个 IP 或 CIDR，留空表示不限制',  'security', 'textarea', 60],
        'admin_session_lifetime' => ['后台会话有效期（秒）', '管理员登录态保持时间',                'security', 'number', 70],
        'api_enabled'            => ['启用 API',          '关闭后所有 /api/* 请求返回 403',        'api',    'switch',   80],
    ];
    $stmt = $db->prepare('UPDATE settings SET label = ?, description = ?, category = ?, control_type = ?, sort_order = ? WHERE key = ? AND (label IS NULL OR label = "")');
    foreach ($meta as $key => $info) {
        $stmt->execute([$info[0], $info[1], $info[2], $info[3], $info[4], $key]);
    }
}

/**
 * 生成唯一的分享码
 * 
 * @param PDO $db
 * @return string 8位十六进制字符串
 */
function generateShareCode($db) {
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = bin2hex(random_bytes(4)); // 8 字符
        $stmt = $db->prepare('SELECT id FROM items WHERE share_code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetch() === false) {
            return $code;
        }
    }
    // 极端情况下使用更长的码
    return bin2hex(random_bytes(6));
}

/**
 * 从 JSON 文件迁移数据到 SQLite
 * 
 * @return array 返回迁移统计 ['items' => int, 'logs' => int]
 */
function migrateJsonToSqlite() {
    $db = getDB();
    $stats = ['items' => 0, 'logs' => 0];

    // 迁移 data.json
    $dataFile = STORAGE_DIR . 'data.json';
    if (file_exists($dataFile)) {
        $content = file_get_contents($dataFile);
        $data = json_decode($content, true) ?: [];

        if (!empty($data)) {
            $stmt = $db->prepare('
                INSERT INTO items (share_code, type, name, content, path, size, file_hash, mime_type, password, download_count, ip, user_agent, time, expire, duration)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($data as $item) {
                $shareCode = generateShareCode($db);
                $type = $item['type'] ?? 'file';
                $name = $item['name'] ?? null;
                $contentVal = $item['content'] ?? null;
                $path = $item['path'] ?? null;
                $size = $item['size'] ?? 0;
                $fileHash = null; // 旧数据无哈希
                $mimeType = null;
                $password = null;
                $downloadCount = 0;
                $ip = $item['ip'] ?? 'unknown';
                $userAgent = null;
                $time = $item['time'] ?? time();
                $expire = $item['expire'] ?? 0;
                $duration = ($expire > 0 && $time > 0) ? ($expire - $time) : 0;

                $stmt->execute([
                    $shareCode, $type, $name, $contentVal, $path, $size,
                    $fileHash, $mimeType, $password, $downloadCount,
                    $ip, $userAgent, $time, $expire, $duration
                ]);
                $stats['items']++;
            }

            // 备份旧文件
            if (file_exists($dataFile) && !file_exists($dataFile . '.bak')) {
                rename($dataFile, $dataFile . '.bak');
            }
        }
    }

    // 迁移 upload_log.json
    $logFile = STORAGE_DIR . 'upload_log.json';
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logs = json_decode($content, true) ?: [];

        if (!empty($logs)) {
            $stmt = $db->prepare('
                INSERT INTO upload_logs (item_id, ip, filename, filesize, upload_time, duration, expire_time, user_agent, action)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($logs as $log) {
                $stmt->execute([
                    null, // item_id 旧日志无法关联
                    $log['ip'] ?? 'unknown',
                    $log['filename'] ?? '',
                    $log['filesize'] ?? 0,
                    $log['upload_time'] ?? time(),
                    $log['duration'] ?? 0,
                    $log['expire_time'] ?? 0,
                    $log['user_agent'] ?? '',
                    'upload'
                ]);
                $stats['logs']++;
            }

            // 备份旧文件
            if (file_exists($logFile) && !file_exists($logFile . '.bak')) {
                rename($logFile, $logFile . '.bak');
            }
        }
    }

    return $stats;
}

/**
 * 检查是否需要从 JSON 迁移
 * 
 * @return bool
 */
function needsMigration() {
    $db = getDB();
    $count = $db->query('SELECT COUNT(*) as cnt FROM items')->fetch()['cnt'];
    // SQLite 表已存在但为空，且 JSON 备份文件存在
    if ($count == 0 && file_exists(STORAGE_DIR . 'data.json.bak')) {
        return false; // 已迁移过
    }
    if ($count == 0 && file_exists(STORAGE_DIR . 'data.json')) {
        // 检查 JSON 是否有数据
        $content = file_get_contents(STORAGE_DIR . 'data.json');
        $data = json_decode($content, true) ?: [];
        return !empty($data);
    }
    return false;
}
