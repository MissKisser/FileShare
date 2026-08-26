# 设计意图汇总

本文档收集 src/ 下代码中"为什么这么写"的决策依据。
代码本身只保留 PHPDoc（功能 + 入参 + 返回值），不重复这里的内容。
修改相关代码前请先看对应章节。

---

## 1. 安全决策

### 1.1 凭据与 Token

- **API Token 仅走 Authorization 头**：URL 中的 token 会被记入 access log、Referer 头、浏览器历史，必须只用 `Authorization: Bearer <token>`。旧 `?token=` 调用方式不支持。
- **owner_token 明文不入 session**：session 文件泄露会丢凭证。验证成功后 session 只存 boolean 标记，明文 token 仅在 URL `?manage=<plaintext>` 携带，验证后丢弃。后续请求不带 token 仍能识别 owner（显示提示），但 POST 删除要求重新带 `?manage=<token>`（manageToken 仅当前请求有效）。
- **CSRF 校验**：所有写操作 POST 必须带 `csrf_token`，失败统一返回"安全验证失败"不区分原因。
- **真实 IP 识别**：默认信任 `REMOTE_ADDR`；仅当 `REMOTE_ADDR` 匹配 `TRUSTED_PROXIES`（CIDR 列表，环境变量配置）时才读取 `X-Forwarded-For` / `X-Real-IP` / `Client-IP` 头，避免伪造来源。
- **API auth 端点速率限制**：按真实 IP 维度，10 次/60 秒（`isRateLimitedByKey` + `recordRateLimitByKey`）。密码错误时累加计数；ADMIN_PASSWORD 默认值弱，必须防爆破。

### 1.2 XSS 与内容安全
- **路径遍历防御**：压缩包读取时 `innerPath` 先 `str_replace(['../', '..\\'], '', ...)`，再校验非空且不以 `/` 开头。
- **缩略图鉴权收敛**：缩略图位于 `uploads/<file>.thumb.jpg`，过去被前端直接以 `/uploads/<thumbPath>` 引用，密码保护项的缩略图可被未授权访问。现所有读取统一收敛到 `?thumb=N` 路由，执行密码解锁检查。

### 1.3 分片上传加固

- **密码二次校验**：init 时校验过大文件密码，merge 时再校验一次（防 init 后绕过）。
- **分片大小一致性**：merge 后实测文件大小 vs init 声明 filesize，偏差 > 1% 拒绝（防攻击者控制分片大小绕过 init 时的密码阈值）。
- **乐观锁**：merge 前 `UPDATE ... WHERE status='uploading'`，affected rows != 1 说明已被其他 merge 请求抢先，拒绝。
- **merge stream_copy_to_stream 返回值检查**：任何分片写入失败立即回滚。
- **merge 后实测大小再校一次大文件密码**：即使 init 声明 filesize 在普通阈值内，merge 后实测超过普通阈值仍要求密码（与 init/merge 阶段的声明大小校核形成双重保险，防 init 阶段声明小但分片阶段堆出大文件绕过密码阈值）。

### 1.4 信息泄露防护

- **PDOException message 模糊化**：DB 异常常含完整 SQL + 文件路径，对用户模糊化提示，记录到 `error_log` 供运维排查。
- **PharData 异常模糊化**：tar 解析异常 message 含 `phar://` 路径细节，对用户模糊化。
- **404/403 不可区分**：密码项目不存在时也记录一次失败尝试（防攻击者通过响应差异判断 share_code 是否存在）；无密码项目不计入限流（防误锁合法访问）；失败才计数（成功不计数，防合法用户误锁）。
- **压缩包解析错误模糊化**：tar 列出失败时返回 `(无法读取压缩包内容)` 占位项。
- **content_preview 文本遮蔽**：`formatItemForApi()` 对文本类型返回预览时，受密码保护的项用 `maskContent()` 遮蔽（保留前 3 字符+`****`，便于辨识），未设密码的项截取前 200 字符。避免明文文本通过 API 端点泄露。

### 1.5 范围控制（HTTP Range）

- **多 range / 非法 range / 语法错 → 忽略 Range 返回 200 全量**（不支持 multipart/byteranges）。
- **越界（start >= size）→ 416 Requested Range Not Satisfiable**。

---

## 2. 性能与可扩展性

### 2.1 过期清理节流

- **5 分钟节流**：首页加载触发 `cleanExpired()`，距上次清理 < 5 分钟则跳过（`settings.last_clean_expired` 时间戳）。副作用：过期项最长可能多存 5 分钟，业务上可接受。
- **日志清理 1 小时节流**：每次下载/上传触发日志清理太频繁。`settings.download_logs_cleaned_at` / `upload_logs_cleaned_at`，距上次 > 1 小时才清理。

### 2.2 后台分页

- admin 分页：默认 50/页，避免 items 表上万行后 admin 页全量渲染卡顿。
- `searchItems` limit/offset 可选：null 表示不限（向前兼容）。

### 2.3 统计查询合并

- 6 次分类 SUM 合并为 1 次 SQL（CASE WHEN 分列 SUM）。
- 结果缓存到 `settings.storage_stats_cache` (JSON)，5 分钟过期。

### 2.4 SQL 兼容性

- **两步清理日志**：先查 id，再 DELETE，避免 SQLite 同表子查询限制。
- **rename 跨设备失败回退**：备份 JSON 文件时，rename 失败（跨挂载点常见）降级到 copy + unlink。

---

## 3. 数据模型

### 3.1 时间字段

- 业务时间字段统一用 `time`（INTEGER Unix 时间戳）。原 `created_at DATETIME DEFAULT CURRENT_TIMESTAMP` 列从未被读取已移除，老库通过增量迁移保留该列（SQLite 不支持 ALTER DROP COLUMN），新库不再声明。

### 3.2 owner 凭证存储

- `items.owner_token_hash` 存 `sha256(owner_token)` 而不是明文。部分唯一索引（SQLite 支持 WHERE 子句）允许历史 NULL 行共存，新创建的行必填。
- owner_token 用 256 位熵（hex64），share_code 用 32 位熵（hex8）：前者是删除凭证**必须**不可猜，后者是公共分享凭证。
- 明文 owner_token 仅出现在创建响应的 manage_url 里，DB 泄露不会直接泄露删除凭证。老数据 owner_token_hash 为 NULL，admin 删除仍可用；owner 端只能删了重建。

### 3.3 增量迁移

- `runIncrementalMigrations()` 通过 `ALTER TABLE ADD COLUMN` + `UPDATE` 统一回填，避免"INSERT 引用了尚不存在的列"导致全新部署启动崩溃。

---

## 4. 删除原子性

`deleteItemsAtomically()` 修复的问题（行为契约见 PHPDoc）：

- 事务边界正确：unlink 失败会回滚 DB 写入
- 关联日志不残留：DELETE items 同时清理 download_logs / upload_logs
- 物理文件引用计数 race：先查再删两步走改事务内
- ID enumeration 防护：errors 数组不泄露哪些 ID 不存在

---

## 5. 文件名净化

`sanitizeStoredFilename()`：用户上传的原文件名经 `preg_replace('/[^a-zA-Z0-9._-]/', '_', ...)` 净化，basename 截断，截断到 200 字符。避免目录遍历字符、特殊 Unicode 在不同 FS 上行为不一致。

---

## 6. 日志最小化

不记录原文（隐私/安全，密码保护项尤为严重），仅记录类型 + 大小 + 是否密码保护。

---

## 7. 文件类型白名单容错

- **扩展名 + MIME 双白名单**：`ALLOWED_FILE_EXTENSIONS` 与 `ALLOWED_FILE_MIMES` 在 `config.php` 中以并列常量声明，`handlers.php` 在落盘前同时校验扩展名与 `finfo` MIME，二者均需落在白名单内才放行。
- **apk MIME 抖动**：apk 在不同 PHP / libmagic 版本下 `finfo` 偶发返回 `application/zip` 或 `application/octet-stream`，因此 MIME 白名单只放行 `application/vnd.android.package-archive`，其它值配合扩展名校验兜底（`handlers.php` 中以扩展名为准）。

---

## 8. PHP 运行时上限

- **upload_max_filesize / post_max_size = PHP_INI_PERDIR**：运行期 `ini_set()` 无效，只能在 `php.ini` 或 `.user.ini` 中设置。当前 php.ini 已配置 1024M，业务层另用 `MAX_FILE_SIZE_NORMAL`（200MB）/ `MAX_FILE_SIZE_LARGE`（2GB）兜底，超过立即拒绝。

---

## 9. 缩略图生成的可选依赖与降级

- **ffmpeg 是可选依赖**：未安装或 `shell_exec` 被禁用时不报错，统一通过 `isFfmpegAvailable()` 返回 false，业务层再写 `'failed:no-ffmpeg'` 状态。系统不强制要求 ffmpeg。
- **`'failed:no-ffmpeg'` 与 `'failed:ffmpeg-error'` 区分**：前者表示环境不具备能力（管理员可据此决定装不装），后者表示环境具备但执行失败（需排查具体视频/路径问题）。两条都用 `failed:` 前缀以便 `getThumbnailStatus()` 一并归类为 `failed`，但 reason 字符串保留诊断信息。
- **图片 Imagick → GD 降级链**：`generateImageThumbnail` 优先用 Imagick（更现代、支持更多格式），未加载或异常时降级到 GD；GD 同样缺失则返回 false 写 `failed:image-decode-error`。两个扩展同时缺失的部署应被视为异常环境，但代码层不抛错以保持上传主流程可用。
- **`thumbnail_path` 三态语义**：NULL/'' = 未尝试；`'failed:<reason>'` = 已尝试但失败；其余 basename = 已生成且相对 `uploads/`。三种状态均由 `getThumbnailStatus()` 归一化为 `none` / `failed` / `ready`，UI 层只关心三态字符串。

---

## 10. 管理后台登录速率限制

- **IP 维度限速 10 次/60 秒**：`adminLogin()` 使用 `isRateLimitedByKey('admin_login_' . getRealIP(), 10, 60)`，失败计数在 `recordRateLimitByKey()` 写入 `rate_limits` 表。防 admin 默认密码（`please-change-admin-password`）被爆破。