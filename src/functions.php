<?php
/**
 * 数据处理函数库
 * 作者：Hackerdallas
 * 
 * 重构为 SQLite 数据库操作，保持对外接口兼容
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

/**
 * 加载所有项目数据
 * 兼容旧接口，返回数组格式
 * 
 * @return array
 */
function loadData() {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM items ORDER BY time DESC');
    return $stmt->fetchAll();
}

/**
 * 保存数据（兼容接口，新代码应使用具体的插入/更新函数）
 * 
 * @param array $data
 */
function saveData($data) {
    // 此函数在 SQLite 模式下不再需要
    // 保留空实现以兼容可能的旧调用
}

/**
 * 清理过期项目
 * 
 * @param array &$data 兼容参数，SQLite 模式下不使用
 */
function cleanExpired(&$data = null) {
    $db = getDB();
    $now = time();

    // 获取即将过期的文件项目（需要删除物理文件）
    $stmt = $db->prepare('SELECT id, path, type, file_hash FROM items WHERE expire > 0 AND expire < ?');
    $stmt->execute([$now]);
    $expiredItems = $stmt->fetchAll();

    if (empty($expiredItems)) {
        return;
    }

    $db->beginTransaction();

    foreach ($expiredItems as $item) {
        // 删除物理文件（仅在无其他项目引用同一文件时）
        if ($item['type'] === 'file' && !empty($item['path']) && file_exists($item['path'])) {
            if (!empty($item['file_hash'])) {
                // 检查是否有其他项目引用同一文件
                $refStmt = $db->prepare('SELECT COUNT(*) as cnt FROM items WHERE path = ? AND id != ?');
                $refStmt->execute([$item['path'], $item['id']]);
                $refCount = $refStmt->fetch()['cnt'];
                if ($refCount == 0) {
                    @unlink($item['path']);
                }
            } else {
                @unlink($item['path']);
            }
        }
    }

    // 批量删除过期记录
    $delStmt = $db->prepare('DELETE FROM items WHERE expire > 0 AND expire < ?');
    $delStmt->execute([$now]);

    $db->commit();
}

/**
 * 根据分享码获取项目
 * 
 * @param string $code 分享码
 * @return array|null
 */
function getItemByCode($code) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM items WHERE share_code = ?');
    $stmt->execute([$code]);
    $item = $stmt->fetch();
    return $item ?: null;
}

/**
 * 根据 ID 获取项目
 * 
 * @param int $id
 * @return array|null
 */
function getItemById($id) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    return $item ?: null;
}

/**
 * 根据 ID 删除项目
 * 
 * @param int $id
 * @return bool
 */
function deleteItemById($id) {
    $db = getDB();
    $item = getItemById($id);
    if (!$item) {
        return false;
    }

    // 删除物理文件
    if ($item['type'] === 'file' && !empty($item['path']) && file_exists($item['path'])) {
        if (!empty($item['file_hash'])) {
            // 检查引用计数
            $refStmt = $db->prepare('SELECT COUNT(*) as cnt FROM items WHERE path = ? AND id != ?');
            $refStmt->execute([$item['path'], $id]);
            $refCount = $refStmt->fetch()['cnt'];
            if ($refCount == 0) {
                @unlink($item['path']);
            }
        } else {
            @unlink($item['path']);
        }
    }

    $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
    return $stmt->execute([$id]);
}

/**
 * 批量删除项目
 * 
 * @param array $ids ID 数组
 * @return array ['deleted' => int, 'errors' => array]
 */
function batchDeleteItems($ids) {
    $db = getDB();
    $deleted = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($ids as $id) {
        $id = intval($id);
        $item = getItemById($id);
        if (!$item) {
            $errors[] = "ID {$id} 不存在";
            continue;
        }

        // 删除物理文件
        if ($item['type'] === 'file' && !empty($item['path']) && file_exists($item['path'])) {
            if (!empty($item['file_hash'])) {
                $refStmt = $db->prepare('SELECT COUNT(*) as cnt FROM items WHERE path = ? AND id != ?');
                $refStmt->execute([$item['path'], $id]);
                $refCount = $refStmt->fetch()['cnt'];
                if ($refCount == 0) {
                    @unlink($item['path']);
                }
            } else {
                @unlink($item['path']);
            }
        }

        $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
        if ($stmt->execute([$id])) {
            $deleted++;
        } else {
            $errors[] = "ID {$id} 删除失败";
        }
    }
    $db->commit();

    return ['deleted' => $deleted, 'errors' => $errors];
}

/**
 * 搜索项目
 * 
 * @param string $query 搜索关键词
 * @param string $typeFilter 类型过滤 (all/file/text)
 * @param string $categoryFilter 分类过滤 (image/video/audio/doc/code/archive)
 * @param string $sort 排序字段 (time/size/name/expire)
 * @param string $sortOrder 排序方向 (desc/asc)
 * @return array
 */
function searchItems($query = '', $typeFilter = 'all', $categoryFilter = '', $sort = 'time', $sortOrder = 'desc') {
    $db = getDB();
    $params = [];
    $where = ['1=1'];

    // 搜索关键词
    if (!empty($query)) {
        $where[] = '(name LIKE ? OR content LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }

    // 类型过滤
    if ($typeFilter === 'file') {
        $where[] = "type = 'file'";
    } elseif ($typeFilter === 'text') {
        $where[] = "type = 'text'";
    }

    // 分类过滤（基于文件扩展名）
    if (!empty($categoryFilter)) {
        $extensions = getCategoryExtensions($categoryFilter);
        if (!empty($extensions)) {
            $placeholders = implode(',', array_fill(0, count($extensions), '?'));
            $where[] = "type = 'file' AND (";
            $nameConditions = [];
            foreach ($extensions as $ext) {
                $nameConditions[] = "name LIKE ?";
                $params[] = '%.' . $ext;
            }
            $where[count($where) - 1] = "type = 'file' AND (" . implode(' OR ', $nameConditions) . ")";
        }
    }

    $whereClause = implode(' AND ', $where);

    // 排序
    $allowedSorts = ['time', 'size', 'name', 'expire', 'download_count'];
    $sort = in_array($sort, $allowedSorts) ? $sort : 'time';
    $sortOrder = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';

    $sql = "SELECT * FROM items WHERE {$whereClause} ORDER BY {$sort} {$sortOrder}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 获取分类对应的扩展名列表
 * 
 * @param string $category
 * @return array
 */
function getCategoryExtensions($category) {
    $map = [
        'image'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg'],
        'video'   => ['mp4', 'webm', 'ogv', 'ogg', 'avi', 'mov', 'mkv'],
        'audio'   => ['mp3', 'wav', 'aac', 'flac', 'm4a', 'opus'],
        'doc'     => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'md', 'markdown'],
        'code'    => ['js', 'ts', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift', 'kt', 'scala', 'rb', 'sh', 'bash', 'ps1', 'bat', 'cmd', 'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'sql', 'yaml', 'yml', 'ini', 'conf', 'log'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'lz4', 'apk'],
    ];
    return $map[$category] ?? [];
}

/**
 * 增加下载计数
 * 
 * @param int $id 项目 ID
 * @param string $ip 下载者 IP
 * @param string $userAgent 下载者 UA
 */
function incrementDownloadCount($id, $ip = '', $userAgent = '') {
    $db = getDB();

    // 更新计数
    $stmt = $db->prepare('UPDATE items SET download_count = download_count + 1 WHERE id = ?');
    $stmt->execute([$id]);

    // 记录下载日志
    $logStmt = $db->prepare('
        INSERT INTO download_logs (item_id, ip, user_agent, download_time)
        VALUES (?, ?, ?, ?)
    ');
    $logStmt->execute([$id, $ip, $userAgent, time()]);

    // 清理旧日志（保留最近 10000 条）
    $db->exec('
        DELETE FROM download_logs WHERE id NOT IN (
            SELECT id FROM download_logs ORDER BY download_time DESC LIMIT 10000
        )
    ');
}

/**
 * 记录上传日志到数据库
 * 
 * @param int $itemId 项目 ID
 * @param string $filename 文件名
 * @param int $filesize 文件大小
 * @param int $duration 保留时长
 */
function logUploadToDb($itemId, $filename, $filesize, $duration) {
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO upload_logs (item_id, ip, filename, filesize, upload_time, duration, expire_time, user_agent, action)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $itemId,
        getRealIP(),
        $filename,
        $filesize,
        time(),
        $duration,
        $duration === 0 ? 0 : (time() + $duration),
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'upload'
    ]);

    // 清理旧日志（保留最近 500 条）
    $db->exec('
        DELETE FROM upload_logs WHERE id NOT IN (
            SELECT id FROM upload_logs ORDER BY upload_time DESC LIMIT 500
        )
    ');
}

/**
 * 获取上传日志
 * 
 * @param int $limit 数量限制
 * @return array
 */
function getUploadLogs($limit = 50) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM upload_logs ORDER BY upload_time DESC LIMIT ?');
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * 格式化文件大小
 * 
 * @param int $bytes
 * @return string
 */
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * 格式化过期时间
 * 
 * @param int $expire
 * @return string
 */
function formatExpire($expire) {
    if ($expire === 0) return '永久';
    $diff = $expire - time();
    if ($diff < 0) return '已过期';
    if ($diff < 3600) return floor($diff / 60) . '分钟';
    if ($diff < 86400) return floor($diff / 3600) . '小时';
    return floor($diff / 86400) . '天';
}

/**
 * 格式化时长
 * 
 * @param int $seconds
 * @return string
 */
function formatDuration($seconds) {
    if ($seconds === 0) return '永久';
    if ($seconds < 3600) return ($seconds / 60) . '分钟';
    if ($seconds < 86400) return ($seconds / 3600) . '小时';
    return ($seconds / 86400) . '天';
}

/**
 * 掩码 IP 地址
 * 
 * @param string $ip
 * @return string
 */
function maskIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return $parts[0] . '.' . $parts[1] . '.***.***';
    }
    return $ip;
}

/**
 * 获取存储统计信息
 * 
 * @return array
 */
function getStorageStats() {
    $db = getDB();

    $stats = [
        'total_items' => 0,
        'file_count' => 0,
        'text_count' => 0,
        'total_size' => 0,
        'disk_usage' => 0,
        'category_sizes' => [],
        'daily_uploads' => [],
    ];

    // 基本计数
    $stats['total_items'] = $db->query('SELECT COUNT(*) as cnt FROM items')->fetch()['cnt'];
    $stats['file_count'] = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'file'")->fetch()['cnt'];
    $stats['text_count'] = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'text'")->fetch()['cnt'];

    // 文件总大小
    $sizeResult = $db->query("SELECT COALESCE(SUM(size), 0) as total FROM items WHERE type = 'file'")->fetch();
    $stats['total_size'] = $sizeResult['total'];

    // 磁盘实际占用（扫描 uploads 目录）
    $stats['disk_usage'] = getDirectorySize(UPLOAD_DIR);

    // 按类型分类大小
    $categories = ['image', 'video', 'audio', 'doc', 'code', 'archive'];
    foreach ($categories as $cat) {
        $extensions = getCategoryExtensions($cat);
        $size = 0;
        if (!empty($extensions)) {
            $placeholders = implode(',', array_fill(0, count($extensions), '?'));
            $conditions = [];
            foreach ($extensions as $ext) {
                $conditions[] = "name LIKE ?";
            }
            $likeConditions = implode(' OR ', $conditions);
            $stmt = $db->prepare("SELECT COALESCE(SUM(size), 0) as total FROM items WHERE type = 'file' AND ({$likeConditions})");
            $params = [];
            foreach ($extensions as $ext) {
                $params[] = '%.' . $ext;
            }
            $stmt->execute($params);
            $size = $stmt->fetch()['total'];
        }
        $stats['category_sizes'][$cat] = $size;
    }

    // 最近 7 天每日上传量
    $sevenDaysAgo = time() - (7 * 86400);
    $stmt = $db->prepare('
        SELECT date(time, "unixepoch") as day, COUNT(*) as cnt
        FROM items
        WHERE time >= ?
        GROUP BY day
        ORDER BY day ASC
    ');
    $stmt->execute([$sevenDaysAgo]);
    $stats['daily_uploads'] = $stmt->fetchAll();

    return $stats;
}

/**
 * 计算目录大小
 * 
 * @param string $dir
 * @return int 字节数
 */
function getDirectorySize($dir) {
    $size = 0;
    if (!is_dir($dir)) return 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

/**
 * 获取系统设置
 * 
 * @param string $key 设置键名
 * @param mixed $default 默认值
 * @return mixed
 */
function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

/**
 * 设置系统配置
 * 
 * @param string $key
 * @param string $value
 */
function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
    $stmt->execute([$key, $value, time()]);
}

/**
 * 检查 IP 是否在黑名单中
 * 
 * @param string $ip
 * @return bool
 */
function isIPBlacklisted($ip) {
    $blacklist = getSetting('ip_blacklist', '');
    if (empty($blacklist)) return false;

    $blocked = array_map('trim', explode(',', $blacklist));
    foreach ($blocked as $blockedIP) {
        if (empty($blockedIP)) continue;
        if (ipInCidr($ip, $blockedIP)) {
            return true;
        }
    }
    return false;
}
