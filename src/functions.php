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

    // 先删除关联的日志记录（外键约束），再删除 items
    $expiredIds = array_column($expiredItems, 'id');
    $idPlaceholders = implode(',', array_fill(0, count($expiredIds), '?'));
    $db->prepare("DELETE FROM download_logs WHERE item_id IN ($idPlaceholders)")->execute($expiredIds);
    $db->prepare("DELETE FROM upload_logs WHERE item_id IN ($idPlaceholders)")->execute($expiredIds);

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
 * 根据 ID 删除项目（单条快捷入口）
 *
 * @param int $id
 * @return bool 是否成功删除
 */
function deleteItemById($id) {
    $result = deleteItemsAtomically([(int)$id]);
    return $result['deleted_count'] === 1;
}

/**
 * 原子删除一个或多个 item — P1 重构核心
 *
 * 修复的问题：
 *  - P1.1 事务边界错误（旧实现 beginTransaction 在 DELETE 之外，unlink 失败不回滚）
 *  - P1.2 关联日志残留（旧实现只 DELETE items，download_logs / upload_logs 留孤儿行）
 *  - P1.3 物理文件引用计数 race（旧实现"先查再删"两步走、无锁）
 *  - P1.5 ID enumeration（旧实现 errors 数组泄露哪些 ID 不存在）
 *
 * 行为契约：
 *  - 全部成功 → 返回 ['deleted' => [id => share_code], 'errors' => [], 'deleted_count' => N]
 *  - 任意 unlink 失败 → 整体 rollback，返回 errors（[id => reason]）
 *  - 输入 ID 中有不存在的 → 静默忽略（不计入 errors，也不暴露哪些 ID 存在）
 *
 * @param int[] $ids 待删除的 item id 列表
 * @return array{deleted: array<int,string>, errors: array<int,string>, deleted_count: int}
 */
function deleteItemsAtomically(array $ids) {
    $db = getDB();

    // 规范化输入：去重、强制 int、过滤无效（PHP 7.3 兼容写法，不用 fn() 箭头函数）
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($i) { return $i > 0; });
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return ['deleted' => [], 'errors' => [], 'deleted_count' => 0];
    }

    $deleted = [];   // id => share_code（成功删除的）
    $errors = [];    // id => reason（失败的 — 通常是 unlink 失败）

    $db->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, share_code, type, path, file_hash FROM items WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 全部 ID 都不存在 → 静默返回（不计入 errors，避免 enumeration）
        if (empty($items)) {
            $db->commit();
            return ['deleted' => [], 'errors' => [], 'deleted_count' => 0];
        }

        $foundIds = array_column($items, 'id');
        $ph2 = implode(',', array_fill(0, count($foundIds), '?'));

        // 1. 先清关联日志（避免 FK 约束冲突 + 清理孤儿行）
        // 注：upload_logs.item_id nullable（兼容历史无 FK 的迁移行），download_logs.item_id NOT NULL
        $db->prepare("DELETE FROM download_logs WHERE item_id IN ($ph2)")->execute($foundIds);
        $db->prepare("DELETE FROM upload_logs WHERE item_id IN ($ph2)")->execute($foundIds);

        // 2. 物理文件：按引用计数删（事务内，失败回滚）
        foreach ($items as $item) {
            if ($item['type'] !== 'file') continue;
            if (empty($item['path']) || !file_exists($item['path'])) continue;

            // 有 file_hash 时才做引用计数（同一文件多 share 场景）
            if (!empty($item['file_hash'])) {
                $refStmt = $db->prepare('SELECT COUNT(*) FROM items WHERE path = ? AND id != ?');
                $refStmt->execute([$item['path'], $item['id']]);
                if ((int)$refStmt->fetchColumn() > 0) {
                    continue; // 还有别的 share 引用同一个文件，跳过 unlink
                }
            }

            if (!@unlink($item['path'])) {
                // 抛异常 → 外层 catch → rollback，DB 状态完全恢复
                throw new RuntimeException("unlink failed: {$item['path']}");
            }
        }

        // 3. 真正删 items
        $delStmt = $db->prepare("DELETE FROM items WHERE id IN ($ph2)");
        $delStmt->execute($foundIds);

        foreach ($items as $item) {
            $deleted[(int)$item['id']] = $item['share_code'];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // 整批回滚 → 失败的 ID 列表就是用户传入的全部 ID
        // 但更准确的做法：把失败的 item 标出来（unlink 失败的），其余的算被回滚
        foreach ($ids as $id) {
            $errors[$id] = $e->getMessage();
        }
        error_log('deleteItemsAtomically failed: ' . $e->getMessage());
    }

    return ['deleted' => $deleted, 'errors' => $errors, 'deleted_count' => count($deleted)];
}

/**
 * 批量删除项目（兼容旧调用方 — 现在转发到 deleteItemsAtomically）
 *
 * @param int[] $ids ID 数组
 * @return array{deleted: array<int,string>, errors: array<int,string>, deleted_count: int}
 */
function batchDeleteItems($ids) {
    return deleteItemsAtomically($ids);
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
    // 使用两步清理：先查 id，再 DELETE — 避免 SQLite 同表子查询限制
    try {
        $idsToKeep = $db->query('
            SELECT id FROM upload_logs ORDER BY upload_time DESC LIMIT 500
        ')->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($idsToKeep)) {
            $placeholders = implode(',', array_fill(0, count($idsToKeep), '?'));
            $db->prepare("DELETE FROM upload_logs WHERE id NOT IN ($placeholders)")
               ->execute($idsToKeep);
        }
    } catch (Exception $e) {
        // 清理失败不影响主流程
        error_log('logUploadToDb cleanup failed: ' . $e->getMessage());
    }
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
 * 递归计算目录占用字节数
 *
 * @param string $dir 目录绝对路径
 * @return int 字节数（失败返回 0）
 */
function getDirectorySize($dir) {
    $size = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
        return 0;
    }
    return $size;
}

/**
 * 获取系统磁盘空间信息
 *
 * @param string $path 用于查询的目录路径
 * @return array{total:int, free:int, used:int, used_pct:float} 字节 / 百分比
 */
function getDiskSpace($path = null) {
    if ($path === null) {
        $path = UPLOAD_DIR;
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) {
        return ['total' => 0, 'free' => 0, 'used' => 0, 'used_pct' => 0.0];
    }
    $total = (int) $total;
    $free = (int) $free;
    $used = $total - $free;
    $usedPct = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;
    return [
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'used_pct' => $usedPct,
    ];
}

/**
 * 获取磁盘统计信息（仅管理端使用）
 *
 * @return array
 */
function getDiskStats() {
    $dirSize = getDirectorySize(UPLOAD_DIR);
    $disk = getDiskSpace(UPLOAD_DIR);
    $dirPct = $disk['total'] > 0 ? round(($dirSize / $disk['total']) * 100, 2) : 0.0;
    return [
        'upload_dir_size' => $dirSize,
        'upload_dir_size_formatted' => formatSize($dirSize),
        'upload_dir_pct' => $dirPct,
        'disk_total' => $disk['total'],
        'disk_total_formatted' => formatSize($disk['total']),
        'disk_free' => $disk['free'],
        'disk_free_formatted' => formatSize($disk['free']),
        'disk_used' => $disk['used'],
        'disk_used_formatted' => formatSize($disk['used']),
        'disk_used_pct' => $disk['used_pct'],
    ];
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

/**
 * 记录管理员操作（审计日志）
 *
 * 复用 upload_logs 表，action 字段填 'admin_<verb>' 区分（如 'admin_delete'）。
 * 读端在 templates/admin/layout.php 的 logs 页面筛 action LIKE 'admin_%' 即可。
 *
 * 注意：item_id 列对 items(id) 有 FK 约束。对"删除"操作来说，调用时 item 已被删，
 * 直接写 item_id 会触发 FK 约束失败。所以这里把 item_id 设为 NULL，把可追溯
 * 的 share_code / itemType 拼到 filename 列里（形如 'admin_delete:abc123 [file]'）。
 *
 * @param string $verb        动作名，如 'delete' / 'batch_delete'
 * @param int|null $itemId    受影响 item 的 id（已删除场景传 NULL 避免 FK 冲突）
 * @param string|null $shareCode 受影响 item 的 share_code（写入 filename 列方便人读）
 * @param string|null $itemType  受影响 item 的 type（写入 filename 列方便人读）
 * @return void
 */
function logAdminAction($verb, $itemId = null, $shareCode = null, $itemType = null) {
    try {
        $db = getDB();
        $parts = ['admin_' . $verb];
        if ($shareCode) $parts[] = $shareCode;
        if ($itemType) $parts[] = '[' . $itemType . ']';
        $filename = implode(':', $parts);
        // item_id 永远为 NULL（避开 FK 约束）；share_code / type 信息在 filename 里
        $db->prepare(
            'INSERT INTO upload_logs (ip, filename, filesize, upload_time, action, item_id, session_id)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        )->execute([
            getRealIP(),
            $filename,
            0,
            time(),
            'admin_' . $verb,
            session_id(),
        ]);
    } catch (Throwable $e) {
        // 审计日志写不进不应阻断主流程，但要让运维能看到
        error_log('logAdminAction failed: ' . $e->getMessage());
    }
}
