<?php
/**
 * 缩略图生成
 *
 * 作者：FileShare Contributors
 *
 * 设计意图见 docs/DESIGN_INTENT.md
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
 * @param string $sourcePath 源图片绝对路径
 * @param string $itemName 用于提取扩展名的文件名
 * @return string|false 成功返回缩略图绝对路径，失败返回 false
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
 * @param string $sourcePath 源视频绝对路径
 * @return string|false 成功返回缩略图绝对路径，ffmpeg 不可用或执行失败返回 false
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
 * @param int $itemId 项目 ID
 * @return string 缩略图相对路径（basename）或 'failed:<reason>'
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

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $result = generateImageThumbnail($item['path'], $name);
        if ($result === false) {
            $newVal = 'failed:image-decode-error';
        } else {
            $newVal = basename($result);
        }
    } elseif (in_array($ext, ['mp4', 'webm', 'ogv', 'ogg', 'avi', 'mov', 'mkv'], true)) {
        $result = generateVideoThumbnail($item['path']);
        if ($result === false) {
            $newVal = isFfmpegAvailable() ? 'failed:ffmpeg-error' : 'failed:no-ffmpeg';
        } else {
            $newVal = basename($result);
        }
    } else {
        $newVal = 'failed:unsupported-type';
    }

    $db->prepare('UPDATE items SET thumbnail_path = ? WHERE id = ?')
       ->execute([$newVal, $itemId]);

    return $newVal;
}

/**
 * 检查缩略图状态
 *
 * @param string|null $thumbnailPath 数据库中存储的缩略图字段值
 * @return string 'none' | 'failed' | 'ready'
 */
function getThumbnailStatus($thumbnailPath) {
    if (empty($thumbnailPath)) return 'none';
    if (strpos($thumbnailPath, 'failed:') === 0) return 'failed';
    return 'ready';
}