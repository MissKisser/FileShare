# FileShare 功能扩展设计文档

> **设计日期**：2026-07-03
> **项目**：FileShare（基于 PHP + SQLite 的轻量级文件上传与文本存储系统）
> **范围**：预览增强 4 项 + 文件高级能力 3 项，共 7 个功能模块
> **排除**：用户管理 / 多用户系统

---

## 1. 项目上下文

### 1.1 现有功能矩阵

| 类别 | 已实现 |
|------|--------|
| 上传 | 多文件、200MB 普通、2GB 大文件（密码保护）、SHA-256 去重 |
| 分享 | 8 位分享码、二维码、密码保护、独立分享页 |
| 预览 | 图片/视频/音频/PDF、视频 Range 流式播放 |
| 数据 | SQLite + 自动 WAL、JSON→SQLite 迁移、过期清理 |
| 搜索 | 关键词、类型、分类、多种排序 |
| 批量 | 批量删除、批量复制分享链接 |
| 统计 | 类型分布饼图、每日上传柱状图 |
| 管理后台 | 仪表盘、项目管理、日志、系统设置 |
| API | RESTful + Bearer Token + Refresh Token |
| 安全 | CSRF、bcrypt、IP 黑名单、时序安全比较、IP 掩码 |

### 1.2 技术约束（来自 CLAUDE.md）

- PHP 7.4+（不使用箭头函数、命名参数、`match`、`nullsafe`）
- 数据库：SQLite
- 前端：原生 HTML + CSS + JS
- 不引入构建工具（无 webpack/vite）
- 第三方库以 CDN 形式引入

---

## 2. 设计目标

在保持现有架构与 PHP 7.4 兼容性的前提下，扩展以下 7 项功能：

| ID | 功能 | 类别 |
|----|------|------|
| A1 | 语法高亮 | 预览增强 |
| A2 | 图片灯箱 | 预览增强 |
| A3 | PDF 在线预览 | 预览增强 |
| A6 | Markdown 渲染 | 预览增强 |
| B1 | 分片上传（断点续传） | 文件高级能力 |
| B2 | 缩略图自动生成 | 文件高级能力 |
| B3 | 压缩包内文件预览 | 文件高级能力 |

**非目标**（明确排除）：
- 多用户系统、配额、个人空间
- Office 文档（docx/xlsx/pptx）在线预览（依赖 LibreOffice）
- 视频转码 / HLS（依赖 ffmpeg 二次处理，超出范围）
- 客户端加密上传（用户未选择）
- PDF 缩略图生成（依赖 ImageMagick，超出范围）

---

## 3. 总体架构

### 3.1 路由扩展

```
index.php
├── ?s=code              → share.php        [A1/A2/A3/A6 增强]
├── ?preview=code        → 现有预览流        [缩略图命中时优先返回]
├── ?api=...             → api.php          [新增 chunk/merge/archive 端点]
├── ?action=upload       → handlers.php     [分片接收与合并]
├── ?action=thumb        → handlers.php     [新：缩略图生成触发]
└── /admin/*             → admin.php        [新增"批量生成缩略图"入口]
```

### 3.2 新增前端模块（按资源类型拆分）

| 文件 | 功能 |
|------|------|
| `assets/js/syntax.js` | Prism 封装：自动按 data-language 高亮 |
| `assets/js/gallery.js` | 图片灯箱：放大、切换、缩放、EXIF |
| `assets/js/pdf-preview.js` | PDF.js 包装：异步加载、工具栏 |
| `assets/js/markdown.js` | Marked.js + DOMPurify 渲染 |
| `assets/js/chunked-upload.js` | 分片上传控制器（FileShareUploader 类） |
| `assets/js/thumbnail.js` | 缩略图展示与懒加载 |
| `assets/js/archive-preview.js` | 压缩包导航与文件预览 |
| `assets/css/gallery.css` | 灯箱样式 |
| `assets/css/pdf-preview.css` | PDF 预览样式 |
| `assets/css/archive.css` | 压缩包浏览器样式 |

### 3.3 数据层变更

**`upload_logs` 表新增字段**：
- `session_id TEXT` — 分片会话唯一标识
- `chunk_count INTEGER` — 分片总数
- `received_chunks TEXT` — 已接收分片位图（如 `"010101"`）
- `status TEXT DEFAULT 'uploading'` — uploading / merged / aborted

**`items` 表新增字段**：
- `thumbnail_path TEXT` — 缩略图相对路径
  - `NULL` 或空字符串 = 未尝试生成
  - 以 `failed:` 前缀 = 已尝试但失败（如 `failed:image-decode-error`）
  - 相对路径 = 缩略图已生成，路径相对于 `uploads/`

**不新增表**：缩略图元数据存于 items.thumbnail_path 即可。

### 3.4 文件存储约定

- 分片临时目录：`uploads/_chunks/{session_id}/{chunk_index}`（合并后清理）
- 缩略图命名：`<原文件名>.thumb.jpg`（同目录）
- 压缩包解析缓存：`storage/archive_cache/{item_id}_{file_hash}.json`，TTL 1 小时

---

## 4. 模块详细设计

### 4.1 A1 语法高亮

**触发**：
- 文本项目：share.php 渲染时检测为 Markdown 走 A6，其他作为代码块
- 代码文件（扩展名匹配 `ALLOWED_FILE_EXTENSIONS` 中 js/py/java 等）：自动调用 Prism

**实现**：
- 复用现有 Prism CDN（main.php 已加载 line-numbers 插件）
- `syntax.js` 监听 DOMContentLoaded，遍历 `.code-block` 与 `.text-content pre` 元素
- 根据 `data-language` 调用 `Prism.highlightElement`
- share.php 在输出文本时包裹 `<pre class="line-numbers"><code class="language-{ext}">`

### 4.2 A2 图片灯箱

**触发**：share.php 中图片类型且已解锁 → 点击图片触发；main.php 项目卡片图片预览同样适用。

**图片集合范围**：灯箱左右切换范围限定为「同一分享页内的所有图片类型项目」。不在分享页内的图片不进入灯箱导航。

**实现**：
- 引入 `gallery.css` + `gallery.js`（原生 JS 实现，不引第三方库）
- 功能：点击放大、左右切换（同分享页图片）、ESC 关闭、滚轮缩放、EXIF 显示（用 `exifr.js` CDN）
- 同一分享页多张图片时支持左右滑动浏览
- 移动端：滑动手势支持

### 4.3 A3 PDF 预览

**触发**：MIME = `application/pdf` 的文件 → 隐藏默认预览链接，自动用 PDF.js 渲染。

**实现**：
- 引入 PDF.js：`https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.min.js`
- **懒加载**：仅在用户进入 PDF 分享页时动态加载 PDF.js，主页面与其他类型分享页不加载
- `pdf-preview.js` 异步加载，首次访问显示 loading，缓存 workerSrc
- 工具栏：上一页/下一页按钮 + 页码跳转输入框 + 缩放按钮组（25%/50%/75%/100%/150%/200%/适应宽度）
- 移动端：默认宽度适配 + 横向滚动
- 密码保护时仍需先解锁（cookie 验证），PDF 流通过 `?preview=` 路由获取

### 4.4 A6 Markdown 渲染

**触发**：文本项目扩展名为 `.md`/`.markdown` 或内容前 200 字符匹配以下任一正则：
- `^#{1,6}\s`（标题）
- `^\s*[-*+]\s+`（无序列表）
- `^\s*\d+\.\s+`（有序列表）
- `^>\s`（引用）
- ```` ``` ````（代码块）
- `\*\*[^*]+\*\*` 或 `\*[^*]+\*`（粗体/斜体）

**实现**：
- 引入 Marked.js：`https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.0/marked.min.js`
- 引入 DOMPurify：`https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js`
- 渲染前用 DOMPurify 清洗，禁用 `<script>` 与 `on*` 事件
- 代码块交给 Prism 高亮（与 A1 联动）
- 外链添加 `rel="noopener noreferrer"`

### 4.5 B1 分片上传

**协议（3 个端点）**：

```
POST ?api=upload/init
    Body: { session_id, filename, size, mime, chunk_size, total_chunks,
            duration, access_password, large_file_password, resume? }
    返回: { success, session_id, received_chunks: '010101', total_chunks }
    作用: 创建/恢复上传会话

POST ?api=upload/chunk
    Body: multipart: session_id, chunk_index, file (binary)
    返回: { success, received_chunks: '010101' }
    作用: 接收单个分片，写入 uploads/_chunks/{session_id}/{chunk_index}

POST ?api=upload/merge
    Body: { session_id }
    返回: { success, item: {...} }
    作用: 校验所有分片，按序合并，进入现有上传流水线
```

**前端实现**（`chunked-upload.js`）：
- 提供 `FileShareUploader` 类
- 切片：`file.slice(start, end)`，每片默认 5 MB（可配置，最大 20 MB）
- 并发：默认 3 个并发请求
- 续传：调用 `/upload/init?resume=1&session_id=xxx` 获取位图，跳过已传分片
- 中止：`AbortController` + 服务端清理 `_chunks/{session_id}/`
- 进度：聚合各分片进度显示

**阈值与降级**：
- 文件 > 50 MB：自动启用分片
- 文件 ≤ 50 MB：走现有 `/upload` 单次上传（保留旧接口）
- `large_file_password` 校验前置到 `/init` 阶段，避免分片全传完才发现密码错

**安全**：
- 分片接收时校验 session_id 是否属于当前 IP（防会话劫持）
- 分片合并后计算 SHA-256 与 `/init` 声明的哈希比对（如客户端声明）
- 上传会话 24 小时后自动清理（status=aborted）

**合并错误码**：
- 409 INCOMPLETE_CHUNKS — 分片未全部到达，返回 received_chunks 位图，前端可据此续传
- 410 SESSION_EXPIRED — session_id 已过期或不存在
- 422 HASH_MISMATCH — 合并后文件哈希与 `/init` 声明不符（极少发生，可能是磁盘错误）
- 500 MERGE_FAILED — 文件写入失败（权限、磁盘满等）

**API 兼容性**：
- 保留现有 `?api=upload` 单次上传端点
- main.php 上传控件自动判断文件大小选择策略

### 4.6 B2 缩略图生成

**触发时机**：
- **上传完成时**：`/upload/merge` 成功后异步触发（`?action=thumb&item_id=...`）
- **首次访问懒生成**：项目卡片加载时若 `thumbnail_path` 为空且类型为图片/视频，请求服务端生成
- **后台批量回填**：admin.php 提供"为现有项目批量生成缩略图"按钮

**实现**：

```
图片缩略图（GD 或 Imagick）:
1. 读取原图 → 计算缩放比例（保持纵横比）
2. 创建画布（最长边 320）
3. 复制缩放后的图像
4. 保存为 JPEG quality=75
5. 写入 <path>.thumb.jpg
6. 异常时记录 thumbnail_path='failed:image-decode-error'（不阻塞上传）

视频缩略图（ffmpeg）:
1. shell_exec("ffmpeg -ss 00:00:01 -i <path> -vframes 1 -vf scale=320:-1 -q:v 5 <thumb>.jpg")
2. 检测 ffmpeg 可用性：function_exists('shell_exec') && which ffmpeg
3. 失败时不阻塞上传，thumbnail_path='failed:ffmpeg-error' 或 'failed:no-ffmpeg'
```

**存储**：
- 缩略图存同目录：`<原文件名>.thumb.jpg`
- `items.thumbnail_path` 字段记录相对路径
- 大小：图片缩略图最长边 320px；视频 320×180
- 格式：统一 JPEG（质量 75，可通过 `.env` 的 `THUMBNAIL_QUALITY` 调整）

**安全**：
- `shell_exec` 路径用 `escapeshellarg()` 转义
- 缩略图生成加 `flock()` 防止并发重复生成
- ffmpeg 加 5 秒超时
- 检测到非图片/视频 MIME 时直接跳过

**前端展示**：
- `thumbnail.js` 处理图片 `loading="lazy"`
- 无缩略图时显示默认图标（按文件类型）
- 服务端加 `Cache-Control: public, max-age=86400`

### 4.7 B3 压缩包预览

**端点**：

```
GET ?api=archive/list&id=<item_id>&path=<内部路径>
    返回: { 
        success, 
        path: 'src/js/', 
        entries: [
            { name: 'main.js', type: 'file', size: 12345, mime: 'text/javascript' },
            { name: 'utils',   type: 'dir' }
        ],
        breadcrumb: ['root', 'src', 'js']
    }

GET ?api=archive/read&id=<item_id>&path=<内部路径>
    返回: 文件内容（文本/二进制流，带 Content-Type）
    限制: 单文件 ≤ 1 MB（解压后）
```

**PHP 端**（`api.php` 新增）：
1. 根据 item_id 获取 item，确认 mime=`application/zip`
2. 密码校验（如设置了访问密码）
3. 打开 ZipArchive：`new ZipArchive(); $zip->open($path)`
4. 遍历所有条目，构建内存目录树（仅元数据）
5. 根据 query path 过滤该层条目
6. 返回 JSON（限制 ≤ 500 条目防 DoS）
7. 缓存解析结果到 `storage/archive_cache/{item_id}_{hash}.json`，TTL 1 小时

**安全限制**：
- 不将压缩包内容写入磁盘（仅在内存中解压用于显示/传输）
- 路径校验：拒绝 `..`、绝对路径（防 zip slip）
- 单 zip 限制：文件数 ≤ 5000、总大小 ≤ 1GB（解压前）
- 嵌套层级：≤ 3 层（防递归攻击）

**前端**（`archive-preview.js` + `archive.css`）：
- share.php 识别 zip → 显示压缩包浏览器替代"下载"按钮
- 目录树视图 + 文件列表双面板
- 文件点击：
  - 文本类（js/json/md/txt）→ 调 `?api=archive/read` 显示（限制 1 MB）
  - 图片类 → 直接 inline base64 解压显示（限制 500 KB）
  - 其他 → "暂不支持预览，请下载查看"
- 面包屑导航

---

## 5. 错误处理矩阵

| 场景 | 行为 |
|------|------|
| 分片上传中途中断 | upload_logs status=aborted；下次同 session_id 调用 /init 返回 aborted=1，让前端决定清理或续传 |
| 分片 hash 校验失败 | 返回 400，前端重传该分片 |
| 缩略图生成失败 | thumbnail_path='failed:<reason>'，前端用默认图标，不阻塞；触发"批量重新生成"时可被覆盖 |
| ffmpeg 未安装 | thumbnail_path='failed:no-ffmpeg'，记录到 admin 日志 |
| 压缩包损坏 | 返回 400 + 友好错误提示 |
| 压缩包条目过多 | 截断到 500，提示"列表过大，建议下载" |
| 压缩包嵌套层级超限 | 返回 403 + 提示 |
| Markdown 渲染异常 | 降级为纯文本显示 + 错误提示 |
| PDF.js 加载失败 | 退回"下载查看"按钮 |
| 服务端临时目录写失败 | 返回 507，前端重试或终止 |

---

## 6. 性能优化

- **缩略图懒生成**：列表页仅展示已有缩略图，无缩略图项目卡片用 CSS 灰色占位
- **缩略图缓存**：服务端加 `If-Modified-Since` 头（基于文件 mtime）
- **CDN 缓存**：项目卡片缩略图加 `Cache-Control: public, max-age=86400`
- **分片并发**：默认 3，可通过 `.env` 的 `CHUNK_CONCURRENCY` 配置
- **大压缩包解析缓存**：服务端缓存 JSON 元数据，TTL 1 小时
- **PDF.js 懒加载**：仅当用户进入 PDF 分享页时加载，避免影响首屏
- **Marked.js 懒加载**：仅当检测到 Markdown 文本时加载

---

## 7. 测试策略

项目无测试框架，采用**手工 + 浏览器验证清单**：

### 7.1 单元测试（手工）

| 模块 | 验证项 |
|------|--------|
| 数据库迁移 | 旧库升级 → 验证 session_id/chunk_count/received_chunks/thumbnail_path 字段自动添加 |
| 分片合并 | 传 5 片 → 中断 → 续传 → 合并 → SHA-256 与单次上传结果一致 |
| 缩略图 | 上传 jpg/png → 验证 thumb.jpg 生成且 < 50 KB |
| 压缩包解析 | 嵌套 zip 3 层 → 列表正确 → 拒绝第 4 层 |
| Markdown 渲染 | `<script>` 内容 → DOMPurify 清洗后无脚本 |

### 7.2 集成测试（手工）

| 场景 | 验证项 |
|------|--------|
| 分享页 Markdown | 上传 .md → 渲染为 HTML → 代码块高亮 |
| 分享页 PDF | 上传 .pdf → PDF.js 加载 → 翻页正常 |
| 分享页压缩包 | 上传 zip → 目录树渲染 → 点击文本文件 → 显示内容 |
| 主页面分片上传 | 100MB 文件 → 分片上传 → 进度条正确 → 完成后缩略图生成 |
| 主页面灯箱 | 上传多张图片 → 点击放大 → 左右切换 |
| 管理后台 | "批量生成缩略图" → 历史项目全部生成 |

### 7.3 回归测试

现有核心功能必须保持工作：
- 单次文件上传（≤ 50 MB）
- 文本保存
- 分享链接生成
- 搜索/过滤/排序
- 批量删除
- API Token 认证
- 管理后台登录

---

## 8. 数据库迁移

通过 `initDB()` 中的 `ALTER TABLE` 检查自动迁移（与现有 `migrateJsonToSqlite` 模式一致）：

```php
// 检测字段是否存在，不存在则 ALTER TABLE
$cols = $db->query("PRAGMA table_info(upload_logs)")->fetchAll();
$colNames = array_column($cols, 'name');

if (!in_array('session_id', $colNames)) {
    $db->exec("ALTER TABLE upload_logs ADD COLUMN session_id TEXT");
}
if (!in_array('chunk_count', $colNames)) {
    $db->exec("ALTER TABLE upload_logs ADD COLUMN chunk_count INTEGER");
}
if (!in_array('received_chunks', $colNames)) {
    $db->exec("ALTER TABLE upload_logs ADD COLUMN received_chunks TEXT");
}
if (!in_array('status', $colNames)) {
    $db->exec("ALTER TABLE upload_logs ADD COLUMN status TEXT DEFAULT 'uploading'");
}

$itemCols = array_column(
    $db->query("PRAGMA table_info(items)")->fetchAll(),
    'name'
);
if (!in_array('thumbnail_path', $itemCols)) {
    $db->exec("ALTER TABLE items ADD COLUMN thumbnail_path TEXT");
}
```

迁移幂等：已运行过的数据库再次运行不会重复 ALTER。

---

## 9. 部署与环境配置

### 9.1 .env 新增配置

| 变量 | 默认 | 说明 |
|------|------|------|
| `THUMBNAIL_QUALITY` | `75` | 缩略图 JPEG 质量 |
| `THUMBNAIL_MAX_SIZE` | `320` | 缩略图最长边像素 |
| `CHUNK_SIZE` | `5242880` | 分片大小（5 MB） |
| `CHUNK_CONCURRENCY` | `3` | 分片上传并发数 |
| `CHUNK_THRESHOLD` | `52428800` | 启用分片的文件大小阈值（50 MB） |
| `ARCHIVE_CACHE_TTL` | `3600` | 压缩包元数据缓存秒数 |
| `MAX_ARCHIVE_ENTRIES` | `5000` | 压缩包最大条目数 |
| `MAX_ARCHIVE_DEPTH` | `3` | 压缩包最大嵌套层级 |

### 9.2 ffmpeg 检测

- admin dashboard 新增"缩略图能力"卡片，提示"未检测到 ffmpeg"
- 未安装时仅图片可生成缩略图，视频使用默认图标

### 9.3 CDN 资源

所有新增 JS/CSS 走 CDN：
- Prism：jsdelivr（已使用）
- PDF.js：cdnjs
- Marked.js：cdnjs
- DOMPurify：cdnjs
- exifr.js：jsdelivr

README 标注：国内用户可替换为 unpkg / 字节 CDN。

---

## 10. 实施优先级

按用户价值与依赖关系排序：

| 优先级 | 模块 | 原因 |
|--------|------|------|
| P0 | 数据库迁移 | 所有新功能依赖 |
| P0 | B1 分片上传 | 解决大文件稳定性问题 |
| P1 | B2 缩略图生成 | 提升列表加载体验 |
| P1 | A1 语法高亮 | 复用已有 Prism，最快交付 |
| P1 | A2 图片灯箱 | 简单独立 |
| P2 | A6 Markdown 渲染 | 提升文本项目价值 |
| P2 | B3 压缩包预览 | 独立功能 |
| P3 | A3 PDF 预览 | 依赖 PDF.js，最重 |

P0 + P1 完成后即可发布；P2/P3 后续迭代。

---

## 11. 关键设计决策回顾

1. **不引入构建工具**：保持 PHP 原生开发节奏，依赖 CDN
2. **缩略图同库存**：减少表结构复杂度
3. **分片进度通过 upload_logs 追踪**：复用现有日志表，避免新表
4. **PDF.js 而非服务端转图片**：降低服务端 CPU 负担
5. **压缩包仅预览不抽取**：避免磁盘空间爆炸与安全风险
6. **ffmpeg 视频缩略图可选**：缺失时降级到默认图标
7. **数据库迁移幂等**：通过 `PRAGMA table_info` 检测 + `ALTER TABLE`

---

## 12. 风险与缓解

| 风险 | 影响 | 缓解 |
|------|------|------|
| CDN 不稳定 | 分享页加载失败 | README 提示可换国内 CDN；关键功能（PDF）提供降级 |
| ffmpeg 不存在 | 视频无缩略图 | 检测 + 降级到默认图标 |
| 大压缩包解析慢 | 列表卡顿 | 元数据缓存 + 条目数限制 |
| 分片合并中磁盘满 | 合并失败 | 预检查可用空间；失败时清理已合并分片 |
| Marked.js XSS | 脚本注入 | DOMPurify 清洗 |
| 旧浏览器不支持 | 部分功能不可用 | 检测并提示升级 |

---

**文档结束**

---

## 附录 A：变更追踪

| 版本 | 日期 | 变更 |
|------|------|------|
| 1.0 | 2026-07-03 | 初版设计（7 个功能模块） |
| 1.1 | 2026-07-03 | Spec self-review 修订：thumbnail_path 三态语义、分片合并错误码、Markdown 触发规则细化、PDF 工具栏细节、灯箱范围限定 |

---

## 附录 B：术语表

| 术语 | 定义 |
|------|------|
| 分享码（share_code） | 8 位十六进制随机字符串，文件/文本的唯一公开标识 |
| 分片（chunk） | 大文件上传时切分的二进制片段，默认 5 MB |
| 会话（session） | 一次分片上传的完整生命周期，由 session_id 标识 |
| 位图（received_chunks） | 字符串如 `"010101"`，第 i 位表示第 i 个分片是否已接收 |
| 缩略图三态 | 未生成 / 失败 / 已生成，对应 thumbnail_path 三种取值 |
| WAL | SQLite Write-Ahead Logging，提升并发读写性能 |
| CDN | Content Delivery Network，本设计依赖第三方公共 CDN |

---

## 附录 C：参考文献

- [PDF.js 官方文档](https://mozilla.github.io/pdf.js/)
- [Marked.js 安全指南](https://marked.js.org/usng_pro)
- [DOMPurify 配置](https://github.com/cure53/DOMPurify)
- [Prism.js 插件列表](https://prismjs.com/#plugins)
- [PHP ZipArchive](https://www.php.net/manual/en/class.ziparchive.php)
- [GD 图像处理](https://www.php.net/manual/en/book.image.php)
- [ffmpeg 截图命令](https://ffmpeg.org/ffmpeg.html#Description)