# 代码审查报告 — copy.viaxv.top (FileShare)

**审查日期**：2026-07-05
**审查范围**：全项目代码质量与架构
**基准 commit**：`26ee579` (main)

## 总体评价

项目整体设计成熟，关键安全路径处理得当（PDO 参数化、bcrypt、hash_equals、事务化原子删除、owner token 256 位熵、ID enumeration 防护）。存在 **6 Critical + 8 Important + 12 Minor = 26 项**待修复问题。

---

## 🔴 Critical（6 项 — 全部修复 ✅）

| # | 标题 | 文件 | 状态 | Commit |
|---|---|---|---|---|
| C1 | 全新数据库初始化崩溃（settings INSERT 引用未创建的列） | `src/database.php:138-151` | ✅ 已修 | 8f56bec |
| C2 | 分享密码验证无速率限制（暴力破解） | `src/handlers.php:859-889` | ✅ 已修 | fc1e0c2 |
| C3 | admin 登录 + API Token 端点无速率限制 | `src/admin.php:35-59` + `src/api.php:252-281` | ✅ 已修 | a8c8f7e |
| C4 | session 中明文存 owner token | `src/handlers.php:843,848` | ✅ 已修 | 25efdc4 |
| C5 | SVG 上传导致存储型 XSS（预览 inline 执行） | `src/config.php:114,139` + `src/handlers.php:931-936` | ✅ 已修 | 013d9d9 |
| C6 | phpinfo.php 暴露在 web 根目录 | `phpinfo.php` | ✅ 已修 | d12cd39 |

## 🟠 Important（8 项 — 全部修复 ✅）

| # | 标题 | 文件 | 状态 | Commit |
|---|---|---|---|---|
| I1 | streamFile Range 解析不严格（多 range / 越界） | `src/handlers.php:1135-1167` | ✅ 已修 | a0b5aab |
| I2 | 缩略图路径前端未转义直接拼 HTML | `assets/js/thumbnail.js:37-38` | ✅ 已修 | d2ffbb0 |
| I3 | cleanExpired 与 getStorageStats 每次首页都跑 | `index.php:39,48` | ✅ 已修 | 957b041 |
| I4 | searchItems LIKE '%...%' 全表扫描 | `src/functions.php:253-257` | ✅ 已修 | 74b5832 |
| I5 | $_GET['token'] API Token 会泄露到 access log | `src/api.php:97-99` | ✅ 已修 | 4143ecb |
| I6 | API 上传与表单上传严重重复（~200 行复制） | `src/handlers.php:366-524` vs `src/api.php:413-513` | ✅ 已修 | 874ca80 |
| I7 | chunk merge 并发 TOCTOU + 大文件密码绕过 | `src/chunk_upload.php:183-307` | ✅ 已修 | afdfbe6 |
| I8 | Exception message 直接回显用户（泄露 schema） | `src/handlers.php:511-521` + 多处 | ✅ 已修 | abc9319 |

## 🟡 Minor（12 项 — 全部修复 ✅）

| # | 标题 | 文件 | 状态 | Commit |
|---|---|---|---|---|
| M1 | .env LARGE_FILE_PASSWORD 弱密码（仅警告，不修改本地） | `.env:4` + `.env.example` | ✅ 已修 | 6df0dfd |
| M2 | uploads/ 缺少 .htaccess 禁止 PHP 执行（Apache RCE 面） | `uploads/` | ✅ 已修 | 77e9d70 |
| M3 | 所有 .htaccess 用 Apache 2.2 语法（2.4 不生效） | 多处 `.htaccess` | ✅ 已修 | 77e9d70 |
| M4 | frontend/ 是短链接项目历史遗留 | `frontend/` | ✅ 已修 | 6df0dfd |
| M5 | 缺 composer.json，无 PSR-4 自动加载 | 根目录 | ✅ 已修 | d7061c1 |
| M6 | tests/test_*.php 是手动脚本，非 PHPUnit 自动化 | `tests/` | ✅ 已修 | d7061c1 |
| M7 | tools/ 未纳入 .gitignore | `.gitignore` | ✅ 已修 | 6df0dfd |
| M8 | CLAUDE.md settings 表 schema 描述与实际不符 | `CLAUDE.md:71-77` | ✅ 已修 | d1d9b15 |
| M9 | items.created_at 死列，从未被读取 | `src/database.php:64,67` | ✅ 已修 | 6df0dfd |
| M10 | API.md 与 api.php 实现偏差（chunk 字段、缩略图路由等） | `API.md` | ✅ 已修 | d1d9b15 |
| M11 | incrementDownloadCount 每次下载都跑日志清理 | `src/functions.php:334-338` | ✅ 已修 | 561d15e |
| M12 | migrateJsonToSqlite rename 跨设备失败不检查 | `src/database.php:334-336` | ✅ 已修 | 8c62f84 |

---

## ✅ 已确认良好的部分（修复时勿动）

1. `deleteItemsAtomically`（functions.php:148-225）事务边界 + FK 清理 + 引用计数 unlink + rollback
2. owner token 256 位熵 + sha256 入库设计
3. ORDER BY 白名单防 SQL 注入（functions.php:284）
4. `loadData` / `handleSearch` 严格不返回密码保护文本内容
5. `getRealIP` 默认信任 REMOTE_ADDR，仅配置 TRUSTED_PROXIES 时读 forwarded 头
6. chunk session_id 强制 `[a-f0-9]{32}` 正则白名单
7. archive 路径遍历防护（剥除 `../`）
8. JSON 输出统一 `JSON_UNESCAPED_UNICODE`
9. PDF / Markdown 预览用 DOMPurify sanitize
10. `handleOwnerDelete` 故意免 CSRF 的设计论证充分（token 即凭证）

---

## 修复策略

详见 `docs/CLEANUP_PROGRESS.md` 与 `docs/superpowers/specs/2026-07-05-code-review-cleanup-design.md`。

**分支**：`fix/code-review-cleanup`
**风格**：每项独立 commit，保持 PHP 7.4 兼容（无箭头函数/match/nullsafe）
