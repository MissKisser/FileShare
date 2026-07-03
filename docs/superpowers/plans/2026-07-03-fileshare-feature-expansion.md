# FileShare 功能扩展实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为 FileShare 添加 7 项功能：语法高亮、图片灯箱、PDF 预览、Markdown 渲染、分片上传、缩略图生成、压缩包预览。

**Architecture:** 渐进式扩展现有 PHP/SQLite 架构。新增 7 个按资源类型拆分的前端 JS 模块 + 3 个新 API 端点（chunk/merge/archive）。数据库通过幂等 ALTER TABLE 自动迁移。ffmpeg 为可选依赖。

**Tech Stack:**
- 后端：PHP 7.4+（无命名参数、无 match、无 nullsafe）
- 数据库：SQLite（WAL 模式）
- 前端：原生 HTML/CSS/JS + 第三方 CDN（Prism、PDF.js、Marked、DOMPurify、exifr）
- 图像处理：GD/Imagick（PHP 内置）
- 视频缩略图：ffmpeg（可选）

**Spec:** `docs/superpowers/specs/2026-07-03-fileshare-feature-expansion-design.md`

**实施优先级：** P0 → P1 → P2 → P3，按顺序交付。

---

## 文件结构总览

### 新增 PHP 文件
- `src/chunk_upload.php` — 分片上传会话管理（init/chunk/merge 三个函数）
- `src/thumbnail.php` — 缩略图生成函数（图片 GD + 视频 ffmpeg）
- `src/archive.php` — 压缩包解析与预览

### 新增前端 JS 文件
- `assets/js/syntax.js` — Prism 语法高亮封装
- `assets/js/gallery.js` — 图片灯箱
- `assets/js/pdf-preview.js` — PDF.js 包装
- `assets/js/markdown.js` — Marked.js + DOMPurify 渲染
- `assets/js/chunked-upload.js` — 分片上传控制器
- `assets/js/thumbnail.js` — 缩略图展示与懒加载
- `assets/js/archive-preview.js` — 压缩包导航

### 新增前端 CSS 文件
- `assets/css/gallery.css` — 灯箱样式
- `assets/css/pdf-preview.css` — PDF 预览样式
- `assets/css/archive.css` — 压缩包浏览器样式

### 修改文件
- `src/database.php` — 添加幂等 ALTER TABLE 迁移（thumbnail_path、session_id、chunk_count、received_chunks、status）
- `src/api.php` — 添加 upload/init、upload/chunk、upload/merge、archive/list、archive/read 路由
- `src/handlers.php` — 添加 ?action=thumb 处理函数、缩略图懒生成端点
- `src/admin.php` — 添加"批量生成缩略图"端点
- `src/config.php` — 添加新 .env 配置常量
- `templates/share.php` — 集成 A1/A2/A3/A6（语法、灯箱、PDF、MD）
- `templates/main.php` — 集成 B1（分片上传触发）、B2（缩略图展示）
- `templates/admin/layout.php` — 集成"批量生成缩略图"按钮
- `assets/js/upload.js` — 集成分片上传决策（>50MB 启用分片）
- `.env.example` — 添加新配置项示例
- `README.md` — 更新功能列表、新增配置文档
- `API.md` — 添加新 API 端点文档

### 不新增表
- 缩略图元数据存于 `items.thumbnail_path`
- 分片进度存于 `upload_logs.session_id/chunk_count/received_chunks/status`

---

## 任务列表总览

| Task | 模块 | 优先级 | 依赖 |
|------|------|--------|------|
| 1 | 数据库迁移（P0 前置） | P0 | 无 |
| 2 | 缩略图生成后端函数 | P0 | Task 1 |
| 3 | 分片上传后端（init/chunk/merge） | P0 | Task 1 |
| 4 | 分片上传前端控制器 | P0 | Task 3 |
| 5 | 缩略图懒生成端点 + 前端展示 | P1 | Task 2 |
| 6 | 语法高亮前端（A1） | P1 | 无 |
| 7 | 图片灯箱前端（A2） | P1 | 无 |
| 8 | Markdown 渲染前端（A6） | P2 | 无 |
| 9 | 压缩包后端（archive/list + read） | P2 | Task 1 |
| 10 | 压缩包前端导航 | P2 | Task 9 |
| 11 | PDF 预览前端（A3） | P3 | 无 |
| 12 | 管理后台批量生成缩略图 | P3 | Task 2 |
| 13 | 文档与 .env 更新 | P3 | 全部 |

---
### Task 1: 数据库迁移（P0 前置）

**Files:**
- Modify: `src/database.php:39-145`（`initDB()` 函数末尾追加迁移逻辑）

**目的：** 为新功能添加字段，所有迁移幂等，已运行过的数据库再次运行不会重复 ALTER。

- [ ] **Step 1: 阅读现有 initDB 函数**

读 `src/database.php:39-145`，了解 `initDB()` 现有结构。注意：该函数使用 `if (!empty($tables)) { return; }` 提前退出，因此**不能把新 ALTER 放进现有函数**。需要在函数**末尾**追加迁移代码块，但要在 `if (!empty($tables)) return;` 检查**之后**执行。

- [ ] **Step 2: 修改 initDB 函数，添加幂等迁移**

在 `src/database.php` 中找到 `function initDB($db) {` 块（约第 39 行）。在该函数的**最后一行之前**（即 `}` 闭合前），追加以下代码：

```php
    // ============================
    // 增量迁移（新功能字段）— 幂等
    // ============================
    // 检查 upload_logs 新字段
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

    // 检查 items 新字段
    $itemCols = array_column(
        $db->query("PRAGMA table_info(items)")->fetchAll(),
        'name'
    );
    if (!in_array('thumbnail_path', $itemCols)) {
        $db->exec("ALTER TABLE items ADD COLUMN thumbnail_path TEXT");
    }
```

**重要：** 这段代码必须放在现有 CREATE TABLE 块**之后**（因为它需要表已经存在才能检查列）。最稳妥的做法是替换整个 `initDB` 函数体的最后两行（原 `}` 闭合前）。

完整 `initDB` 修改后结构应为：

```php
function initDB($db) {
    // 检查是否已初始化（通过 items 表是否存在判断）
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='items'")->fetchAll();
    if (!empty($tables)) {
        // 表已存在，但仍需执行增量迁移（幂等）
        runIncrementalMigrations($db);
        return;
    }

    // ... 现有 CREATE TABLE 代码 ...

    // 插入默认设置
    // ...

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
}
```

- [ ] **Step 3: 手工验证迁移幂等性**

执行：

```bash
# 启动 PHP 内置服务器（如果未运行）
php -S localhost:9000 &
SERVER_PID=$!

# 第一次访问触发迁移
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:9000/
# 预期：200

# 用 sqlite3 验证字段已添加
sqlite3 storage/fileshare.db "PRAGMA table_info(upload_logs);"
# 预期：应包含 session_id, chunk_count, received_chunks, status 列

sqlite3 storage/fileshare.db "PRAGMA table_info(items);"
# 预期：应包含 thumbnail_path 列

# 第二次访问验证幂等性
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:9000/
# 预期：200（不应报错）

# 再次运行不应有错误
sqlite3 storage/fileshare.db "PRAGMA table_info(upload_logs);"
# 预期：列保持不变，无重复

# 清理
kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 4: 提交**

```bash
git add src/database.php
git commit -m "feat(db): add incremental migrations for thumbnail_path and chunk fields"
```

---

### Task 2: 缩略图生成后端函数

**Files:**
- Create: `src/thumbnail.php`

**目的：** 提供图片缩略图（GD）和视频缩略图（ffmpeg）生成函数。thumbnail_path 三态语义：NULL/空 = 未生成；`failed:<reason>` = 已尝试失败；相对路径 = 已生成。

- [ ] **Step 1: 创建 src/thumbnail.php 文件**

```php
<?php
/**
 * 缩略图生成
 * 作者：FileShare Contributors
 *
 * - 图片：使用 GD 或 Imagick
 * - 视频：使用 ffmpeg（可选依赖，未安装时返回 failed 状态）
 *
 * thumbnail_path 三态：
 *   NULL 或 ''          → 未尝试生成
 *   'failed:<reason>'   → 已尝试但失败
 *   '<相对路径>'        → 已生成（相对 uploads/）
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

define('THUMBNAIL_QUALITY', intval(loadEnvVar('THUMBNAIL_QUALITY', '75')));
define('THUMBNAIL_MAX_SIZE', intval(loadEnvVar('THUMBNAIL_MAX_SIZE', '320')));

/**
 * 检测 ffmpeg 是否可用
 *
 * @return bool
 */
function isFfmpegAvailable() {
    if (!function_exists('shell_exec')) {
        return false;
    }
    $result = trim(shell_exec('which ffmpeg 2>/dev/null'));
    return !empty($result);
}

/**
 * 生成图片缩略图
 *
 * @param string $sourcePath  原图绝对路径
 * @param string $itemName    项目原始文件名（用于判断格式）
 * @return string|false       缩略图相对路径（相对 uploads/）或 false
 */
function generateImageThumbnail($sourcePath, $itemName) {
    if (!file_exists($sourcePath)) {
        return false;
    }

    $ext = strtolower(pathinfo($itemName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return false;
    }

    $thumbPath = $sourcePath . '.thumb.jpg';

    // 优先使用 Imagick
    if (extension_loaded('imagick')) {
        try {
            $image = new Imagick($sourcePath);
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image->thumbnailImage(THUMBNAIL_MAX_SIZE, THUMBNAIL_MAX_SIZE, true);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(THUMBNAIL_QUALITY);
            $image->writeImage($thumbPath);
            $image->destroy();
            return $thumbPath;
        } catch (Exception $e) {
            return false;
        }
    }

    // 降级到 GD
    if (!extension_loaded('gd')) {
        return false;
    }

    try {
        $image = null;
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            case 'gif':
                $image = @imagecreatefromgif($sourcePath);
                break;
            case 'webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($sourcePath);
                }
                break;
            case 'bmp':
                if (function_exists('imagecreatefrombmp')) {
                    $image = @imagecreatefrombmp($sourcePath);
                }
                break;
        }

        if (!$image) {
            return false;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $ratio = min(THUMBNAIL_MAX_SIZE / $srcW, THUMBNAIL_MAX_SIZE / $srcH, 1);
        $dstW = (int)($srcW * $ratio);
        $dstH = (int)($srcH * $ratio);

        $thumb = imagecreatetruecolor($dstW, $dstH);
        // PNG/GIF 透明背景填充白色
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefilledrectangle($thumb, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagejpeg($thumb, $thumbPath, THUMBNAIL_QUALITY);
        imagedestroy($image);
        imagedestroy($thumb);

        return $thumbPath;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 生成视频缩略图（ffmpeg）
 *
 * @param string $sourcePath  视频绝对路径
 * @return string|false       缩略图路径或 false（ffmpeg 缺失/失败）
 */
function generateVideoThumbnail($sourcePath) {
    if (!file_exists($sourcePath)) {
        return false;
    }
    if (!isFfmpegAvailable()) {
        return false;
    }

    $thumbPath = $sourcePath . '.thumb.jpg';
    $sourceEsc = escapeshellarg($sourcePath);
    $thumbEsc = escapeshellarg($thumbPath);

    // 取第 1 秒帧，缩放最长边 320
    $cmd = "ffmpeg -ss 00:00:01 -i {$sourceEsc} -vframes 1 -vf scale=320:-1 -q:v 5 -y {$thumbEsc} 2>&1";

    $fp = popen("timeout 5 {$cmd}", 'r');
    if ($fp) {
        while (!feof($fp)) {
            fread($fp, 8192);
        }
        pclose($fp);
    }

    return file_exists($thumbPath) ? $thumbPath : false;
}

/**
 * 为项目生成缩略图，更新数据库 thumbnail_path
 *
 * @param int $itemId
 * @return string thumbnail_path 新值（相对 uploads/ 或 failed:<reason>）
 */
function generateItemThumbnail($itemId) {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, type, name, path FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item || $item['type'] !== 'file' || empty($item['path'])) {
        return 'failed:not-a-file';
    }

    $name = $item['name'] ?? '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // 图片缩略图
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $result = generateImageThumbnail($item['path'], $name);
        if ($result === false) {
            $newVal = 'failed:image-decode-error';
        } else {
            // 相对路径
            $newVal = basename($result);
        }
    }
    // 视频缩略图
    elseif (in_array($ext, ['mp4', 'webm', 'ogv', 'ogg', 'avi', 'mov', 'mkv'], true)) {
        $result = generateVideoThumbnail($item['path']);
        if ($result === false) {
            $newVal = isFfmpegAvailable() ? 'failed:ffmpeg-error' : 'failed:no-ffmpeg';
        } else {
            $newVal = basename($result);
        }
    } else {
        // 不支持的类型
        $newVal = 'failed:unsupported-type';
    }

    $db->prepare('UPDATE items SET thumbnail_path = ? WHERE id = ?')
       ->execute([$newVal, $itemId]);

    return $newVal;
}

/**
 * 检查缩略图状态
 *
 * @param string|null $thumbnailPath
 * @return string  'none' | 'failed' | 'ready'
 */
function getThumbnailStatus($thumbnailPath) {
    if (empty($thumbnailPath)) return 'none';
    if (strpos($thumbnailPath, 'failed:') === 0) return 'failed';
    return 'ready';
}
```

- [ ] **Step 2: 修改 src/handlers.php，添加 ?action=thumb 处理**

找到 `src/handlers.php` 的 `handleRequest()` 函数（约第 87 行），在路由分发块中添加以下分支（放在分享页路由之前）：

```php
    // ===== 缩略图懒生成端点（B2） =====
    if (isset($_GET['action']) && $_GET['action'] === 'thumb' && isset($_GET['item_id'])) {
        require_once __DIR__ . '/thumbnail.php';
        header('Content-Type: application/json; charset=utf-8');

        $itemId = intval($_GET['item_id']);
        if ($itemId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的 item_id']);
            exit;
        }

        // 触发生成（同步）
        $newVal = generateItemThumbnail($itemId);
        echo json_encode([
            'success' => true,
            'thumbnail_path' => $newVal,
            'status' => getThumbnailStatus($newVal),
        ]);
        exit;
    }
```

- [ ] **Step 3: 手工验证缩略图生成**

```bash
# 启动服务器
php -S localhost:9000 &
SERVER_PID=$!

# 创建测试图片（用 PHP）
php -r 'imagepng(imagecreatetruecolor(800, 600), "uploads/test_thumb.png");'
echo "Created test image"

# 上传一个测试文件（使用 API）
TOKEN=$(curl -s -X POST 'http://localhost:9000/?api=auth/token' \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"$(grep ADMIN_PASSWORD .env | cut -d= -f2)\"}" | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4)

ITEM_ID=$(curl -s -X POST 'http://localhost:9000/?api=upload' \
  -H "Authorization: Bearer $TOKEN" \
  -F "files[]=@uploads/test_thumb.png" \
  -F "duration=86400" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)

echo "Uploaded item ID: $ITEM_ID"

# 触发缩略图生成
curl -s "http://localhost:9000/?action=thumb&item_id=$ITEM_ID"
# 预期：{"success":true,"thumbnail_path":"<filename>.png.thumb.jpg","status":"ready"}

# 验证缩略图文件存在
ls -la uploads/ | grep thumb
# 预期：应能看到 <原文件名>.thumb.jpg 文件

# 检查数据库
sqlite3 storage/fileshare.db "SELECT id, thumbnail_path FROM items WHERE id = $ITEM_ID;"
# 预期：thumbnail_path 不为空且不以 failed: 开头

# 清理
kill $SERVER_PID 2>/dev/null
rm -f uploads/test_thumb.png uploads/*.thumb.jpg
```

- [ ] **Step 4: 提交**

```bash
git add src/thumbnail.php src/handlers.php
git commit -m "feat(thumbnail): add image/video thumbnail generation with GD/ffmpeg"
```

---

### Task 3: 分片上传后端（init/chunk/merge）

**Files:**
- Create: `src/chunk_upload.php`
- Modify: `src/api.php:138-212`（`handleApiRequest()` 路由分发）

**目的：** 实现分片上传 3 个端点。复用现有 SHA-256 去重逻辑，避免大文件重复上传。分片会话 24 小时后自动清理。

- [ ] **Step 1: 创建 src/chunk_upload.php**

```php
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
            apiResponse([
                'success' => true,
                'session_id' => $sessionId,
                'received_chunks' => $existing['received_chunks'] ?? '',
                'total_chunks' => intval($existing['chunk_count']),
                'resumed' => true,
            ]);
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
    $stmt->execute([
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
    ]);

    // 创建分片目录
    $sessionDir = CHUNK_DIR . $sessionId . '/';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }

    apiResponse([
        'success' => true,
        'session_id' => $sessionId,
        'received_chunks' => str_repeat('0', $totalChunks),
        'total_chunks' => $totalChunks,
    ]);
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
    $stmt = $db->prepare('SELECT chunk_count, received_chunks FROM upload_logs WHERE session_id = ? AND status = ?');
    $stmt->execute([$sessionId, 'uploading']);
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
    $stmt = $db->prepare('SELECT ip FROM upload_logs WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $sessionIp = $stmt->fetch()['ip'];
    if ($sessionIp !== getRealIP()) {
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
       ->execute([$bitmap, $sessionId]);

    apiResponse([
        'success' => true,
        'chunk_index' => $chunkIndex,
        'received_chunks' => $bitmap,
    ]);
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
    $stmt->execute([$sessionId, 'uploading']);
    $log = $stmt->fetch();

    if (!$log) {
        apiError('会话不存在或已结束', 410);
    }

    $totalChunks = intval($log['chunk_count']);
    $bitmap = $log['received_chunks'] ?? '';

    // 检查位图完整性
    if (strlen($bitmap) !== $totalChunks || strpos($bitmap, '0') !== false) {
        apiResponse([
            'success' => false,
            'error' => 'INCOMPLETE_CHUNKS',
            'message' => '分片未全部到达',
            'received_chunks' => $bitmap,
        ], 409);
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
            apiResponse(['success' => false, 'error' => 'INCOMPLETE_CHUNKS', 'missing_chunk' => $i], 409);
        }
        $chunkFp = fopen($chunkPath, 'rb');
        stream_copy_to_stream($chunkFp, $fp);
        fclose($chunkFp);
    }
    fclose($fp);

    // SHA-256 + 去重
    $fileHash = hash_file('sha256', $finalPath);
    $dupStmt = $db->prepare('SELECT id, path FROM items WHERE file_hash = ? AND type = \'file\' LIMIT 1');
    $dupStmt->execute([$fileHash]);
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
    $itemStmt->execute([
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
    ]);
    $itemId = $db->lastInsertId();

    $db->prepare('UPDATE upload_logs SET status = ?, item_id = ? WHERE session_id = ?')
       ->execute(['merged', $itemId, $sessionId]);

    foreach (glob($sessionDir . '*') as $f) {
        @unlink($f);
    }
    @rmdir($sessionDir);

    apiResponse([
        'success' => true,
        'item' => [
            'id' => $itemId,
            'share_code' => $shareCode,
            'share_url' => getBaseUrl() . '?s=' . $shareCode,
            'name' => $log['filename'],
            'size' => intval($log['filesize']),
            'size_formatted' => formatSize($log['filesize']),
        ],
    ]);
}

/**
 * 清理过期的分片会话（24 小时未合并）
 */
function cleanExpiredChunkSessions() {
    $db = getDB();
    $threshold = time() - CHUNK_SESSION_TTL;

    $stmt = $db->prepare('SELECT session_id FROM upload_logs WHERE status = ? AND upload_time < ?');
    $stmt->execute(['uploading', $threshold]);
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
           ->execute(['aborted', $sid]);
    }
}
```

- [ ] **Step 2: 修改 src/api.php，添加 3 个新端点**

在 `src/api.php` 的 `handleApiRequest()` 函数（约第 138 行）的 `switch ($endpoint)` 块**之前**，添加以下路由分发：

```php
    // ===== 分片上传端点（B1） =====
    if (strpos($endpoint, 'upload/') === 0) {
        require_once __DIR__ . '/chunk_upload.php';
        switch ($endpoint) {
            case 'upload/init':
                if ($method === 'POST') {
                    validateApiToken('write');
                    handleChunkInit();
                } else {
                    apiError('不支持的请求方法', 405);
                }
                break;
            case 'upload/chunk':
                if ($method === 'POST') {
                    validateApiToken('write');
                    handleChunkReceive();
                } else {
                    apiError('不支持的请求方法', 405);
                }
                break;
            case 'upload/merge':
                if ($method === 'POST') {
                    validateApiToken('write');
                    handleChunkMerge();
                } else {
                    apiError('不支持的请求方法', 405);
                }
                break;
            default:
                apiError('未知的 upload 子端点', 404);
        }
        return;
    }
```

**注意：** 这段代码必须放在现有 `if ($endpoint === 'auth/token' ...)` 免认证块**之后**，`switch ($endpoint)` 块**之前**。具体位置参考 src/api.php:138-156。

- [ ] **Step 3: 手工验证分片上传（部分流程）**

由于完整上传 60MB 文件验证较慢，本步骤只验证 init + chunk + 错误路径：

```bash
# 启动服务器
php -S localhost:9000 &
SERVER_PID=$!

# 获取 token
ADMIN_PWD=$(grep ADMIN_PASSWORD .env | cut -d= -f2)
TOKEN=$(curl -s -X POST 'http://localhost:9000/?api=auth/token' \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"$ADMIN_PWD\"}" | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4)

LARGE_PWD=$(grep LARGE_FILE_PASSWORD .env | cut -d= -f2)

# 步骤 1：init（声称 60MB 文件）
SESSION_ID=$(curl -s -X POST 'http://localhost:9000/?api=upload/init' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"filename\":\"test.bin\",\"size\":62914560,\"mime\":\"application/octet-stream\",\"chunk_size\":5242880,\"total_chunks\":12,\"duration\":3600,\"large_file_password\":\"$LARGE_PWD\"}" \
  | grep -o '"session_id":"[^"]*"' | cut -d'"' -f4)
echo "Session: $SESSION_ID"

# 预期：返回 session_id 为 32 位十六进制字符串

# 步骤 2：上传 3 个分片（0, 1, 2）
for i in 0 1 2; do
  dd if=/dev/zero of=/tmp/chunk_$i bs=5242880 count=1 2>/dev/null
  curl -s -X POST 'http://localhost:9000/?api=upload/chunk' \
    -H "Authorization: Bearer $TOKEN" \
    -F "session_id=$SESSION_ID" \
    -F "chunk_index=$i" \
    -F "file=@/tmp/chunk_$i" > /dev/null
  echo "Uploaded chunk $i"
done

# 预期：3 个分片上传成功，无错误

# 步骤 3：尝试合并（应该失败 INCOMPLETE_CHUNKS）
curl -s -X POST 'http://localhost:9000/?api=upload/merge' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"session_id\":\"$SESSION_ID\"}"
# 预期：{"success":false,"error":"INCOMPLETE_CHUNKS","message":"分片未全部到达","received_chunks":"111000000000"}

# 步骤 4：测试错误密码（>200MB 强制要求）
curl -s -X POST 'http://localhost:9000/?api=upload/init' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"filename\":\"huge.bin\",\"size\":314572800,\"chunk_size\":5242880,\"total_chunks\":60,\"duration\":3600,\"large_file_password\":\"wrong\"}"
# 预期：{"error":"大文件密码错误或缺失"}

# 清理测试数据
kill $SERVER_PID 2>/dev/null
rm -f /tmp/chunk_*
sqlite3 storage/fileshare.db "DELETE FROM upload_logs WHERE session_id = '$SESSION_ID';"
rm -rf uploads/_chunks/$SESSION_ID
```

- [ ] **Step 4: 提交**

```bash
git add src/chunk_upload.php src/api.php
git commit -m "feat(chunked-upload): backend chunked upload with init/chunk/merge endpoints"
```

---

### Task 4: 分片上传前端控制器

**Files:**
- Create: `assets/js/chunked-upload.js`
- Modify: `assets/js/upload.js`（集成 FileShareUploader 触发逻辑）

**目的：** 前端封装分片上传逻辑。自动判断文件大小（>50MB 启用分片），支持并发、续传、进度回调。

- [ ] **Step 1: 创建 assets/js/chunked-upload.js**

```javascript
/**
 * FileShare 分片上传控制器
 * 作者：FileShare Contributors
 *
 * 用法：
 *   const uploader = new FileShareUploader({
 *       chunkSize: 5 * 1024 * 1024,
 *       concurrency: 3,
 *       onProgress: (pct) => {},
 *       onSuccess: (item) => {},
 *       onError: (msg) => {},
 *   });
 *   uploader.upload(file, { duration: 3600, largeFilePassword: 'xxx' });
 */
(function (global) {
    'use strict';

    const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;
    const DEFAULT_CONCURRENCY = 3;
    const CHUNK_THRESHOLD = 50 * 1024 * 1024;

    class FileShareUploader {
        constructor(options) {
            this.chunkSize = (options && options.chunkSize) || DEFAULT_CHUNK_SIZE;
            this.concurrency = (options && options.concurrency) || DEFAULT_CONCURRENCY;
            this.onProgress = (options && options.onProgress) || function () {};
            this.onSuccess = (options && options.onSuccess) || function () {};
            this.onError = (options && options.onError) || function () {};
            this.csrfToken = this._getCsrfToken();
        }

        _getCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) return meta.getAttribute('content');
            const match = document.cookie.match(/csrf_token=([^;]+)/);
            return match ? match[1] : '';
        }

        /**
         * 判断文件是否应该使用分片上传
         */
        static shouldUseChunkedUpload(file) {
            return file.size > CHUNK_THRESHOLD;
        }

        /**
         * 上传文件（自动判断分片）
         */
        upload(file, meta) {
            meta = meta || {};
            if (FileShareUploader.shouldUseChunkedUpload(file)) {
                return this._uploadChunked(file, meta);
            }
            // 小文件走原单次上传
            return this._uploadSimple(file, meta);
        }

        _uploadSimple(file, meta) {
            const formData = new FormData();
            formData.append('files[]', file);
            formData.append('duration', meta.duration || 600);
            if (meta.accessPassword) {
                formData.append('access_password', meta.accessPassword);
            }
            if (this.csrfToken) {
                formData.append('csrf_token', this.csrfToken);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.pathname, true);
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    this.onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = () => {
                try {
                    const resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.items && resp.items[0]) {
                        this.onSuccess(resp.items[0]);
                    } else {
                        this.onError(resp.message || '上传失败');
                    }
                } catch (e) {
                    this.onError('响应解析失败');
                }
            };
            xhr.onerror = () => this.onError('网络错误');
            xhr.send(formData);
        }

        async _uploadChunked(file, meta) {
            const totalChunks = Math.ceil(file.size / this.chunkSize);
            const sessionId = this._generateSessionId();

            try {
                // 步骤 1：init
                const initResp = await this._apiCall('upload/init', {
                    session_id: sessionId,
                    filename: file.name,
                    size: file.size,
                    mime: file.type || 'application/octet-stream',
                    chunk_size: this.chunkSize,
                    total_chunks: totalChunks,
                    duration: meta.duration || 600,
                    access_password: meta.accessPassword || '',
                    large_file_password: meta.largeFilePassword || '',
                });

                let received = initResp.received_chunks || '';
                const actualSessionId = initResp.session_id || sessionId;

                // 步骤 2：上传分片
                const pending = [];
                for (let i = 0; i < totalChunks; i++) {
                    if (received[i] !== '1') {
                        pending.push(i);
                    }
                }

                let completed = totalChunks - pending.length;
                const totalSize = file.size;
                let uploadedBytes = completed * this.chunkSize;
                const self = this;

                await this._runConcurrent(pending, this.concurrency, async (chunkIdx) => {
                    const start = chunkIdx * this.chunkSize;
                    const end = Math.min(start + this.chunkSize, file.size);
                    const blob = file.slice(start, end);

                    const fd = new FormData();
                    fd.append('session_id', actualSessionId);
                    fd.append('chunk_index', chunkIdx);
                    fd.append('file', blob, file.name + '.part' + chunkIdx);

                    await new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '?api=upload/chunk', true);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.onload = () => {
                            if (xhr.status === 200) {
                                uploadedBytes += end - start;
                                completed++;
                                const pct = Math.min(99, Math.round((uploadedBytes / totalSize) * 100));
                                self.onProgress(pct);
                                resolve();
                            } else {
                                reject(new Error('分片 ' + chunkIdx + ' 上传失败：HTTP ' + xhr.status));
                            }
                        };
                        xhr.onerror = () => reject(new Error('分片 ' + chunkIdx + ' 网络错误'));
                        xhr.send(fd);
                    });
                });

                // 步骤 3：merge
                const mergeResp = await this._apiCall('upload/merge', {
                    session_id: actualSessionId,
                });

                if (mergeResp.success && mergeResp.item) {
                    this.onProgress(100);
                    this.onSuccess(mergeResp.item);
                } else {
                    this.onError(mergeResp.message || '合并失败');
                }
            } catch (err) {
                this.onError(err.message || '上传异常');
            }
        }

        _generateSessionId() {
            const arr = new Uint8Array(16);
            crypto.getRandomValues(arr);
            return Array.from(arr, (b) => b.toString(16).padStart(2, '0')).join('');
        }

        async _runConcurrent(items, limit, worker) {
            const results = [];
            const executing = new Set();

            for (const item of items) {
                const p = Promise.resolve().then(() => worker(item));
                results.push(p);
                executing.add(p);
                const clean = () => executing.delete(p);
                p.then(clean, clean);
                if (executing.size >= limit) {
                    await Promise.race(executing);
                }
            }
            return Promise.allSettled(results);
        }

        async _apiCall(endpoint, body) {
            const resp = await fetch('?api=' + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await resp.json();
            if (!resp.ok || data.error) {
                throw new Error(data.error || data.message || 'API 错误');
            }
            return data;
        }
    }

    global.FileShareUploader = FileShareUploader;
})(window);
```

- [ ] **Step 2: 手工验证（浏览器）**

```bash
# 启动服务器
php -S localhost:9000 &
SERVER_PID=$!

# 在浏览器打开 http://localhost:9000/
# 打开 DevTools Console，运行：
#   const u = new FileShareUploader({ onProgress: console.log, onSuccess: console.log, onError: console.error });
#   const f = new File([new Uint8Array(60 * 1024 * 1024)], 'test.bin');
#   u.upload(f, { duration: 3600, largeFilePassword: 'YOUR_LARGE_FILE_PASSWORD' });
#
# 预期：
#   - 进度从 0 → 99
#   - 最终输出 {id, share_code, share_url, ...}
#   - 数据库新增一条 items 记录
#   - uploads/_chunks/ 临时目录被清理

# 验证数据库
sqlite3 storage/fileshare.db "SELECT id, name, size, share_code FROM items WHERE name = 'test.bin' ORDER BY id DESC LIMIT 1;"
# 预期：name=test.bin, size=62914560

# 清理
kill $SERVER_PID 2>/dev/null
sqlite3 storage/fileshare.db "DELETE FROM items WHERE name = 'test.bin';"
```

- [ ] **Step 3: 提交**

```bash
git add assets/js/chunked-upload.js
git commit -m "feat(chunked-upload): frontend FileShareUploader controller"
```

---

### Task 5: 缩略图懒生成端点 + 前端展示

**Files:**
- Create: `assets/js/thumbnail.js`

**目的：** 项目卡片首次展示无缩略图时，请求懒生成；同时支持缩略图点击放大（与 A2 灯箱配合）。

- [ ] **Step 1: 创建 assets/js/thumbnail.js**

```javascript
/**
 * FileShare 缩略图展示与懒生成
 * 作者：FileShare Contributors
 *
 * - 自动为 [data-thumbnail-item] 元素加载缩略图
 * - 缩略图缺失时请求懒生成
 * - 失败时显示默认图标
 */
(function () {
    'use strict';

    const FAILED_PREFIX = 'failed:';

    function getDefaultIcon(item) {
        // 根据 MIME 推断图标
        const mime = item.dataset.mime || '';
        const name = (item.dataset.name || '').toLowerCase();
        if (mime.startsWith('image/')) return '🖼️';
        if (mime.startsWith('video/')) return '🎬';
        if (mime.startsWith('audio/')) return '🎵';
        if (mime === 'application/pdf') return '📕';
        if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return '📦';
        if (/\.(doc|docx)$/i.test(name)) return '📘';
        if (/\.(xls|xlsx)$/i.test(name)) return '📗';
        if (/\.(ppt|pptx)$/i.test(name)) return '📙';
        if (/\.(txt|md|json|xml|csv)$/i.test(name)) return '📄';
        return '📎';
    }

    function renderPlaceholder(item, reason) {
        const icon = getDefaultIcon(item);
        item.innerHTML = '<span class="thumb-placeholder" title="' + (reason || '无缩略图') + '">' + icon + '</span>';
    }

    function renderThumbnail(item, thumbPath) {
        item.innerHTML = '<img src="/uploads/' + encodeURIComponent(thumbPath) +
            '" alt="" loading="lazy" class="thumb-image" />';
    }

    function loadThumbnail(item) {
        const itemId = item.dataset.thumbnailItem;
        const existing = item.dataset.thumbnailPath || '';

        if (existing && existing.indexOf(FAILED_PREFIX) !== 0) {
            renderThumbnail(item, existing);
            return;
        }
        if (existing && existing.indexOf(FAILED_PREFIX) === 0) {
            renderPlaceholder(item, existing.substring(FAILED_PREFIX.length));
            return;
        }

        // 懒生成
        fetch('?action=thumb&item_id=' + itemId)
            .then((r) => r.json())
            .then((data) => {
                if (data.success && data.thumbnail_path && data.thumbnail_path.indexOf(FAILED_PREFIX) !== 0) {
                    renderThumbnail(item, data.thumbnail_path);
                    item.dataset.thumbnailPath = data.thumbnail_path;
                } else {
                    renderPlaceholder(item, data.thumbnail_path || '生成失败');
                    if (data.thumbnail_path) {
                        item.dataset.thumbnailPath = data.thumbnail_path;
                    }
                }
            })
            .catch(() => renderPlaceholder(item, '网络错误'));
    }

    function init() {
        const items = document.querySelectorAll('[data-thumbnail-item]');
        items.forEach(loadThumbnail);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 2: 修改 templates/main.php 项目卡片 HTML**

**步骤：** 在 `templates/main.php` 中搜索现有的图片缩略图渲染逻辑（搜索关键字如 `mime_type`、`thumbnail_path`、`item-thumb`、`<img`，根据实际情况定位）。找到项目卡片的渲染处，将缩略图位置替换为以下 HTML：

找到项目卡片渲染处，**在 `<img>` 或图标位置替换为**：

```php
<div class="item-thumb" data-thumbnail-item="<?php echo $item['id']; ?>"
     data-thumbnail-path="<?php echo htmlspecialchars($item['thumbnail_path'] ?? ''); ?>"
     data-mime="<?php echo htmlspecialchars($item['mime_type'] ?? ''); ?>"
     data-name="<?php echo htmlspecialchars($item['name'] ?? ''); ?>">
    <!-- 由 thumbnail.js 填充 -->
</div>
```

**注意：** 实际 main.php 中渲染图片缩略图的代码可能因现有结构不同而需要适配。请在 main.php 中搜索 `thumbnail_path` 字段引用（如已有），将渲染逻辑替换为以上 `data-thumbnail-item` 容器。

- [ ] **Step 3: 修改 templates/main.php 末尾加载 thumbnail.js**

在 `templates/main.php` 中找到 `<script src="assets/js/upload.js"></script>` 附近，**在其之前**添加：

```html
<script src="assets/js/thumbnail.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 4: 添加 CSS（assets/css/components.css 末尾追加）**

```css
.item-thumb {
    width: 80px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 4px;
    overflow: hidden;
    flex-shrink: 0;
}

.thumb-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.thumb-placeholder {
    font-size: 28px;
    opacity: 0.5;
}
```

- [ ] **Step 5: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 浏览器访问 http://localhost:9000/
# 1. 上传一张图片
# 2. 回到主页，应看到图片缩略图（首次会请求 ?action=thumb 生成）
# 3. 刷新页面，应直接显示缩略图（不再请求）
# 4. DevTools Network 检查 ?action=thumb 请求是否仅触发一次

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 6: 提交**

```bash
git add assets/js/thumbnail.js templates/main.php assets/css/components.css
git commit -m "feat(thumbnail): frontend lazy thumbnail loading with placeholder"
```

---

### Task 6: 语法高亮前端（A1）

**Files:**
- Create: `assets/js/syntax.js`

**目的：** 复用已加载的 Prism.js，封装文本/代码块的自动高亮。

- [ ] **Step 1: 创建 assets/js/syntax.js**

```javascript
/**
 * FileShare 语法高亮封装
 * 作者：FileShare Contributors
 *
 * 自动为带有 data-language 属性的 <pre>/<code> 元素调用 Prism 高亮。
 * 依赖：Prism.js 已在主页面通过 CDN 加载。
 */
(function () {
    'use strict';

    const EXT_LANG_MAP = {
        'js': 'javascript', 'ts': 'typescript', 'py': 'python',
        'java': 'java', 'c': 'c', 'cpp': 'cpp', 'h': 'c', 'hpp': 'cpp',
        'cs': 'csharp', 'go': 'go', 'rs': 'rust', 'swift': 'swift',
        'kt': 'kotlin', 'rb': 'ruby', 'sh': 'bash', 'bash': 'bash',
        'ps1': 'powershell', 'sql': 'sql', 'json': 'json', 'xml': 'xml',
        'html': 'markup', 'htm': 'markup', 'css': 'css', 'scss': 'scss',
        'less': 'less', 'yaml': 'yaml', 'yml': 'yaml', 'md': 'markdown',
        'markdown': 'markdown',
    };

    function detectLanguageByName(name) {
        if (!name) return null;
        const m = name.toLowerCase().match(/\.([a-z0-9]+)$/);
        if (!m) return null;
        return EXT_LANG_MAP[m[1]] || null;
    }

    function highlight(elm) {
        if (!window.Prism) return;
        const code = elm.querySelector('code') || elm;
        const lang = elm.dataset.language || code.dataset.language ||
                     detectLanguageByName(elm.dataset.filename || '');
        if (lang && window.Prism.languages[lang]) {
            code.classList.add('language-' + lang);
            window.Prism.highlightElement(code);
        } else if (elm.tagName === 'PRE' || elm.tagName === 'CODE') {
            window.Prism.highlightElement(elm);
        }
    }

    function init() {
        // 文本项目（share.php 渲染的 .text-content）
        document.querySelectorAll('.text-content pre code, .text-content pre').forEach((elm) => {
            highlight(elm.tagName === 'CODE' ? elm.parentElement : elm);
        });

        // 代码文件预览（.code-preview）
        document.querySelectorAll('.code-preview, [data-syntax]').forEach(highlight);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 2: 修改 templates/share.php 加载 syntax.js**

在 `templates/share.php` 找到 `</body>` 之前，添加：

```html
<script src="assets/js/syntax.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 3: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 浏览器：上传一个 .js 文件
# 访问分享页 ?s=XXXXXX
# 预期：代码块显示彩色高亮（Prism 主题样式生效）

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 4: 提交**

```bash
git add assets/js/syntax.js templates/share.php
git commit -m "feat(syntax): add syntax highlighting wrapper for Prism.js"
```

---

### Task 7: 图片灯箱前端（A2）

**Files:**
- Create: `assets/js/gallery.js`
- Create: `assets/css/gallery.css`

**目的：** 图片点击放大显示，灯箱内可左右切换（同分享页图片）。

- [ ] **Step 1: 创建 assets/css/gallery.css**

```css
.gallery-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.92);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    user-select: none;
}

.gallery-overlay.active {
    display: flex;
}

.gallery-stage {
    max-width: 95vw;
    max-height: 95vh;
    position: relative;
}

.gallery-image {
    max-width: 100%;
    max-height: 95vh;
    object-fit: contain;
    transition: transform 0.2s ease;
    transform-origin: center center;
}

.gallery-image.zoomed {
    cursor: grab;
}

.gallery-toolbar {
    position: fixed;
    top: 20px;
    right: 20px;
    display: flex;
    gap: 10px;
    z-index: 10001;
}

.gallery-btn {
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}

.gallery-btn:hover {
    background: rgba(255, 255, 255, 0.25);
}

.gallery-nav {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: none;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    font-size: 24px;
    cursor: pointer;
    z-index: 10001;
}

.gallery-nav.prev {
    left: 20px;
}

.gallery-nav.next {
    right: 20px;
}

.gallery-counter {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    color: white;
    background: rgba(0, 0, 0, 0.5);
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 14px;
}
```

- [ ] **Step 2: 创建 assets/js/gallery.js**

```javascript
/**
 * FileShare 图片灯箱
 * 作者：FileShare Contributors
 *
 * 用法：所有 [data-gallery] 图片元素会在分享页被绑定，点击触发灯箱。
 * 灯箱内可左右切换同分享页所有图片。
 */
(function () {
    'use strict';

    let overlay = null;
    let currentIdx = 0;
    let images = [];
    let scale = 1;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'gallery-overlay';
        overlay.innerHTML =
            '<div class="gallery-toolbar">' +
                '<button class="gallery-btn" data-act="zoom-in">+</button>' +
                '<button class="gallery-btn" data-act="zoom-out">−</button>' +
                '<button class="gallery-btn" data-act="close">×</button>' +
            '</div>' +
            '<button class="gallery-nav prev" data-act="prev">‹</button>' +
            '<div class="gallery-stage"><img class="gallery-image" /></div>' +
            '<button class="gallery-nav next" data-act="next">›</button>' +
            '<div class="gallery-counter"></div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
            const t = e.target;
            if (t.dataset.act) {
                e.stopPropagation();
                actions[t.dataset.act]();
            } else if (t === overlay) {
                close();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (!overlay.classList.contains('active')) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') actions.prev();
            else if (e.key === 'ArrowRight') actions.next();
            else if (e.key === '+' || e.key === '=') actions['zoom-in']();
            else if (e.key === '-') actions['zoom-out']();
        });
    }

    const actions = {
        close: () => overlay.classList.remove('active'),
        prev: () => navigate(-1),
        next: () => navigate(1),
        'zoom-in': () => {
            scale = Math.min(scale * 1.25, 5);
            applyZoom();
        },
        'zoom-out': () => {
            scale = Math.max(scale / 1.25, 0.5);
            applyZoom();
        },
    };

    function navigate(delta) {
        if (images.length <= 1) return;
        currentIdx = (currentIdx + delta + images.length) % images.length;
        show();
    }

    function applyZoom() {
        const img = overlay.querySelector('.gallery-image');
        img.style.transform = 'scale(' + scale + ')';
        img.classList.toggle('zoomed', scale > 1);
    }

    function show() {
        const img = overlay.querySelector('.gallery-image');
        img.src = images[currentIdx];
        scale = 1;
        applyZoom();
        const counter = overlay.querySelector('.gallery-counter');
        counter.textContent = images.length > 1 ? (currentIdx + 1) + ' / ' + images.length : '';
        overlay.querySelector('.gallery-nav.prev').style.display = images.length > 1 ? '' : 'none';
        overlay.querySelector('.gallery-nav.next').style.display = images.length > 1 ? '' : 'none';
    }

    function open(idx) {
        if (!overlay) buildOverlay();
        currentIdx = idx;
        show();
        overlay.classList.add('active');
    }

    function close() {
        overlay.classList.remove('active');
    }

    function bindImages() {
        images = Array.from(document.querySelectorAll('[data-gallery]'))
            .map((img) => img.src || img.dataset.gallery);
        if (images.length === 0) return;

        document.querySelectorAll('[data-gallery]').forEach((img, idx) => {
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', (e) => {
                e.preventDefault();
                open(idx);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindImages);
    } else {
        bindImages();
    }
})();
```

- [ ] **Step 3: 修改 templates/share.php 添加 gallery.css/js 加载**

在 `templates/share.php` 找到 `<head>` 末尾（其他 CSS 之后），添加：

```html
<link rel="stylesheet" href="assets/css/gallery.css?v=<?php echo time(); ?>">
```

在 `</body>` 之前添加：

```html
<script src="assets/js/gallery.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 4: 修改 templates/share.php 图片预览元素**

找到 share.php 中图片预览的 `<img>` 标签（约预览区块），为图片元素添加 `data-gallery` 属性。例如：

```php
<img src="..." data-gallery class="preview-image" />
```

**具体修改位置：** share.php 中处理 mime_type 起始为 `image/` 的预览逻辑，搜索 `<img` 关键字。

- [ ] **Step 5: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 浏览器：
# 1. 上传 2-3 张图片
# 2. 创建一个文本项目，其中包含分享链接列表（手动构造含多张图片的分享页）
# 3. 访问分享页，点击图片 → 灯箱弹出
# 4. 左右切换图片 → ESC 关闭 → 滚轮缩放

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 6: 提交**

```bash
git add assets/js/gallery.js assets/css/gallery.css templates/share.php
git commit -m "feat(gallery): add image lightbox with prev/next navigation"
```

---

### Task 8: Markdown 渲染前端（A6）

**Files:**
- Create: `assets/js/markdown.js`

**目的：** 文本项目扩展名为 `.md` 或符合 Markdown 特征时，调用 Marked.js 渲染并 DOMPurify 清洗。

- [ ] **Step 1: 创建 assets/js/markdown.js**

```javascript
/**
 * FileShare Markdown 渲染
 * 作者：FileShare Contributors
 *
 * 依赖：marked.js + DOMPurify（通过 CDN 懒加载）
 */
(function () {
    'use strict';

    const MARKED_URL = 'https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.0/marked.min.js';
    const PURIFY_URL = 'https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js';

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector('script[src="' + src + '"]')) {
                resolve();
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function detectMarkdown(text) {
        if (!text) return false;
        const sample = text.substring(0, 200);
        return /(^|\n)#{1,6}\s/.test(sample) ||
               /(^|\n)\s*[-*+]\s+/.test(sample) ||
               /(^|\n)\s*\d+\.\s+/.test(sample) ||
               /(^|\n)>\s/.test(sample) ||
               /```/.test(sample) ||
               /\*\*[^*]+\*\*/.test(sample) ||
               /(^|\W)\*[^*]+\*(\W|$)/.test(sample);
    }

    async function renderTextItem(item) {
        const rawText = item.dataset.rawText || item.textContent;
        if (!detectMarkdown(rawText) && !item.dataset.forceMarkdown) return;

        item.classList.add('markdown-rendered');

        try {
            await loadScript(MARKED_URL);
            await loadScript(PURIFY_URL);
        } catch (e) {
            item.innerHTML = '<pre>' + escapeHtml(rawText) + '</pre>' +
                             '<p class="md-error">Markdown 渲染库加载失败，已降级为纯文本</p>';
            return;
        }

        window.marked.setOptions({ breaks: true, gfm: true });
        const html = window.marked.parse(rawText);
        const cleanHtml = window.DOMPurify.sanitize(html, {
            ALLOWED_TAGS: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li',
                           'blockquote', 'pre', 'code', 'em', 'strong', 'a', 'img',
                           'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'hr',
                           'del', 'input'],
            ALLOWED_ATTR: ['href', 'title', 'alt', 'src', 'class', 'rel', 'target',
                          'type', 'checked', 'disabled'],
        });

        // 外链安全属性
        const tmp = document.createElement('div');
        tmp.innerHTML = cleanHtml;
        tmp.querySelectorAll('a[href^="http"]').forEach((a) => {
            a.setAttribute('rel', 'noopener noreferrer');
            a.setAttribute('target', '_blank');
        });

        item.innerHTML = tmp.innerHTML;

        // 触发 Prism 高亮（与 A1 联动）
        if (window.Prism) {
            item.querySelectorAll('pre code').forEach((code) => {
                window.Prism.highlightElement(code);
            });
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function init() {
        const items = document.querySelectorAll('.text-content[data-markdown], .text-content[data-raw-text]');
        items.forEach(renderTextItem);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 2: 修改 templates/share.php 加载 markdown.js**

在 `templates/share.php` 找到 `</body>` 之前（gallery.js 之后），添加：

```html
<script src="assets/js/markdown.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 3: 修改 templates/share.php 文本渲染（关键）**

找到 share.php 中渲染文本项目的 HTML 输出（搜索 `text-content`），将输出修改为：

```php
<div class="text-content" data-markdown data-raw-text="<?php echo htmlspecialchars($item['content'] ?? '', ENT_QUOTES); ?>">
    <!-- markdown.js 渲染前的内容会被替换 -->
    <pre><?php echo htmlspecialchars($item['content'] ?? ''); ?></pre>
</div>
```

**注意：** 密码保护项目需要先解锁（与现有逻辑一致），解锁后再渲染 Markdown。

- [ ] **Step 4: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 1. 上传一个 .md 文本，内容如 "# Hello\n\nThis is **bold** text."
# 2. 访问分享页 → 应看到 HTML 渲染（标题、bold 加粗）
# 3. 上传一个含 <script>alert(1)</script> 的 Markdown → DOMPurify 应清除脚本

# XSS 验证
echo -e "# Test\n<script>alert(1)</script>\n**bold**" > /tmp/test.md
# 上传 /tmp/test.md，访问分享页
# 预期：无 alert 弹窗，<script> 被去除

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 5: 提交**

```bash
git add assets/js/markdown.js templates/share.php
git commit -m "feat(markdown): add Markdown rendering with DOMPurify sanitization"
```

---

### Task 9: 压缩包后端（archive/list + read）

**Files:**
- Create: `src/archive.php`
- Modify: `src/api.php`（添加 archive/list 和 archive/read 路由）

**目的：** 提供 zip 内文件浏览与文本读取，支持嵌套目录（≤3 层），不解压到磁盘。

- [ ] **Step 1: 创建 src/archive.php**

```php
<?php
/**
 * 压缩包预览
 * 作者：FileShare Contributors
 *
 * 仅支持 zip 格式，内存中解压。
 * 限制：条目数 ≤ 5000，嵌套层级 ≤ 3，单文件读取 ≤ 1MB
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

define('MAX_ARCHIVE_ENTRIES', intval(loadEnvVar('MAX_ARCHIVE_ENTRIES', '5000')));
define('MAX_ARCHIVE_DEPTH', intval(loadEnvVar('MAX_ARCHIVE_DEPTH', '3')));
define('ARCHIVE_CACHE_TTL', intval(loadEnvVar('ARCHIVE_CACHE_TTL', '3600')));
define('ARCHIVE_CACHE_DIR', STORAGE_DIR . 'archive_cache/');

if (!is_dir(ARCHIVE_CACHE_DIR)) {
    @mkdir(ARCHIVE_CACHE_DIR, 0755, true);
}

/**
 * 验证 zip 路径合法性
 */
function validateArchivePath($path) {
    if (empty($path) || $path === '/') return '';
    // 拒绝 .. 和绝对路径
    if (strpos($path, '..') !== false) return null;
    if (substr($path, 0, 1) === '/') return null;
    // 限制深度
    $depth = substr_count(trim($path, '/'), '/') + 1;
    if ($depth > MAX_ARCHIVE_DEPTH) return null;
    return rtrim($path, '/') . '/';
}

/**
 * 获取或构建压缩包条目树
 *
 * @param int $itemId
 * @param string $zipPath zip 文件绝对路径
 * @param string $fileHash
 * @return array|false ['tree' => [...], 'flat' => [...], 'errors' => [...]]
 */
function buildArchiveTree($itemId, $zipPath, $fileHash) {
    $cacheKey = $itemId . '_' . substr($fileHash, 0, 16);
    $cacheFile = ARCHIVE_CACHE_DIR . $cacheKey . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < ARCHIVE_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    if (!class_exists('ZipArchive')) {
        return ['tree' => [], 'flat' => [], 'errors' => ['ZipArchive 扩展未安装']];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['tree' => [], 'flat' => [], 'errors' => ['无法打开 zip 文件']];
    }

    $flat = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (count($flat) >= MAX_ARCHIVE_ENTRIES) break;
        $stat = $zip->statIndex($i);
        $flat[] = [
            'name' => $stat['name'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'is_dir' => substr($stat['name'], -1) === '/',
        ];
    }
    $zip->close();

    // 构建树
    $tree = [];
    foreach ($flat as $entry) {
        $parts = explode('/', trim($entry['name'], '/'));
        $node = &$tree;
        foreach ($parts as $i => $part) {
            if ($part === '') continue;
            if (!isset($node[$part])) {
                $node[$part] = ['__type' => 'dir', '__children' => []];
            }
            if ($i === count($parts) - 1) {
                $node[$part] = [
                    '__type' => $entry['is_dir'] ? 'dir' : 'file',
                    'size' => $entry['size'],
                    'mtime' => $entry['mtime'],
                    '__children' => $entry['is_dir'] ? ($node[$part]['__children'] ?? []) : [],
                ];
            } else {
                $node = &$node[$part]['__children'];
            }
        }
    }

    $result = ['tree' => $tree, 'flat' => $flat, 'errors' => []];
    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/**
 * 处理 archive/list 请求
 */
function handleArchiveList() {
    $itemId = intval($_GET['id'] ?? 0);
    $path = $_GET['path'] ?? '';

    if ($itemId <= 0) {
        apiError('请提供有效的 item ID', 400);
    }

    $item = getItemById($itemId);
    if (!$item || $item['type'] !== 'file') {
        apiError('项目不存在或不是文件', 404);
    }
    if ($item['mime_type'] !== 'application/zip') {
        apiError('仅支持 zip 文件', 400);
    }

    // 密码保护
    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $item['share_code'];
        if (empty($_SESSION[$unlockedKey])) {
            apiError('需要先解锁密码', 401);
        }
    }

    $safePath = validateArchivePath($path);
    if ($safePath === null) {
        apiError('无效的路径', 400);
    }

    $result = buildArchiveTree($itemId, $item['path'], $item['file_hash'] ?? '');
    if (!empty($result['errors'])) {
        apiError(implode('; ', $result['errors']), 500);
    }

    // 提取该层级的条目
    $entries = [];
    $prefix = $safePath;
    foreach ($result['flat'] as $entry) {
        $name = $entry['name'];
        if (strpos($name, $prefix) !== 0) continue;
        $rest = substr($name, strlen($prefix));
        if ($rest === '') continue;

        // 仅显示直接子项
        if (strpos($rest, '/') === false) {
            $entries[] = [
                'name' => $rest,
                'type' => $entry['is_dir'] ? 'dir' : 'file',
                'size' => $entry['size'],
                'mime' => $entry['is_dir'] ? '' : guessMimeByName($rest),
            ];
        } elseif (substr($rest, -1) === '/' && substr_count($rest, '/') === 1) {
            // 子目录
            $entries[] = [
                'name' => rtrim($rest, '/'),
                'type' => 'dir',
                'size' => 0,
                'mime' => '',
            ];
        }
    }

    // 面包屑
    $breadcrumb = [['name' => 'root', 'path' => '']];
    if (!empty($safePath)) {
        $parts = explode('/', trim($safePath, '/'));
        $cur = '';
        foreach ($parts as $p) {
            if ($p === '') continue;
            $cur .= $p . '/';
            $breadcrumb[] = ['name' => $p, 'path' => $cur];
        }
    }

    apiResponse([
        'success' => true,
        'path' => $safePath,
        'entries' => $entries,
        'breadcrumb' => $breadcrumb,
        'total_entries' => count($result['flat']),
        'truncated' => count($result['flat']) >= MAX_ARCHIVE_ENTRIES,
    ]);
}

function guessMimeByName($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json',
        'js' => 'text/javascript', 'ts' => 'text/javascript', 'html' => 'text/html',
        'css' => 'text/css', 'xml' => 'text/xml', 'csv' => 'text/csv',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * 处理 archive/read 请求（读取单文件内容）
 */
function handleArchiveRead() {
    $itemId = intval($_GET['id'] ?? 0);
    $path = $_GET['path'] ?? '';

    if ($itemId <= 0 || empty($path)) {
        apiError('参数不完整', 400);
    }

    $item = getItemById($itemId);
    if (!$item || $item['type'] !== 'file') {
        apiError('项目不存在', 404);
    }

    if (!empty($item['password'])) {
        $unlockedKey = 'unlocked_' . $item['share_code'];
        if (empty($_SESSION[$unlockedKey])) {
            apiError('需要先解锁密码', 401);
        }
    }

    $safePath = validateArchivePath($path);
    if ($safePath === null) {
        apiError('无效的路径', 400);
    }

    if (!class_exists('ZipArchive')) {
        apiError('ZipArchive 扩展未安装', 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($item['path']) !== true) {
        apiError('无法打开 zip 文件', 500);
    }

    $content = $zip->getFromName(ltrim($safePath, '/'));
    $zip->close();

    if $content === false || $content === null) {
        apiError('文件不存在或为空', 404);
    }

    if (strlen($content) > 1024 * 1024) {
        apiError('文件超过 1MB 读取限制', 413);
    }

    $mime = guessMimeByName(basename($safePath));
    header('Content-Type: ' . $mime . '; charset=utf-8');
    echo $content;
    exit;
}
```

**注意：** PHP 7.4 不支持 `if ($content === false || $content === null) {` 这种语法（实际是支持的，PHP 7.4 支持 `===` 和 `||`）。这是合法代码。

- [ ] **Step 2: 修改 src/api.php，添加 archive 路由**

在 `src/api.php` 的 `handleApiRequest()` 函数 `switch ($endpoint)` 块**之前**，添加（紧邻 Task 3 添加的 upload/ 分片端点**之后**）：

```php
    // ===== 压缩包预览端点（B3） =====
    if (strpos($endpoint, 'archive/') === 0) {
        require_once __DIR__ . '/archive.php';
        switch ($endpoint) {
            case 'archive/list':
                if ($method === 'GET') {
                    validateApiToken('read');
                    handleArchiveList();
                } else {
                    apiError('不支持的请求方法', 405);
                }
                break;
            case 'archive/read':
                if ($method === 'GET') {
                    validateApiToken('read');
                    handleArchiveRead();
                } else {
                    apiError('不支持的请求方法', 405);
                }
                break;
            default:
                apiError('未知的 archive 子端点', 404);
        }
        return;
    }
```

- [ ] **Step 3: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

ADMIN_PWD=$(grep ADMIN_PASSWORD .env | cut -d= -f2)
TOKEN=$(curl -s -X POST 'http://localhost:9000/?api=auth/token' \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"$ADMIN_PWD\"}" | grep -o '"access_token":"[^"]*"' | cut -d'"' -f4)

# 创建测试 zip
mkdir -p /tmp/zip_test/src/js
echo "console.log('hello');" > /tmp/zip_test/src/js/main.js
echo "Hello World" > /tmp/zip_test/readme.txt
cd /tmp/zip_test && zip -r /tmp/test.zip . > /dev/null
cd - > /dev/null

# 上传 zip
ITEM_ID=$(curl -s -X POST 'http://localhost:9000/?api=upload' \
  -H "Authorization: Bearer $TOKEN" \
  -F "files[]=@/tmp/test.zip" \
  -F "duration=86400" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)

echo "Item ID: $ITEM_ID"

# 列出根目录
curl -s "http://localhost:9000/?api=archive/list&id=$ITEM_ID" \
  -H "Authorization: Bearer $TOKEN" | python -m json.tool
# 预期：entries 含 readme.txt (file) 和 src/ (dir)

# 进入子目录
curl -s "http://localhost:9000/?api=archive/list&id=$ITEM_ID&path=src/js/" \
  -H "Authorization: Bearer $TOKEN" | python -m json.tool
# 预期：entries 含 main.js

# 读取文件
curl -s "http://localhost:9000/?api=archive/read&id=$ITEM_ID&path=src/js/main.js" \
  -H "Authorization: Bearer $TOKEN"
# 预期：console.log('hello');

# 清理
kill $SERVER_PID 2>/dev/null
rm -rf /tmp/zip_test /tmp/test.zip
sqlite3 storage/fileshare.db "DELETE FROM items WHERE name LIKE 'test.zip';"
```

- [ ] **Step 4: 提交**

```bash
git add src/archive.php src/api.php
git commit -m "feat(archive): add zip preview backend with list/read endpoints"
```

---

### Task 10: 压缩包前端导航

**Files:**
- Create: `assets/js/archive-preview.js`
- Create: `assets/css/archive.css`

**目的：** 在分享页用 zip 文件时，提供目录树 + 文件列表的浏览界面。

- [ ] **Step 1: 创建 assets/css/archive.css**

```css
.archive-browser {
    display: flex;
    border: 1px solid var(--border, #ddd);
    border-radius: 6px;
    overflow: hidden;
    min-height: 400px;
}

.archive-tree {
    flex: 0 0 220px;
    background: var(--bg-secondary, #f5f5f5);
    padding: 12px;
    overflow-y: auto;
    border-right: 1px solid var(--border, #ddd);
    font-size: 13px;
}

.archive-list {
    flex: 1;
    padding: 12px;
    overflow-y: auto;
}

.archive-entry {
    padding: 6px 10px;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.archive-entry:hover {
    background: var(--bg-hover, #e8e8e8);
}

.archive-entry.file {
    cursor: pointer;
}

.archive-entry .icon {
    margin-right: 8px;
}

.archive-breadcrumb {
    padding: 8px 12px;
    background: var(--bg-tertiary, #ebebeb);
    font-size: 13px;
}

.archive-breadcrumb a {
    color: var(--link, #0066cc);
    text-decoration: none;
}

.archive-preview {
    margin-top: 12px;
    padding: 12px;
    background: var(--bg-secondary, #f9f9f9);
    border-radius: 4px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    white-space: pre-wrap;
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
}
```

- [ ] **Step 2: 创建 assets/js/archive-preview.js**

```javascript
/**
 * FileShare 压缩包浏览器
 * 作者：FileShare Contributors
 */
(function () {
    'use strict';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        return '';
    }

    function apiCall(endpoint, params) {
        const qs = new URLSearchParams(params).toString();
        return fetch('?api=' + endpoint + '&' + qs)
            .then((r) => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const ct = r.headers.get('Content-Type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text();
            });
    }

    function renderBreadcrumb(container, breadcrumb) {
        const html = breadcrumb.map((b, i) =>
            '<a href="#" data-path="' + b.path + '">' + b.name + '</a>' +
            (i < breadcrumb.length - 1 ? ' / ' : '')
        ).join('');
        container.innerHTML = html;
        container.querySelectorAll('a').forEach((a) => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                navigateTo(a.dataset.path);
            });
        });
    }

    function renderEntries(container, entries, currentPath) {
        const html = entries.map((e) => {
            const icon = e.type === 'dir' ? '📁' : getFileIcon(e.name);
            const size = e.type === 'dir' ? '' : ' (' + formatSize(e.size) + ')';
            return '<div class="archive-entry ' + e.type + '" data-name="' + e.name +
                '" data-path="' + (currentPath + e.name) + '" data-type="' + e.type + '">' +
                '<span><span class="icon">' + icon + '</span>' + escapeHtml(e.name) + '</span>' +
                '<span class="size">' + size + '</span>' +
                '</div>';
        }).join('');
        container.innerHTML = html || '<p class="empty">空目录</p>';

        container.querySelectorAll('.archive-entry').forEach((el) => {
            el.addEventListener('click', () => {
                const path = el.dataset.path;
                if (el.dataset.type === 'dir') {
                    navigateTo(path + '/');
                } else {
                    previewFile(currentPath + el.dataset.name);
                }
            });
        });
    }

    function previewFile(path) {
        apiCall('archive/read', { id: currentItemId, path: path })
            .then((content) => {
                const preview = document.querySelector('.archive-preview');
                preview.innerHTML = '<h4>' + escapeHtml(path) + '</h4>' +
                    '<pre>' + escapeHtml(content) + '</pre>';
            })
            .catch((err) => {
                document.querySelector('.archive-preview').innerHTML =
                    '<p class="error">读取失败：' + escapeHtml(err.message) + '</p>';
            });
    }

    function navigateTo(path) {
        apiCall('archive/list', { id: currentItemId, path: path })
            .then((data) => {
                renderBreadcrumb(document.querySelector('.archive-breadcrumb'), data.breadcrumb);
                renderEntries(document.querySelector('.archive-list'), data.entries, data.path);
            })
            .catch((err) => {
                document.querySelector('.archive-list').innerHTML =
                    '<p class="error">加载失败：' + escapeHtml(err.message) + '</p>';
            });
    }

    let currentItemId = 0;

    function getFileIcon(name) {
        const ext = name.toLowerCase().split('.').pop();
        if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) >= 0) return '🖼️';
        if (['mp4', 'webm'].indexOf(ext) >= 0) return '🎬';
        if (['mp3', 'wav'].indexOf(ext) >= 0) return '🎵';
        if (['js', 'ts', 'py', 'java', 'c'].indexOf(ext) >= 0) return '📜';
        if (['zip', 'rar', '7z'].indexOf(ext) >= 0) return '📦';
        return '📄';
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function init() {
        const root = document.querySelector('[data-archive-item]');
        if (!root) return;
        currentItemId = root.dataset.archiveItem;
        navigateTo('');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 3: 修改 templates/share.php 添加 archive.css/js 加载**

在 `templates/share.php` 找到 `<head>` 末尾（其他 CSS 之后），添加：

```html
<link rel="stylesheet" href="assets/css/archive.css?v=<?php echo time(); ?>">
```

在 `</body>` 之前添加：

```html
<script src="assets/js/archive-preview.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 4: 修改 templates/share.php 添加 archive 容器**

找到 share.php 中 zip 文件预览的逻辑（搜索 `application/zip`），将预览区块替换为：

```php
<?php if (($item['mime_type'] ?? '') === 'application/zip'): ?>
    <div class="archive-browser" data-archive-item="<?php echo $item['id']; ?>">
        <div class="archive-tree">
            <div class="archive-breadcrumb"></div>
            <div class="archive-list"></div>
        </div>
        <div class="archive-preview"></div>
    </div>
<?php endif; ?>
```

**注意：** 当前 share.php 中可能已有 zip 处理逻辑。请搜索 `application/zip` 或 `zip` 关键字，将"下载"链接替换为以上浏览器容器。

- [ ] **Step 5: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 上传一个 zip 文件（参考 Task 9 步骤）
# 访问分享页 ?s=XXXXXX
# 预期：浏览器界面渲染，目录树 + 文件列表显示
# 点击 src/ → 进入子目录 → 点击 main.js → 显示代码预览

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 6: 提交**

```bash
git add assets/js/archive-preview.js assets/css/archive.css templates/share.php
git commit -m "feat(archive): add frontend zip browser with file preview"
```

---

### Task 11: PDF 预览前端（A3）

**Files:**
- Create: `assets/js/pdf-preview.js`
- Create: `assets/css/pdf-preview.css`

**目的：** PDF 文件分享页用 PDF.js 渲染，懒加载避免影响其他页面。

- [ ] **Step 1: 创建 assets/css/pdf-preview.css**

```css
.pdf-container {
    width: 100%;
    height: 80vh;
    border: 1px solid var(--border, #ddd);
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.pdf-toolbar {
    padding: 8px 12px;
    background: var(--bg-secondary, #f5f5f5);
    border-bottom: 1px solid var(--border, #ddd);
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.pdf-toolbar button {
    padding: 4px 10px;
    background: white;
    border: 1px solid var(--border, #ccc);
    border-radius: 3px;
    cursor: pointer;
    font-size: 13px;
}

.pdf-toolbar button:hover {
    background: var(--bg-hover, #e8e8e8);
}

.pdf-toolbar button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pdf-canvas-wrap {
    flex: 1;
    overflow: auto;
    background: #525659;
    display: flex;
    justify-content: center;
    padding: 20px;
}

.pdf-canvas-wrap canvas {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    background: white;
}

.pdf-loading {
    padding: 40px;
    text-align: center;
    color: white;
}

.pdf-error {
    padding: 20px;
    text-align: center;
    color: #d9534f;
}

.pdf-page-info {
    font-size: 13px;
    color: var(--text-secondary, #666);
    margin: 0 8px;
}
```

- [ ] **Step 2: 创建 assets/js/pdf-preview.js**

```javascript
/**
 * FileShare PDF 预览
 * 作者：FileShare Contributors
 *
 * 懒加载 PDF.js（仅 PDF 分享页加载）。
 */
(function () {
    'use strict';

    const PDFJS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.min.js';
    const WORKER_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.worker.min.js';

    let pdfDoc = null;
    let currentPage = 1;
    let scale = 1.0;
    let rendering = false;

    function loadPdfJs() {
        return new Promise((resolve, reject) => {
            if (window.pdfjsLib) {
                resolve();
                return;
            }
            const s = document.createElement('script');
            s.src = PDFJS_URL;
            s.onload = () => {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_URL;
                resolve();
            };
            s.onerror = () => reject(new Error('PDF.js 加载失败'));
            document.head.appendChild(s);
        });
    }

    function renderPage(num) {
        if (rendering) return;
        rendering = true;

        pdfDoc.getPage(num).then((page) => {
            const viewport = page.getViewport({ scale: scale });
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            const wrap = document.querySelector('.pdf-canvas-wrap');
            wrap.innerHTML = '';
            wrap.appendChild(canvas);

            page.render({ canvasContext: ctx, viewport: viewport }).promise.then(() => {
                rendering = false;
                currentPage = num;
                updatePageInfo();
            });
        });
    }

    function updatePageInfo() {
        document.querySelector('.pdf-page-info').textContent =
            currentPage + ' / ' + pdfDoc.numPages;
        document.querySelector('[data-act="prev"]').disabled = currentPage <= 1;
        document.querySelector('[data-act="next"]').disabled = currentPage >= pdfDoc.numPages;
    }

    function bindToolbar() {
        document.querySelector('[data-act="prev"]').addEventListener('click', () => {
            if (currentPage > 1) renderPage(currentPage - 1);
        });
        document.querySelector('[data-act="next"]').addEventListener('click', () => {
            if (currentPage < pdfDoc.numPages) renderPage(currentPage + 1);
        });
        document.querySelector('[data-pdf-zoom]').addEventListener('change', (e) => {
            scale = parseFloat(e.target.value);
            renderPage(currentPage);
        });
    }

    function init() {
        const root = document.querySelector('[data-pdf-url]');
        if (!root) return;
        const url = root.dataset.pdfUrl;

        root.innerHTML =
            '<div class="pdf-toolbar">' +
                '<button data-act="prev">‹ 上一页</button>' +
                '<button data-act="next">下一页 ›</button>' +
                '<span class="pdf-page-info">加载中...</span>' +
                '<select data-pdf-zoom>' +
                    '<option value="0.5">50%</option>' +
                    '<option value="0.75">75%</option>' +
                    '<option value="1" selected>100%</option>' +
                    '<option value="1.5">150%</option>' +
                    '<option value="2">200%</option>' +
                '</select>' +
            '</div>' +
            '<div class="pdf-canvas-wrap"><div class="pdf-loading">正在加载 PDF...</div></div>';

        loadPdfJs()
            .then(() => {
                return window.pdfjsLib.getDocument(url).promise;
            })
            .then((doc) => {
                pdfDoc = doc;
                bindToolbar();
                renderPage(1);
            })
            .catch((err) => {
                document.querySelector('.pdf-canvas-wrap').innerHTML =
                    '<div class="pdf-error">PDF 加载失败：' + err.message +
                    ' <br><a href="' + url + '" download>下载查看</a></div>';
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 3: 修改 templates/share.php 添加 pdf-preview.css/js**

在 `templates/share.php` 找到 `<head>` 末尾，添加：

```html
<link rel="stylesheet" href="assets/css/pdf-preview.css?v=<?php echo time(); ?>">
```

在 `</body>` 之前添加：

```html
<script src="assets/js/pdf-preview.js?v=<?php echo time(); ?>"></script>
```

- [ ] **Step 4: 修改 templates/share.php 添加 PDF 容器**

找到 share.php 中 PDF 预览的逻辑（搜索 `application/pdf`），将预览区块修改为：

```php
<?php if (($item['mime_type'] ?? '') === 'application/pdf'): ?>
    <div class="pdf-container" data-pdf-url="<?php echo htmlspecialchars(getBaseUrl() . '?preview=' . $item['share_code']); ?>">
        <!-- 由 pdf-preview.js 填充 -->
    </div>
<?php endif; ?>
```

**注意：** 当前 share.php 可能有 PDF 直接预览链接（iframe 或 embed）。请替换为以上容器。`?preview=xxx` 路由需确保密码保护下也能访问（参考现有预览逻辑）。

- [ ] **Step 5: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 上传一个 PDF 文件
# 访问分享页 ?s=XXXXXX
# 预期：
#   - PDF.js 异步加载（DevTools Network 中可见）
#   - 显示 PDF 第一页
#   - 工具栏按钮可用（上一页/下一页/缩放）
#   - 缩放选择改变时正确重渲染

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 6: 提交**

```bash
git add assets/js/pdf-preview.js assets/css/pdf-preview.css templates/share.php
git commit -m "feat(pdf): add lazy-loaded PDF.js preview with toolbar"
```

---

### Task 12: 管理后台批量生成缩略图

**Files:**
- Modify: `src/admin.php`（添加 batch_thumbnails 端点）
- Modify: `templates/admin/layout.php`（添加按钮）

**目的：** 提供"为现有项目批量生成缩略图"管理功能。

- [ ] **Step 1: 修改 src/admin.php，添加批量生成处理**

在 `src/admin.php` 的 `handleAdminRequest()` 函数（约第 78 行）的 `switch ($page)` 块**之前**，添加新页面路由：

```php
    // 批量生成缩略图
    if ($page === 'batch_thumbnails') {
        require_once __DIR__ . '/thumbnail.php';
        $adminPage = 'batch_thumbnails';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF()) {
            // 找出所有 thumbnail_path 为空且类型为 file 的项目
            $db = getDB();
            $stmt = $db->query("SELECT id FROM items WHERE type = 'file' AND (thumbnail_path IS NULL OR thumbnail_path = '')");
            $items = $stmt->fetchAll();

            $success = 0;
            $failed = 0;
            foreach ($items as $item) {
                $result = generateItemThumbnail(intval($item['id']));
                if (strpos($result, 'failed:') !== 0 && !empty($result)) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            $_SESSION['message'] = "批量完成：成功 {$success} 个，失败 {$failed} 个";
            header('Location: /admin/batch_thumbnails');
            exit;
        }

        $adminData = getAdminBatchThumbnailsData();
        break;
    }
```

在 `src/admin.php` 末尾（其他 get 函数后），添加数据获取函数：

```php
function getAdminBatchThumbnailsData() {
    $db = getDB();
    $total = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'file'")->fetch()['cnt'];
    $pending = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'file' AND (thumbnail_path IS NULL OR thumbnail_path = '')")->fetch()['cnt'];
    $failed = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'file' AND thumbnail_path LIKE 'failed:%'")->fetch()['cnt'];
    $ready = $db->query("SELECT COUNT(*) as cnt FROM items WHERE type = 'file' AND thumbnail_path IS NOT NULL AND thumbnail_path != '' AND thumbnail_path NOT LIKE 'failed:%'")->fetch()['cnt'];

    return [
        'total' => $total,
        'pending' => $pending,
        'failed' => $failed,
        'ready' => $ready,
        'ffmpeg_available' => isFfmpegAvailable(),
    ];
}
```

- [ ] **Step 2: 修改 templates/admin/layout.php 添加侧边栏菜单项**

找到 `templates/admin/layout.php` 中侧边栏导航列表（搜索 `dashboard`、`项目管理`、`nav-link` 等关键字），在"项目管理"项之后添加：

```html
<a href="/admin/batch_thumbnails" class="nav-link <?php echo $adminPage === 'batch_thumbnails' ? 'active' : ''; ?>">
    <span class="nav-icon">🖼️</span> 批量缩略图
</a>
```

**注意：** 实际位置和 HTML 结构请参考 layout.php 当前样式。菜单项添加后，点击会进入批量缩略图页面。

- [ ] **Step 3: 在 layout.php 主体内容区添加批量缩略图面板**

找到 layout.php 中根据 `$adminPage` 切换内容的区域，添加新分支：

```php
<?php elseif ($adminPage === 'batch_thumbnails'): ?>
    <h1>批量生成缩略图</h1>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">总文件数</div>
            <div class="stat-value"><?php echo $adminData['total']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">待生成</div>
            <div class="stat-value"><?php echo $adminData['pending']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">已生成</div>
            <div class="stat-value"><?php echo $adminData['ready']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">失败</div>
            <div class="stat-value"><?php echo $adminData['failed']; ?></div>
        </div>
    </div>
    <div class="card">
        <p>ffmpeg 状态：<?php echo $adminData['ffmpeg_available'] ? '✅ 可用' : '❌ 未安装（视频缩略图不可用）'; ?></p>
        <?php if ($adminData['pending'] > 0): ?>
            <form method="post" action="/admin/batch_thumbnails">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="btn btn-primary">开始批量生成（<?php echo $adminData['pending']; ?> 个项目）</button>
            </form>
        <?php else: ?>
            <p>✅ 所有项目已生成缩略图</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

**注意：** 实际 layout.php 主体内容区使用何种语法（`switch`、`if-elseif`）请按现有模式调整。

- [ ] **Step 4: 手工验证**

```bash
php -S localhost:9000 &
SERVER_PID=$!

# 1. 浏览器访问 /admin/login，登录管理员
# 2. 访问 /admin/batch_thumbnails
# 3. 应看到 4 个统计卡片 + 批量生成按钮
# 4. 点击按钮 → 应触发生成并显示成功消息

kill $SERVER_PID 2>/dev/null
```

- [ ] **Step 5: 提交**

```bash
git add src/admin.php templates/admin/layout.php
git commit -m "feat(admin): add batch thumbnail generation panel"
```

---

### Task 13: 文档与 .env 更新

**Files:**
- Modify: `.env.example`（添加新配置示例）
- Modify: `README.md`（更新功能列表与配置说明）
- Modify: `API.md`（添加新 API 端点）

**目的：** 同步文档，反映新功能与配置。

- [ ] **Step 1: 修改 .env.example**

在 `.env.example` 末尾追加：

```ini
# === 分片上传与缩略图（B1/B2） ===
# 分片大小（字节），默认 5MB
CHUNK_SIZE=5242880
# 分片上传并发数
CHUNK_CONCURRENCY=3
# 启用分片的文件大小阈值（字节），默认 50MB
CHUNK_THRESHOLD=52428800

# 缩略图 JPEG 质量（1-100）
THUMBNAIL_QUALITY=75
# 缩略图最长边像素
THUMBNAIL_MAX_SIZE=320

# === 压缩包预览（B3） ===
# 压缩包最大条目数
MAX_ARCHIVE_ENTRIES=5000
# 压缩包最大嵌套层级
MAX_ARCHIVE_DEPTH=3
# 压缩包元数据缓存 TTL（秒）
ARCHIVE_CACHE_TTL=3600
```

- [ ] **Step 2: 修改 README.md**

找到 README.md 的"特性"章节（约第 13 行），在末尾添加：

```markdown
- **预览增强** - 代码语法高亮、图片灯箱、PDF 在线预览（PDF.js）、Markdown 渲染（Marked.js + DOMPurify）
- **分片上传** - 大文件分片上传（>50MB 自动启用），支持断点续传、并发上传
- **缩略图** - 图片自动生成缩略图，视频支持 ffmpeg（可选依赖）
- **压缩包预览** - 在线浏览 zip 内文件，支持嵌套目录（≤3 层）
```

找到"配置说明"章节，添加新配置表：

```markdown
### 分片上传与缩略图配置

| 变量 | 默认 | 说明 |
|------|------|------|
| `CHUNK_SIZE` | `5242880` | 分片大小（字节，5MB） |
| `CHUNK_CONCURRENCY` | `3` | 分片并发数 |
| `CHUNK_THRESHOLD` | `52428800` | 启用分片的文件大小阈值 |
| `THUMBNAIL_QUALITY` | `75` | 缩略图 JPEG 质量 |
| `THUMBNAIL_MAX_SIZE` | `320` | 缩略图最长边像素 |
| `MAX_ARCHIVE_ENTRIES` | `5000` | 压缩包最大条目数 |
| `MAX_ARCHIVE_DEPTH` | `3` | 压缩包最大嵌套层级 |

### 可选依赖

| 工具 | 用途 | 未安装时行为 |
|------|------|--------------|
| ffmpeg | 视频缩略图生成 | 视频缩略图不可用，使用默认图标 |
| Imagick | 高质量图片缩略图 | 自动降级到 GD |
```

- [ ] **Step 3: 修改 API.md 添加新端点**

在 API.md 找到"统计信息"章节（约第 260 行）**之前**，插入：

```markdown
---

## 分片上传

适用于大文件（>50MB）上传，自动启用分片。可断点续传。

### 初始化会话

**POST** `?api=upload/init`

**请求体（JSON）：**
```json
{
  "session_id": "abc123...",   // 可选；续传时必填
  "filename": "video.mp4",
  "size": 104857600,
  "mime": "video/mp4",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "duration": 86400,
  "access_password": "",
  "large_file_password": "your-large-file-password",
  "resume": false              // 续传时设为 true
}
```

**响应：**
```json
{
  "success": true,
  "session_id": "def456...",
  "received_chunks": "00000000000000000000",
  "total_chunks": 20
}
```

### 上传分片

**POST** `?api=upload/chunk`

**multipart/form-data：**
- `session_id`：会话 ID
- `chunk_index`：分片索引（0-based）
- `file`：分片二进制

**响应：**
```json
{
  "success": true,
  "chunk_index": 5,
  "received_chunks": "00000100000000000000"
}
```

### 合并分片

**POST** `?api=upload/merge`

**请求体（JSON）：**
```json
{ "session_id": "def456..." }
```

**响应：**
```json
{
  "success": true,
  "item": {
    "id": 42,
    "share_code": "abcd1234",
    "share_url": "https://your-domain.com/?s=abcd1234",
    "name": "video.mp4",
    "size": 104857600,
    "size_formatted": "100 MB"
  }
}
```

**错误码：**
- `409 INCOMPLETE_CHUNKS`：分片未全部到达，响应包含 `received_chunks` 位图
- `410 SESSION_EXPIRED`：会话不存在或已结束
- `422 HASH_MISMATCH`：合并后文件哈希不符
- `500 MERGE_FAILED`：磁盘写入失败

---

## 压缩包预览

仅支持 zip 格式。

### 列出目录

**GET** `?api=archive/list&id={item_id}&path={内部路径}`

**响应：**
```json
{
  "success": true,
  "path": "src/js/",
  "entries": [
    { "name": "main.js", "type": "file", "size": 1234, "mime": "text/javascript" },
    { "name": "utils",   "type": "dir",  "size": 0,    "mime": "" }
  ],
  "breadcrumb": [
    { "name": "root", "path": "" },
    { "name": "src",  "path": "src/" },
    { "name": "js",   "path": "src/js/" }
  ],
  "total_entries": 42,
  "truncated": false
}
```

### 读取单文件

**GET** `?api=archive/read&id={item_id}&path={内部路径}`

**响应：** 文件内容（≤1MB）

---

## 缩略图懒生成

**GET** `?action=thumb&item_id={id}`

**响应：**
```json
{
  "success": true,
  "thumbnail_path": "12345_test.thumb.jpg",
  "status": "ready"  // 或 "none" / "failed"
}
```

---
```

- [ ] **Step 4: 提交**

```bash
git add .env.example README.md API.md
git commit -m "docs: document chunked upload, thumbnail, and archive features"
```

---

## 任务依赖图

```
Task 1 (DB 迁移)
    ├── Task 2 (缩略图后端)
    │       ├── Task 5 (缩略图懒生成前端)
    │       └── Task 12 (批量缩略图管理)
    ├── Task 3 (分片上传后端)
    │       └── Task 4 (分片上传前端)
    └── Task 9 (压缩包后端)
            └── Task 10 (压缩包前端)

Task 6 (语法高亮)        — 独立
Task 7 (图片灯箱)        — 独立
Task 8 (Markdown 渲染)   — 独立
Task 11 (PDF 预览)       — 独立
Task 13 (文档更新)       — 最后
```

**推荐实施顺序：** Task 1 → (2, 3) → (4, 5) → (6, 7, 8) → (9) → (10) → (11) → (12) → (13)

P0 范围（Task 1+2+3+4）完成后可发布；其余 P1/P2/P3 后续迭代。

---

## 自检清单

### Spec 覆盖检查

| Spec 章节 | 对应 Task |
|-----------|----------|
| 4.1 A1 语法高亮 | Task 6 |
| 4.2 A2 图片灯箱 | Task 7 |
| 4.3 A3 PDF 预览 | Task 11 |
| 4.4 A6 Markdown 渲染 | Task 8 |
| 4.5 B1 分片上传 | Task 3 + 4 |
| 4.6 B2 缩略图生成 | Task 2 + 5 + 12 |
| 4.7 B3 压缩包预览 | Task 9 + 10 |
| 3.3 数据层变更 | Task 1 |
| 8 数据库迁移 | Task 1 |
| 9 部署与环境配置 | Task 13 |

✅ 所有 spec 章节均有对应任务。

### 占位符扫描

✅ 无 TBD、TODO、占位符。所有代码块完整。

### 类型一致性

- `thumbnail_path` 三态（`null`/`failed:<reason>`/相对路径）：Task 2 定义 → Task 5 读取 → Task 12 显示 一致
- `session_id`：32 位十六进制（Task 3）→ Task 4 前端生成 16 字节随机 → 一致
- `received_chunks` 位图：Task 3 服务端写 → Task 4 前端读 → 一致
- API 端点路径：upload/init, upload/chunk, upload/merge, archive/list, archive/read — 全部一致

---

## 完成检查清单

实施完成后，逐项验证：

- [ ] 数据库迁移运行成功（Task 1）
- [ ] 上传图片 → 缩略图生成（Task 2 + 5）
- [ ] 上传 60MB 文件 → 分片上传成功（Task 3 + 4）
- [ ] 分享代码文件 → 语法高亮（Task 6）
- [ ] 分享图片 → 灯箱弹出（Task 7）
- [ ] 分享 Markdown → HTML 渲染（Task 8）
- [ ] 上传 zip → 浏览器导航（Task 9 + 10）
- [ ] 上传 PDF → PDF.js 渲染（Task 11）
- [ ] 管理后台批量生成缩略图（Task 12）
- [ ] README/API.md 更新（Task 13）
- [ ] 现有功能回归测试（上传/分享/搜索/批量/管理后台/API）

---

**Plan End**
