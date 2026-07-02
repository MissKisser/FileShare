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
        return; // 已初始化
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
    $stmt = $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
    $defaults = [
        ['site_title', '文件上传与文本存储系统', $now],
        ['max_file_size_normal', (string)(200 * 1024 * 1024), $now],
        ['max_file_size_large', (string)(2048 * 1024 * 1024), $now],
        ['default_duration', '600', $now],
        ['admin_session_lifetime', '7200', $now],
        ['api_enabled', '1', $now],
        ['ip_blacklist', '', $now],
    ];
    foreach ($defaults as $row) {
        $stmt->execute($row);
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
