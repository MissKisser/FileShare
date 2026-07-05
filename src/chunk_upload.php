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
 *
 * I7 安全加固（4 处）：
 *   1. 重新校验大文件密码（init 时校验过，merge 时再次校验，防 init 后篡改）
 *      merge 请求 body 必须带 large_file_password
 *   2. 实测 merge 后文件大小 vs init 声明 filesize，偏差 > 1% 拒绝（防分片篡改）
 *   3. 乐观锁：UPDATE status='merging' WHERE status='uploading'，
 *      affected rows != 1 拒绝（防并发 merge 双倍写库）
 *   4. stream_copy_to_stream 检查返回值，失败回滚
 */
function handleChunkMerge() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiError('请求体格式错误', 400);
    }
    $sessionId = preg_replace('/[^a-f0-9]/i', '', $input['session_id'] ?? '');
    $largeFilePassword = $input['large_file_password'] ?? '';

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

    // I7.1：重新校验大文件密码（init 时已校验，merge 时再校验，防 init 后绕过）
    $declaredSize = intval($log['filesize']);
    if ($declaredSize > MAX_FILE_SIZE_NORMAL) {
        if (!verifyLargeFilePassword($largeFilePassword)) {
            apiError('大文件密码错误或缺失（merge 阶段重新校验）', 403);
        }
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

    // I7.3：乐观锁 —— 把 status 从 'uploading' 改为 'merging'，
    // affected rows != 1 说明已被其他 merge 请求抢先，拒绝
    $lockStmt = $db->prepare("UPDATE upload_logs SET status = 'merging' WHERE session_id = ? AND status = 'uploading'");
    $lockStmt->execute(array($sessionId));
    if ($lockStmt->rowCount() !== 1) {
        apiError('会话正在被另一个 merge 请求处理，请勿重复提交', 409);
    }

    $sessionDir = CHUNK_DIR . $sessionId . '/';
    $safeName = sanitizeStoredFilename($log['filename']);
    $finalName = time() . '_' . uniqid() . '_' . $safeName;
    $finalPath = UPLOAD_DIR . $finalName;

    $fp = fopen($finalPath, 'wb');
    if (!$fp) {
        // 失败时回退 status 允许重试
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiError('无法创建目标文件', 500);
    }

    // I7.4：检查 stream_copy_to_stream 返回值
    $mergeError = null;
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $sessionDir . $i;
        if (!file_exists($chunkPath)) {
            $mergeError = array('error' => 'INCOMPLETE_CHUNKS', 'missing_chunk' => $i);
            break;
        }
        $chunkFp = fopen($chunkPath, 'rb');
        if (!$chunkFp) {
            $mergeError = array('error' => 'CHUNK_READ_FAILED', 'chunk_index' => $i);
            break;
        }
        $copied = stream_copy_to_stream($chunkFp, $fp);
        fclose($chunkFp);
        if ($copied === false) {
            $mergeError = array('error' => 'CHUNK_COPY_FAILED', 'chunk_index' => $i);
            break;
        }
    }
    fclose($fp);

    if ($mergeError !== null) {
        @unlink($finalPath);
        // 失败时回退 status 允许重试
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiResponse(array_merge(array('success' => false), $mergeError), 409);
    }

    // I7.2：实测 merge 后大小 vs init 声明 filesize
    // 偏差 > 1% 拒绝（防攻击者控制分片大小绕过 init 时的密码阈值）
    $actualSize = filesize($finalPath);
    if ($actualSize <= 0) {
        @unlink($finalPath);
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiError('合并后文件大小异常', 500);
    }
    $sizeDeviationPct = abs($actualSize - $declaredSize) / max($declaredSize, 1) * 100;
    if ($sizeDeviationPct > 1.0) {
        @unlink($finalPath);
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiError("合并后文件大小 ({$actualSize}) 与初始声明 ({$declaredSize}) 偏差超过 1%，疑似分片篡改", 400);
    }
    // 同时强校验：实测大小若超过普通阈值且未通过密码，也拒绝（双重保险）
    if ($actualSize > MAX_FILE_SIZE_NORMAL && !verifyLargeFilePassword($largeFilePassword)) {
        @unlink($finalPath);
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiError('实际文件大小超过普通上限，需要大文件密码', 403);
    }

    // I6 重构：复用 createFileItem 完成去重 + INSERT + 日志
    // chunk merge 场景文件已在 UPLOAD_DIR，传 isAlreadyMoved=true（用 rename 而非 move_uploaded_file）
    $duration = intval($log['duration']);
    $accessPassword = ''; // chunk init 当前未支持 access_password 字段，预留
    $result = createFileItem($log['filename'], $finalPath, $actualSize, $duration, $accessPassword, true);
    if ($result['error'] !== null) {
        // 失败时回退 status，清理可能的临时文件
        $db->prepare("UPDATE upload_logs SET status = 'uploading' WHERE session_id = ?")->execute(array($sessionId));
        apiError($result['error'], 400);
    }

    $itemId = $result['item']['id'];
    $shareCode = $result['item']['share_code'];
    $ownerToken = $result['owner_token'];

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
            'manage_url' => getBaseUrl() . '?s=' . $shareCode . '&manage=' . $ownerToken,
            'name' => $log['filename'],
            'size' => $actualSize,
            'size_formatted' => formatSize($actualSize),
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
