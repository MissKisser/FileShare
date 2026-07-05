# 全量修复进度跟踪 — fix/code-review-cleanup

**起始日期**：2026-07-05
**基准分支**：`main` @ `26ee579`
**工作分支**：`fix/code-review-cleanup`

---

## 阶段 0：准备

- [x] 0.1 创建分支 `fix/code-review-cleanup`
- [x] 0.1 创建 `docs/CODE_REVIEW_REPORT.md`（26 项归档）
- [x] 0.1 创建 `docs/CLEANUP_PROGRESS.md`（本文件）

---

## 阶段 1：Critical

- [ ] **1.1 C1** — `src/database.php` 全新 DB 初始化崩溃（settings INSERT 引用未创建列）
- [ ] **1.2 C6** — 删除 `phpinfo.php` + 加入 `.gitignore`
- [ ] **1.3 C2** — `src/handlers.php` 分享密码验证加速率限制（新增 `isRateLimitedByKey` 辅助）
- [ ] **1.4 C3** — `src/admin.php` + `src/api.php` admin login / API auth token 加速率限制
- [ ] **1.5 C4** — `src/handlers.php` session 不再存明文 owner token + 同步 share.php / 测试
- [ ] **1.6 C5** — `src/handlers.php` SVG 预览强制 attachment + CSP

---

## 阶段 2：Important

- [ ] **2.1 I1** — `src/handlers.php` streamFile 严格 Range 解析（正则 + 416）
- [ ] **2.2 I2** — `assets/js/thumbnail.js` 前端转义
- [ ] **2.3 I8** — `src/handlers.php` + `src/api.php` + `src/migrate.php` 异常信息不回显
- [ ] **2.4 I3** — `index.php` + `src/functions.php` cleanExpired / getStorageStats 缓存优化
- [ ] **2.5 I4** — `src/admin.php` admin items 分页 + searchItems 加 limit/offset
- [ ] **2.6 I5** — `src/api.php` 移除 $_GET['token'] fallback + API.md 同步
- [ ] **2.7 I6** — `src/functions.php` 抽取 `createFileItem` / `createTextItem` 共用函数
- [ ] **2.8 I7** — `src/chunk_upload.php` + `assets/js/chunked-upload.js` merge 并发安全 + 密码重校验

---

## 阶段 3：Minor

- [ ] **3.1 M2+M3** — 新建 `uploads/.htaccess` + 全部 .htaccess 升级 Apache 2.4 双语法 + `docs/nginx.example.conf`
- [ ] **3.2 M7** — `.gitignore` 添加 `/tools/`
- [ ] **3.3 M4** — 删除 `frontend/` + README 目录结构同步
- [ ] **3.4 M9** — `src/database.php` 不再声明 items.created_at（新建库）
- [ ] **3.5 M1** — `.env.example` 强化警告 + 修正缩略图配置注释
- [ ] **3.6 M8+M10** — `CLAUDE.md` + `API.md` 校准
- [ ] **3.7 M11** — `src/functions.php` 日志清理移出热路径（settings 计数器）
- [ ] **3.8 M12** — `src/database.php` migrate rename 失败回退（copy + unlink）
- [ ] **3.9 M5+M6** — `composer.json` + `phpunit.xml` + `tests/helpers/HttpHelper.php` + `tests/README.md`

---

## 阶段 4：验证与合并

- [ ] **4.1** 端到端验证：
  - [ ] PHP 语法检查（`find src -name "*.php" -exec php -l {} \;`）
  - [ ] C1 fresh init 验证（删 db 重启）
  - [ ] 手动测试脚本（test_owner_delete.php, test_delete_auth.php）
  - [ ] 开发服务器手测（上传/分享/admin/SVG/chunk）
- [ ] **4.2** 更新 CLEANUP_PROGRESS 勾选 + 提交 PR

---

## Backlog（不在本次范围）

- FTS5 全文搜索（I4 长期方案）
- 真正的 PSR-4 自动加载重构（M5 仅搭框架）
- PHPUnit 全面迁移所有 test_*.php（M6 仅搭框架 + 示范）
- session 存储改 redis（C4 根本缓解）
- 路径遍历更深层加固（archive.php 已有防护）
- 速率限制落库表（当前用 session 维度，跨 session 失效）
