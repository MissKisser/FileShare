<?php
/**
 * 分片上传管理
 * 作者：FileShare Contributors
 *
 * 3 个端点：
 *   POST ?api=upload/init   - 创建/恢复会话
 *   POST ?api=upload/chunk  - 接收单个分片
 *   POST ?api=upload/merge  - 合并所有分片
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

define('CHUNK_DIR', UPLOAD_DIR . '_chunks/');
define('CHUNK_SESSION_TTL', 86400); // 24 小时

// 确保分片目录存在
if (!is_dir(CHUNK_DIR)) {
    @mkdir(CHUNK_DIR, 0755, true);
}

/**
 * 初始化/恢复上传会话
 */
function handleChunkInit() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiError('请求体格式错误', 400);
    }

    $db = getDB();
    $sessionId = preg_replace('/[^a-f0-9]/i', '', $input['session_id'] ?? '');
    $filename = $input['filename'] ?? '';
    $fileSize = intval($input['size'] ?? 0);
    $mime = $input['mime'] ?? '';
    $chunkSize = intval($input['chunk_size'] ?? (5 * 1024 * 1024));
    $totalChunks = intval($input['total_chunks'] ?? 0);
    $duration = intval($input['duration'] ?? 600);
    $accessPassword = $input['access_password'] ?? '';
    $largeFilePassword = $input['large_file_password'] ?? '';
    $resume = !empty($input['resume']);

    if (empty($filename) || $fileSize <= 0 || $totalChunks <= 0) {
        apiError('参数不完整', 400);
    }

    // 大文件密码校验
    if ($fileSize > MAX_FILE_SIZE_NORMAL) {
        if (!verifyLargeFilePassword($largeFilePassword)) {
            apiError('大文件密码错误或缺失', 403);
        }
        if ($fileSize > MAX_FILE_SIZE_LARGE) {
            apiError('文件超过授权上传上限', 413);
        }
    }

    // 复用旧会话
    if ($resume && !empty($sessionId) && strlen($sessionId) === 32) {
        $stmt = $db->prepare('SELECT chunk_count, received_chunks FROM upload_logs WHERE session_id = ? AND status = ?');
        $stmt->execute([$sessionId, 'uploading']);
        $existing = $stmt->fetch();
        if ($existing) {
            apiResponse(array(
                'success' => true,
                'session_id' => $sessionId,
                'received_chunks' => $existing['received_chunks'] ?? '',
                'total_chunks' => intval($existing['chunk_count']),
                'resumed' => true,
            ));
        }
    }

    // 新会话
    if (empty($sessionId)) {
        $sessionId = bin2hex(random_bytes(16));
    }

    $validation = validateFileType($filename, '');
    if (!$validation['valid']) {
        apiError($validation['error'], 400);
    }

    // 插入 upload_logs 记录
    $stmt = $db->prepare('
        INSERT INTO upload_logs
            (item_id, ip, filename, filesize, upload_time, duration, expire_time, user_agent, action,
             session_id, chunk_count, received_chunks, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute(array(
        null,
        getRealIP(),
        $filename,
        $fileSize,
        time(),
        $duration,
        $duration === 0 ? 0 : (time() + $duration),
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'upload',
        $sessionId,
        $totalChunks,
        str_repeat('0', $totalChunks),
        'uploading',
    ));

    // 创建分片目录
    $sessionDir = CHUNK_DIR . $sessionId . '/';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }

    apiResponse(array(
        'success' => true,
        'session_id' => $sessionId,
        'received_chunks' => str_repeat('0', $totalChunks),
        'total_chunks' => $totalChunks,
    ));
}

/**
 * 接收单个分片
 */
function handleChunkReceive() {
    if (empty($_POST['session_id']) || !isset($_POST['chunk_index'])) {
        apiError('缺少 session_id 或 chunk_index', 400);
    }
    if (empty($_FILES['file'])) {
        apiError('缺少分片文件', 400);
    }

    $sessionId = preg_replace('/[^a-f0-9]/i', '', $_POST['session_id']);
    $chunkIndex = intval($_POST['chunk_index']);

    if (strlen($sessionId) !== 32 || $chunkIndex < 0) {
        apiError('无效的 session_id 或 chunk_index', 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT chunk_count, received_chunks, ip FROM upload_logs WHERE session_id = ? AND status = ?');
    $stmt->execute(array($sessionId, 'uploading'));
    $log = $stmt->fetch();

    if (!$log) {
        apiError('会话不存在或已结束', 410);
    }

    $totalChunks = intval($log['chunk_count']);
    $bitmap = $log['received_chunks'] ?? str_repeat('0', $totalChunks);

    if ($chunkIndex >= $totalChunks) {
        apiError('chunk_index 超出范围', 400);
    }

    // 验证 IP
    if ($log['ip'] !== getRealIP()) {
        apiError('会话与 IP 不匹配', 403);
    }

    // 写入分片文件
    $sessionDir = CHUNK_DIR . $sessionId . '/';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }
    $chunkPath = $sessionDir . $chunkIndex;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath)) {
        apiError('分片写入失败', 500);
    }

    // 更新位图
    $bitmap[$chunkIndex] = '1';
    $db->prepare('UPDATE upload_logs SET received_chunks = ? WHERE session_id = ?')
       ->execute(array($bitmap, $sessionId));

    apiResponse(array(
        'success' => true,
        'chunk_index' => $chunkIndex,
        'received_chunks' => $bitmap,
    ));
}

/**
 * 合并所有分片
 */
function handleChunkMerge() {
    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = preg_replace('/[^a-f0-9]/i', '', $input['session_id'] ?? '');

    if (strlen($sessionId) !== 32) {
        apiError('无效的 session_id', 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM upload_logs WHERE session_id = ? AND status = ?');
    $stmt->execute(array($sessionId, 'uploading'));
    $log = $stmt->fetch();

    if (!$log) {
        apiError('会话不存在或已结束', 410);
    }

    $totalChunks = intval($log['chunk_count']);
    $bitmap = $log['received_chunks'] ?? '';

    // 检查位图完整性
    if (strlen($bitmap) !== $totalChunks || strpos($bitmap, '0') !== false) {
        apiResponse(array(
            'success' => false,
            'error' => 'INCOMPLETE_CHUNKS',
            'message' => '分片未全部到达',
            'received_chunks' => $bitmap,
        ), 409);
    }

    // 合并分片
    $sessionDir = CHUNK_DIR . $sessionId . '/';
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($log['filename']));
    $safeName = substr($safeName, 0, 200);
    $finalName = time() . '_' . uniqid() . '_' . $safeName;
    $finalPath = UPLOAD_DIR . $finalName;

    $fp = fopen($finalPath, 'wb');
    if (!$fp) {
        apiError('无法创建目标文件', 500);
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $sessionDir . $i;
        if (!file_exists($chunkPath)) {
            fclose($fp);
            @unlink($finalPath);
            apiResponse(array('success' => false, 'error' => 'INCOMPLETE_CHUNKS', 'missing_chunk' => $i), 409);
        }
        $chunkFp = fopen($chunkPath, 'rb');
        stream_copy_to_stream($chunkFp, $fp);
        fclose($chunkFp);
    }
    fclose($fp);

    // SHA-256 + 去重
    $fileHash = hash_file('sha256', $finalPath);
    $dupStmt = $db->prepare('SELECT id, path FROM items WHERE file_hash = ? AND type = \'file\' LIMIT 1');
    $dupStmt->execute(array($fileHash));
    $duplicate = $dupStmt->fetch();

    if ($duplicate && !empty($duplicate['path']) && file_exists($duplicate['path'])) {
        @unlink($finalPath);
        $finalPath = $duplicate['path'];
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $finalPath);
        finfo_close($finfo);
    }

    $duration = intval($log['duration']);
    $expire = $duration === 0 ? 0 : (time() + $duration);
    $shareCode = generateShareCode($db);

    $itemStmt = $db->prepare('
        INSERT INTO items
            (share_code, type, name, path, size, file_hash, mime_type, download_count,
             ip, user_agent, time, expire, duration, thumbnail_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $itemStmt->execute(array(
        $shareCode,
        'file',
        $log['filename'],
        $finalPath,
        intval($log['filesize']),
        $fileHash,
        $mimeType,
        0,
        $log['ip'],
        $log['user_agent'],
        time(),
        $expire,
        $duration,
        null,
    ));
    $itemId = $db->lastInsertId();

    $db->prepare('UPDATE upload_logs SET status = ?, item_id = ? WHERE session_id = ?')
       ->execute(array('merged', $itemId, $sessionId));

    foreach (glob($sessionDir . '*') as $f) {
        @unlink($f);
    }
    @rmdir($sessionDir);

    apiResponse(array(
        'success' => true,
        'item' => array(
            'id' => $itemId,
            'share_code' => $shareCode,
            'share_url' => getBaseUrl() . '?s=' . $shareCode,
            'name' => $log['filename'],
            'size' => intval($log['filesize']),
            'size_formatted' => formatSize($log['filesize']),
        ),
    ));
}

/**
 * 清理过期的分片会话（24 小时未合并）
 */
function cleanExpiredChunkSessions() {
    $db = getDB();
    $threshold = time() - CHUNK_SESSION_TTL;

    $stmt = $db->prepare('SELECT session_id FROM upload_logs WHERE status = ? AND upload_time < ?');
    $stmt->execute(array('uploading', $threshold));
    $expired = $stmt->fetchAll();

    foreach ($expired as $row) {
        $sid = $row['session_id'];
        $dir = CHUNK_DIR . $sid . '/';
        if (is_dir($dir)) {
            foreach (glob($dir . '*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $db->prepare('UPDATE upload_logs SET status = ? WHERE session_id = ?')
           ->execute(array('aborted', $sid));
    }
}
