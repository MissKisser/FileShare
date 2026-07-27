# 删除逻辑重设计

**日期**: 2026-07-27
**状态**: 已批准

## 问题

1. 删除确认弹窗不在屏幕中间（JS `top/left/transform` 与 CSS flexbox 冲突）
2. 所有人都能触发删除按钮，虽然后端返回 403，但前端不检查响应直接显示"删除成功"
3. 后端 `handleDelete()` 要求 admin 登录，普通用户无法删除自己的内容
4. 存在两套重复的删除事件处理器
5. 分享页用 `window.confirm()`，首页用自定义弹窗，风格不统一

## 核心规则

| 文件类型 | 谁能删除 | 验证方式 |
|---------|---------|---------|
| 非加密文件/文本 | 任何人 | 仅确认弹窗 |
| 加密文件/文本 | 任何人（需输入密码） | 确认弹窗 → 密码输入弹窗 → `password_verify()` |

## 改动清单

### 1. 后端：重写 `handleDelete()`

**文件**: `src/handlers.php`

**当前**: 要求 `isAdminLoggedIn()` + CSRF → 非管理员 403 → 302 重定向

**改为**:
- 移除 admin 登录要求
- 保留 CSRF 验证
- 查询 item，判断 `password` 列：
  - 无密码：直接删除
  - 有密码：从 POST 取 `access_password`，用 `password_verify()` 校验，通过才删除
- 返回 JSON（而非 302 重定向）：
  - 成功: `{success: true, message: "删除成功"}`
  - 密码错误: `{success: false, message: "密码错误"}`
  - 缺少密码: `{success: false, message: "此内容已设置密码保护，需输入密码"}`
  - CSRF 失败: `{success: false, message: "安全验证失败"}`

### 2. 后端：重写 `handleBatchDelete()`

**文件**: `src/handlers.php`

**改为**:
- 移除 admin 登录要求
- 保留 CSRF 验证
- 对每个 item 检查密码状态：
  - 无密码的 item：直接删除
  - 有密码的 item：从 POST 取 `passwords[<id>]` 参数，`password_verify()` 校验
- 返回 JSON：`{success, deleted_count, failed_ids, message}`
  - 部分成功也算 success=true，failed_ids 列出失败的

### 3. 前端：删除按钮点击流程

**文件**: `assets/js/upload.js`

**非加密 item**:
```
点击"移除" → showCyberConfirm("确定删除？") → POST delete=<id> + csrf_token → 刷新列表
```

**加密 item**:
```
点击"移除" → showCyberConfirm("确定删除？") → showPasswordModal() → POST delete=<id> + csrf_token + access_password=xxx → 刷新列表
```

### 4. 新增：密码输入弹窗

**文件**: `assets/js/upload.js`, `assets/css/components.css`

新增 `showPasswordModal(message, onConfirm)` 函数：
- 标题："验证密码"
- 内容：密码输入框 + 提示文字
- 按钮："取消" + "确认删除"
- 复用 `.cyber-modal-overlay` 样式体系
- 支持回车提交、Escape 关闭

### 5. 修复弹窗居中

**文件**: `assets/js/upload.js`

**问题**: JS 手动设置 `style.top/left/transform` 与 CSS `display:flex; align-items:center; justify-content:center` 冲突，且 `transform:translate(-50%,-50%)` 覆盖了 CSS 的 `transform:scale(0.95)` 动画。

**修复**: 删除 JS 中 `modalEl.style.top/left/transform` 三行，完全依赖 CSS flexbox 居中。

### 6. 清理重复事件绑定

**文件**: `assets/js/upload.js`

删除第二个全局 `document.addEventListener('click', ...)` 删除处理器（约 2243-2274 行），只保留 `bindListButtonEvents()` 中的事件委托版本。

### 7. 前端响应检查

**文件**: `assets/js/upload.js`

当前 `.then()` 不检查响应状态。改为：
```javascript
.then(r => r.json())
.then(d => {
    if (d.success) { showToast('删除成功', 'success'); refreshStorageList(); }
    else { showToast(d.message || '删除失败', 'error'); }
})
```

批量删除同理。

### 8. 首页 item 渲染：标记密码状态

**文件**: `templates/main.php`, `assets/js/upload.js`

给删除按钮添加 `data-has-password` 属性：
```html
<button class="btn-small btn-danger btn-delete" data-id="1" data-has-password="1">移除</button>
```

前端据此决定是否弹出密码输入框。

搜索接口和 API 已返回 `has_password` 字段，无需后端改动。

### 9. 批量删除密码处理

**文件**: `assets/js/upload.js`

批量删除时，收集选中 item 中有密码的项，如果存在：
- 弹出密码输入弹窗，提示"N 个加密项需输入密码"
- 用户输入一个密码，尝试匹配所有加密项（同一密码可能保护多个 item）
- POST 时附带 `passwords[<id>]=<input>` 给每个加密项

## 不改动的部分

- `handleOwnerDelete()`：分享页的 owner 自删除逻辑不变（通过 manage token）
- API 删除端点：仍要求 API token + write 权限
- `deleteItemsAtomically()` 核心删除引擎不变
- 分享页的 `window.confirm()` 暂不替换（独立页面，影响小）
