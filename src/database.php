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
            -- M9: 原 created_at DATETIME DEFAULT CURRENT_TIMESTAMP 列从未被读取，已移除。
            -- 老库通过增量迁移保留该列（SQLite 不支持 ALTER DROP COLUMN），新库不再声明。
            -- 业务时间字段统一用 time (INTEGER Unix 时间戳)。
            -- owner 凭证：存 sha256(owner_token) 而不是明文。owner_token 是创建者收到的
            -- 管理链接 ?s=<code>&manage=<token> 里的明文 token，DB 泄露不丢凭证。
            -- 老数据此列为 NULL，admin 删除依然工作，owner 端只能删了重建。
            owner_token_hash TEXT
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

    // 插入默认设置（仅基础 3 列 — 元数据 label/description/category/control_type/sort_order
    // 由 runIncrementalMigrations() 通过 ALTER TABLE ADD COLUMN + UPDATE 统一回填，
    // 避免"INSERT 引用了尚不存在的列"导致全新部署启动崩溃。）
    $now = time();
    $defaults = [
        ['site_title', '文件上传与文本存储系统'],
        ['site_subtitle', '上传文件或文本，生成分享链接'],
        ['default_duration', '600'],
        ['max_file_size_normal', (string)(200 * 1024 * 1024)],
        ['max_file_size_large', (string)(2048 * 1024 * 1024)],
        ['ip_blacklist', ''],
        ['admin_session_lifetime', '7200'],
        ['api_enabled', '1'],
    ];
    $stmt = $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
    foreach ($defaults as $row) {
        $stmt->execute([$row[0], $row[1], $now]);
    }

    // 运行增量迁移（首次建表后补齐新字段 + 元数据回填）
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
    if (!in_array('owner_token_hash', $itemCols)) {
        $db->exec("ALTER TABLE items ADD COLUMN owner_token_hash TEXT");
        // 部分唯一索引（SQLite 支持 WHERE 子句）：允许历史 NULL 行共存，
        // 同时让 sha256(token) 查询走索引。新创建的行必填。
        $db->exec("CREATE UNIQUE INDEX idx_owner_token_hash ON items(owner_token_hash) WHERE owner_token_hash IS NOT NULL");
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
        'site_title'             => ['网站标题',          '显示在浏览器标签和页面标题',           'site',   'text',     10, '文件上传与文本存储系统'],
        'site_subtitle'          => ['网站副标题',        '显示在主页大标题下方',                 'site',   'text',     20, '上传文件或文本，生成分享链接'],
        'default_duration'       => ['默认有效期（秒）',   '上传项的默认过期时间，0 表示永不过期',  'upload', 'number',   30, '600'],
        'max_file_size_normal'   => ['普通上传大小上限',   '字节数，可使用 1MB/200MB/2GB 等单位',  'upload', 'text',     40, (string)(200 * 1024 * 1024)],
        'max_file_size_large'    => ['分块上传大小上限',   '超出此大小将走分块上传流程',            'upload', 'text',     50, (string)(2048 * 1024 * 1024)],
        'ip_blacklist'           => ['IP 黑名单',         '每行一个 IP 或 CIDR，留空表示不限制',  'security', 'textarea', 60, ''],
        'admin_session_lifetime' => ['后台会话有效期（秒）', '管理员登录态保持时间',                'security', 'number', 70, '7200'],
        'api_enabled'            => ['启用 API',          '关闭后所有 /api/* 请求返回 403',        'api',    'switch',   80, '1'],
    ];

    // 1) 回填已存在行的元数据
    $stmt = $db->prepare('UPDATE settings SET label = ?, description = ?, category = ?, control_type = ?, sort_order = ? WHERE key = ? AND (label IS NULL OR label = "")');
    foreach ($meta as $key => $info) {
        $stmt->execute([$info[0], $info[1], $info[2], $info[3], $info[4], $key]);
    }

    // 2) 插入新行（如 site_subtitle 在老数据库中不存在）
    $ins = $db->prepare('INSERT OR IGNORE INTO settings (key, value, updated_at, label, description, category, control_type, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $now = time();
    foreach ($meta as $key => $info) {
        $ins->execute([$key, $info[5], $now, $info[0], $info[1], $info[2], $info[3], $info[4]]);
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
 * 生成 owner 管理 token（明文）
 *
 * 与 generateShareCode 的区别：
 *   - share_code 是公共分享凭证（URL 里出现），8 字符 32 位熵勉强够用；
 *   - owner_token 是删除凭证，**必须**不可猜；64 字符 256 位熵。
 *   - 返回的是**明文 token**（用于放进创建响应里的 manage_url），
 *     DB 实际存的是 sha256(token)。模型与 api_tokens.token_hash 完全一致。
 *
 * @param PDO $db
 * @return string 64 字符十六进制明文 token
 * @throws RuntimeException 撞库超过 5 次（极不可能）
 */
function generateOwnerToken($db) {
    $maxAttempts = 5;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $token = bin2hex(random_bytes(32)); // 64 字符 hex = 256 位熵
        $hash = hash('sha256', $token);
        $stmt = $db->prepare('SELECT id FROM items WHERE owner_token_hash = ?');
        $stmt->execute([$hash]);
        if ($stmt->fetch() === false) {
            return $token;
        }
    }
    // 256 位熵撞库概率 2^-256，5 次连续碰撞视为异常
    throw new RuntimeException('Failed to generate unique owner_token after 5 attempts');
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

            // 备份旧文件（M12：rename 跨设备失败回退到 copy + unlink）
            if (file_exists($dataFile) && !file_exists($dataFile . '.bak')) {
                if (!@rename($dataFile, $dataFile . '.bak')) {
                    // rename 失败（常见于跨挂载点），降级到 copy + unlink
                    if (@copy($dataFile, $dataFile . '.bak')) {
                        @unlink($dataFile);
                    } else {
                        throw new RuntimeException("无法备份 data.json 到 data.json.bak（权限或磁盘空间）");
                    }
                }
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

            // 备份旧文件（M12：rename 跨设备失败回退到 copy + unlink）
            if (file_exists($logFile) && !file_exists($logFile . '.bak')) {
                if (!@rename($logFile, $logFile . '.bak')) {
                    if (@copy($logFile, $logFile . '.bak')) {
                        @unlink($logFile);
                    } else {
                        throw new RuntimeException("无法备份 upload_log.json 到 .bak（权限或磁盘空间）");
                    }
                }
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
