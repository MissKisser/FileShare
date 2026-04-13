# UI 重构实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将项目 UI 从深色赛博朋克风格重构为简约现代的轻盈色块设计

**Architecture:** 左侧 200px 固定侧边栏 + 右侧主内容区（统计条 + 双列卡片 + 列表 + 日志），浅灰白背景配低饱和度色块，响应式支持移动端抽屉菜单

**Tech Stack:** 原生 CSS（无框架依赖），PHP 模板，响应式媒体查询

---

## 文件修改清单

| 操作 | 文件 |
|------|------|
| 重写 | `assets/css/style.css` |
| 重构 | `templates/main.php` |
| 重建 | `assets/css/main-min.css` |
| 更新 | `frontend/assets/sass/styles.scss` |

---

### Task 1: 重写 CSS 变量和基础样式

**Files:**
- Modify: `assets/css/style.css`（完全重写，保留路径引用）

- [ ] **Step 1: 定义 CSS 变量和重置样式**

```css
/* ========================================
   文件上传与文本存储系统 - 简约现代风格
   作者：Hackerdallas
   ======================================== */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    /* 基础色板 */
    --bg-page: #F8F9FA;
    --bg-card: #FFFFFF;
    --text-primary: #1A1A2E;
    --text-secondary: #6B7280;
    --text-muted: #9CA3AF;
    --border-color: #E5E7EB;
    --border-hover: #D1D5DB;

    /* 功能区配色 */
    --accent-upload-bg: #E8F4FD;
    --accent-upload-border: #BFDBFE;
    --accent-upload-text: #2563EB;
    --accent-text-bg: #E8F5E9;
    --accent-text-border: #BBF7D0;
    --accent-text-text: #16A34A;
    --accent-overview-border: #FDE68A;
    --accent-log-bg: #FFF3E0;
    --accent-log-border: #FED7AA;
    --accent-log-text: #EA580C;

    /* 侧边栏 */
    --sidebar-bg: #FFFFFF;
    --sidebar-accent: #3B82F6;
    --sidebar-hover: #EFF6FF;

    /* 按钮 */
    --btn-primary-bg: #3B82F6;
    --btn-primary-hover: #2563EB;
    --btn-secondary-bg: #F3F4F6;
    --btn-secondary-hover: #E5E7EB;
    --btn-danger-bg: #EF4444;
    --btn-danger-hover: #DC2626;
    --btn-success-bg: #16A34A;
    --btn-success-hover: #15803D;

    /* 阴影 */
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-lg: 0 10px 40px rgba(0,0,0,0.08);

    /* 圆角 */
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;

    /* 过渡 */
    --transition: all 0.2s ease;
}

body {
    font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg-page);
    min-height: 100vh;
    color: var(--text-primary);
    line-height: 1.6;
}
```

- [ ] **Step 2: 写入手势和选择器样式**

```css
/* 手势 */
a { text-decoration: none; color: inherit; }
button { cursor: pointer; font-family: inherit; border: none; outline: none; }

/* 选中 */
::selection { background: var(--accent-upload-text); color: white; }

/* 滚动条 */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }
```

---

### Task 2: 创建侧边栏样式

**Files:**
- Modify: `assets/css/style.css`（追加）

- [ ] **Step 1: 侧边栏基础布局**

```css
/* 侧边栏 */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 200px;
    height: 100vh;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    z-index: 100;
}

.sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid var(--border-color);
}

.sidebar-logo {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.sidebar-version {
    font-size: 12px;
    color: var(--text-muted);
}

.sidebar-nav {
    flex: 1;
    padding: 16px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.sidebar-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    color: var(--text-secondary);
    transition: var(--transition);
    background: transparent;
    width: 100%;
    text-align: left;
}

.sidebar-btn:hover {
    background: var(--sidebar-hover);
    color: var(--sidebar-accent);
}

.sidebar-btn svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-color);
}

.sidebar-stat {
    font-size: 12px;
    color: var(--text-muted);
}

.sidebar-stat strong {
    color: var(--text-primary);
    font-weight: 600;
}
```

- [ ] **Step 2: 移动端抽屉样式**

```css
/* 移动端侧边栏抽屉 */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 99;
}

.sidebar-toggle {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    z-index: 101;
    width: 44px;
    height: 44px;
    background: var(--sidebar-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
}

@media (max-width: 1023px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }

    .sidebar.sidebar-open {
        transform: translateX(0);
    }

    .sidebar-overlay.sidebar-overlay-active {
        display: block;
    }

    .sidebar-toggle {
        display: flex;
    }

    .main-content {
        margin-left: 0 !important;
        padding-top: 76px;
    }
}
```

---

### Task 3: 创建主内容区布局

**Files:**
- Modify: `assets/css/style.css`（追加）

- [ ] **Step 1: 主容器和统计条**

```css
/* 主内容区 */
.main-content {
    margin-left: 200px;
    min-height: 100vh;
    padding: 32px;
}

/* 统计条 */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    box-shadow: var(--shadow-sm);
}

.stat-label {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-card:nth-child(1) .stat-value { color: var(--accent-upload-text); }
.stat-card:nth-child(2) .stat-value { color: var(--accent-text-text); }
.stat-card:nth-child(3) .stat-value { color: var(--accent-log-text); }

@media (max-width: 640px) {
    .stats-bar {
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .stat-card { padding: 14px; }
    .stat-value { font-size: 20px; }
    .stat-label { font-size: 11px; }
}
```

- [ ] **Step 2: 栅格卡片布局**

```css
/* 栅格布局 */
.grid-layout {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.section-full {
    grid-column: 1 / -1;
}

@media (max-width: 768px) {
    .grid-layout {
        grid-template-columns: 1fr;
    }
}
```

---

### Task 4: 卡片和表单组件样式

**Files:**
- Modify: `assets/css/style.css`（追加）

- [ ] **Step 1: 通用卡片样式**

```css
/* 卡片 */
.grid-card {
    background: var(--bg-card);
    border-radius: var(--radius-md);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    transition: var(--transition);
}

.grid-card:hover {
    box-shadow: var(--shadow-md);
}

.grid-card h2 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
}

.grid-card h2 svg {
    width: 20px;
    height: 20px;
}

/* 色块卡片 */
.card-upload {
    background: var(--accent-upload-bg);
    border-color: var(--accent-upload-border);
}

.card-upload h2 { color: var(--accent-upload-text); }

.card-text {
    background: var(--accent-text-bg);
    border-color: var(--accent-text-border);
}

.card-text h2 { color: var(--accent-text-text); }
```

- [ ] **Step 2: 表单元素**

```css
/* 表单 */
.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

input[type="file"],
input[type="text"],
input[type="password"],
select,
textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: inherit;
    background: var(--bg-card);
    color: var(--text-primary);
    transition: var(--transition);
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: var(--sidebar-accent);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

textarea {
    min-height: 140px;
    resize: vertical;
    line-height: 1.6;
}

select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}

input[type="file"]::file-selector-button {
    background: var(--btn-primary-bg);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    margin-right: 12px;
    transition: var(--transition);
}

input[type="file"]::file-selector-button:hover {
    background: var(--btn-primary-hover);
}
```

- [ ] **Step 3: 按钮样式**

```css
/* 按钮 */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    transition: var(--transition);
    border: none;
}

.btn-primary {
    background: var(--btn-primary-bg);
    color: white;
}

.btn-primary:hover {
    background: var(--btn-primary-hover);
    box-shadow: var(--shadow-sm);
}

.btn-secondary {
    background: var(--btn-secondary-bg);
    color: var(--text-primary);
}

.btn-secondary:hover {
    background: var(--btn-secondary-hover);
}

.btn-success {
    background: var(--btn-success-bg);
    color: white;
}

.btn-success:hover {
    background: var(--btn-success-hover);
}

.btn-danger {
    background: var(--btn-danger-bg);
    color: white;
}

.btn-danger:hover {
    background: var(--btn-danger-hover);
}

/* 小按钮 */
.btn-small {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-small svg {
    width: 14px;
    height: 14px;
}

/* 按钮组 */
.btn-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
```

---

### Task 5: 列表、日志和弹窗样式

**Files:**
- Modify: `assets/css/style.css`（追加）

- [ ] **Step 1: 列表区块**

```css
/* 列表 */
.list {
    background: var(--bg-card);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    transition: var(--transition);
}

.item:last-child {
    border-bottom: none;
}

.item:hover {
    background: var(--bg-page);
}

.item-info {
    flex: 1;
    min-width: 0;
}

.item-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.item-name svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--text-muted);
}

.item-meta {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.text-preview {
    background: var(--bg-page);
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-top: 10px;
    font-size: 13px;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.item-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.item-actions .btn-small {
    background: var(--btn-secondary-bg);
    color: var(--text-secondary);
}

.item-actions .btn-small:hover {
    background: var(--sidebar-accent);
    color: white;
}

.item-actions .btn-delete:hover {
    background: var(--btn-danger-bg);
}

.item-actions .btn-view:hover {
    background: #8B5CF6;
}

.item-actions .btn-copy:hover {
    background: var(--btn-success-bg);
}

.empty {
    text-align: center;
    color: var(--text-muted);
    padding: 48px 24px;
    font-size: 14px;
}
```

- [ ] **Step 2: 日志表格**

```css
/* 日志 */
.log-container {
    background: var(--accent-log-bg);
    border-radius: var(--radius-md);
    border: 1px solid var(--accent-log-border);
    overflow-x: auto;
}

.log-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.log-table thead {
    background: var(--accent-log-text);
}

.log-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    font-size: 12px;
}

.log-table tbody tr {
    border-bottom: 1px solid var(--accent-log-border);
}

.log-table tbody tr:last-child {
    border-bottom: none;
}

.log-table tbody tr:hover {
    background: rgba(255,255,255,0.5);
}

.log-table td {
    padding: 12px 16px;
    color: var(--text-primary);
}

.log-filename {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
}
```

- [ ] **Step 3: 消息提示和弹窗**

```css
/* 消息提示 */
.message {
    background: linear-gradient(135deg, #DCFCE7 0%, #F0FDF4 100%);
    color: var(--btn-success-bg);
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    border: 1px solid #BBF7D0;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}

.message svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

/* Toast 提示 */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-lg);
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 1000;
    transform: translateX(120%);
    transition: transform 0.3s ease;
}

.toast.toast-show {
    transform: translateX(0);
}

.toast-success { border-left: 4px solid var(--btn-success-bg); }
.toast-error { border-left: 4px solid var(--btn-danger-bg); }
.toast-info { border-left: 4px solid var(--sidebar-accent); }

/* 确认弹窗 */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

.modal-overlay.modal-show {
    opacity: 1;
    pointer-events: auto;
}

.modal {
    background: var(--bg-card);
    border-radius: var(--radius-md);
    width: 90%;
    max-width: 400px;
    box-shadow: var(--shadow-lg);
    transform: scale(0.95);
    transition: transform 0.2s ease;
}

.modal-overlay.modal-show .modal {
    transform: scale(1);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
}

.modal-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
}

.modal-body {
    padding: 20px;
    color: var(--text-secondary);
    font-size: 14px;
    line-height: 1.6;
}

.modal-footer {
    padding: 16px 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    border-top: 1px solid var(--border-color);
}
```

- [ ] **Step 4: 代码预览弹窗（复用原 drag-upload.css 和 code-modal.css 样式）**

```css
/* 代码预览弹窗 - 复用原样式，只需微调 */
.code-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 1001;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

.code-modal-overlay.modal-show {
    opacity: 1;
    pointer-events: auto;
}

.code-modal {
    background: var(--bg-card);
    border-radius: var(--radius-md);
    width: 90%;
    max-width: 700px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
    transform: scale(0.95);
    transition: transform 0.2s ease;
}

.code-modal-overlay.modal-show .code-modal {
    transform: scale(1);
}

.code-modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.code-modal-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.code-modal-actions {
    display: flex;
    gap: 8px;
}

.code-modal-btn {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
    background: var(--btn-secondary-bg);
    color: var(--text-secondary);
    transition: var(--transition);
}

.code-modal-btn:hover {
    background: var(--btn-secondary-hover);
}

.code-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.code-modal-body pre {
    margin: 0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    line-height: 1.6;
}
```

---

### Task 6: 重构 templates/main.php HTML 结构

**Files:**
- Modify: `templates/main.php`

- [ ] **Step 1: 简化 HTML 结构，添加侧边栏**

```php
<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件上传与文本存储系统</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/main-min.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/drag-upload.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/code-modal.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- 移动端菜单按钮 -->
    <button type="button" class="sidebar-toggle" id="sidebarToggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>

    <!-- 侧边栏遮罩 -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- 侧边栏 -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">文件暂存</div>
            <div class="sidebar-version">v1.0.0</div>
        </div>
        <nav class="sidebar-nav">
            <button type="button" class="sidebar-btn" id="btnUpload">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17,8 12,3 7,8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                上传文件
            </button>
            <button type="button" class="sidebar-btn" id="btnText">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14,2 14,8 20,8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                新建文本
            </button>
            <button type="button" class="sidebar-btn" id="btnRefresh">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23,4 23,10 17,10"/>
                    <polyline points="1,20 1,14 7,14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                刷新列表
            </button>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-stat">
                当前存储: <strong><?php echo count($data); ?></strong> 项
            </div>
        </div>
    </aside>

    <!-- 拖拽上传面板 -->
    <div id="dragOverlay" class="drag-overlay">
        <div class="drag-content">
            <div class="drag-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17,8 12,3 7,8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
            </div>
            <div class="drag-text">松开鼠标开始上传</div>
            <div class="drag-hint">支持任何格式文件</div>
        </div>
    </div>

    <div id="dragFilePanel" class="drag-file-panel">
        <div class="drag-file-header">
            <span class="drag-file-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                </svg>
                排队上传 (<span id="dragFileCount">0</span>)
            </span>
            <button type="button" id="clearAllFiles" class="btn-clear-all">清空</button>
        </div>
        <div id="dragFileList" class="drag-file-list"></div>
        <div class="drag-file-footer">
            <span id="dragTotalSize" class="drag-total-size">0 B</span>
            <div class="drag-file-actions">
                <button type="button" id="cancelDragUpload" class="btn-cancel-upload">取消</button>
                <button type="button" id="startDragUpload" class="btn-start-upload">提交</button>
            </div>
        </div>
    </div>

    <div id="uploadProgressPanel" class="upload-progress-panel">
        <div class="progress-panel-header">
            <span class="progress-title">上传处理中...</span>
            <button type="button" id="cancelUpload" class="btn-cancel-upload">中止</button>
        </div>
        <div class="progress-overall">
            <div class="progress-label">
                <span>总进度</span><span id="overallProgress">0%</span>
            </div>
            <div class="progress-bar-container"><div id="overallProgressBar" class="progress-bar-fill"></div></div>
        </div>
        <div id="fileProgressList" class="file-progress-list"></div>
    </div>

    <div id="successPanel" class="success-panel">
        <div class="success-content">
            <div class="success-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="9,12 12,15 16,10"/>
                </svg>
            </div>
            <div class="success-title">传输完成</div>
            <div id="successMessage" class="success-message"></div>
            <div class="success-actions">
                <button type="button" id="viewFilesBtn" class="btn-view-files">刷新列表</button>
                <button type="button" id="continueUploadBtn" class="btn-continue-upload">继续</button>
            </div>
        </div>
    </div>

    <div id="codeModal" class="code-modal-overlay">
        <div class="code-modal">
            <div class="code-modal-header">
                <div class="code-modal-title">快速预览</div>
                <div class="code-modal-actions">
                    <button type="button" id="codeModalCopy" class="code-modal-btn">复制</button>
                    <button type="button" id="codeModalClose" class="code-modal-btn">关闭</button>
                </div>
            </div>
            <div class="code-modal-body">
                <pre class="line-numbers"><code id="codeModalContent" class="language-plaintext"></code></pre>
            </div>
        </div>
    </div>

    <!-- 主内容区 -->
    <main class="main-content">
        <!-- 统计条 -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-label">文件存储</div>
                <div class="stat-value"><?php echo count(array_filter($data, fn($i) => $i['type'] === 'file')); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">文本片段</div>
                <div class="stat-value"><?php echo count(array_filter($data, fn($i) => $i['type'] === 'text')); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">总项目数</div>
                <div class="stat-value"><?php echo count($data); ?></div>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- 功能卡片区 -->
        <div class="grid-layout">
            <!-- 文件上传卡片 -->
            <div class="grid-card card-upload" id="uploadCard">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17,8 12,3 7,8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    文件寄送
                </h2>
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>选择需上传的文件</label>
                        <input type="file" name="files[]" id="fileInput" multiple required accept="*/*">
                    </div>
                    <div class="form-group">
                        <label>访问时效</label>
                        <select name="duration">
                            <option value="600">10分钟</option>
                            <option value="3600">1小时</option>
                            <option value="86400">1天</option>
                            <option value="0">无限制</option>
                        </select>
                    </div>
                    <div id="selectedFilesContainer" class="selected-files-container" style="display: none;">
                        <div class="selected-files-header">
                            <span class="selected-files-title">队列 (<span id="selectedFileCount">0</span>)</span>
                            <button type="button" id="clearSelectedFiles" class="btn-clear-selected">清空</button>
                        </div>
                        <div id="selectedFileList" class="selected-file-list"></div>
                        <div class="selected-files-footer">
                            <span id="selectedTotalSize" class="selected-total-size">0 B</span>
                        </div>
                    </div>
                    <div id="progressContainer" style="display: none;">
                        <div class="progress-stats">
                            <span id="statusText">传输中...</span>
                            <span id="progressText">0%</span>
                        </div>
                        <div class="progress-track"><div id="progressBar"></div></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">确认上传</button>
                </form>
            </div>

            <!-- 文本共享卡片 -->
            <div class="grid-card card-text" id="textCard">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    剪贴板共享
                </h2>
                <form method="POST">
                    <div class="form-group">
                        <label>纯文本内容</label>
                        <textarea name="text" placeholder="输入或粘贴文本内容..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>留存时间</label>
                        <select name="text_duration">
                            <option value="600" selected>10分钟</option>
                            <option value="3600">1小时</option>
                            <option value="86400">1天</option>
                            <option value="0">无限制</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success">持久化文本</button>
                </form>
            </div>

            <!-- 存储概览 -->
            <div class="grid-card section-full">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                        <polyline points="13,2 13,9 20,9"/>
                    </svg>
                    存储概览
                </h2>
                <div class="list">
                    <?php if (empty($data)): ?>
                        <div class="empty">云端无挂载数据</div>
                    <?php else: ?>
                        <?php foreach ($data as $index => $item): ?>
                            <div class="item">
                                <div class="item-info">
                                    <div class="item-name">
                                        <?php if ($item['type'] === 'file'): ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                                                <polyline points="13,2 13,9 20,9"/>
                                            </svg>
                                            <?php echo htmlspecialchars($item['name']); ?>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <polyline points="14,2 14,8 20,8"/>
                                                <line x1="16" y1="13" x2="8" y2="13"/>
                                                <line x1="16" y1="17" x2="8" y2="17"/>
                                            </svg>
                                            文本片段
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-meta">
                                        <?php if ($item['type'] === 'file'): ?>
                                            <?php echo formatSize($item['size']); ?> •
                                        <?php endif; ?>
                                        入库: <?php echo date('Y-m-d H:i', $item['time']); ?> •
                                        剩余: <?php echo formatExpire($item['expire']); ?>
                                        <?php if (isset($item['ip'])): ?>
                                            • <?php echo htmlspecialchars($item['ip']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($item['type'] === 'text'): ?>
                                        <div class="text-preview">
                                            <pre><?php echo htmlspecialchars(mb_substr($item['content'], 0, 150)); ?><?php if (mb_strlen($item['content']) > 150) echo '...'; ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="item-actions">
                                    <?php if ($item['type'] === 'file'): ?>
                                        <a href="?download=<?php echo $index; ?>" class="btn-small btn-secondary">拉取</a>
                                    <?php else: ?>
                                        <button class="btn-small btn-secondary btn-view" data-index="<?php echo $index; ?>" data-content="<?php echo htmlspecialchars($item['content'], ENT_QUOTES, 'UTF-8'); ?>">展开</button>
                                        <button class="btn-small btn-secondary btn-copy" data-content="<?php echo htmlspecialchars($item['content'], ENT_QUOTES, 'UTF-8'); ?>">入板</button>
                                    <?php endif; ?>
                                    <button class="btn-small btn-danger btn-delete" data-delete-url="?delete=<?php echo $index; ?>">移除</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 上行日志 -->
            <div class="grid-card section-full">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    上行日志审计
                </h2>
                <div class="log-container">
                    <?php if (empty($uploadLogs)): ?>
                        <div class="empty">审计队列为空</div>
                    <?php else: ?>
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>时间戳</th>
                                    <th>资产</th>
                                    <th>大小</th>
                                    <th>远端</th>
                                    <th>策略</th>
                                    <th>TTL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploadLogs as $log): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', $log['upload_time']); ?></td>
                                        <td class="log-filename"><?php echo htmlspecialchars($log['filename']); ?></td>
                                        <td><?php echo formatSize($log['filesize']); ?></td>
                                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo formatDuration($log['duration']); ?></td>
                                        <td><?php echo ($log['expire_time'] === 0) ? '持久化' : date('Y-m-d H:i:s', $log['expire_time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
    <script src="assets/js/upload.js?v=<?php echo time(); ?>"></script>
    <script>
        // 侧边栏移动端交互
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('sidebar-open');
            document.getElementById('sidebarOverlay').classList.toggle('sidebar-overlay-active');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('sidebar-open');
            this.classList.remove('sidebar-overlay-active');
        });

        // 快捷按钮
        document.getElementById('btnRefresh').addEventListener('click', function() {
            location.reload();
        });

        document.getElementById('btnUpload').addEventListener('click', function() {
            document.getElementById('fileInput').click();
        });

        document.getElementById('btnText').addEventListener('click', function() {
            document.querySelector('textarea[name="text"]').focus();
        });
    </script>
</body>
</html>
```

- [ ] **Step 2: 验证 HTML 完整性**

确认所有区块闭合：`sidebar`、`main-content`、`grid-layout`、所有 `.grid-card`

---

### Task 7: 更新 SCSS 文件

**Files:**
- Modify: `frontend/assets/sass/styles.scss`

- [ ] **Step 1: 更新 SCSS 变量**

```scss
// 简约现代配色方案
$bg-page: #F8F9FA;
$bg-card: #FFFFFF;
$text-primary: #1A1A2E;
$text-secondary: #6B7280;
$text-muted: #9CA3AF;
$border-color: #E5E7EB;

$accent-upload-bg: #E8F4FD;
$accent-upload-text: #2563EB;
$accent-text-bg: #E8F5E9;
$accent-text-text: #16A34A;
$accent-log-bg: #FFF3E0;
$accent-log-text: #EA580C;

$sidebar-accent: #3B82F6;
$sidebar-hover: #EFF6FF;

$btn-primary: #3B82F6;
$btn-success: #16A34A;
$btn-danger: #EF4444;

$shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
$shadow-md: 0 4px 20px rgba(0,0,0,0.06);
$shadow-lg: 0 10px 40px rgba(0,0,0,0.08);

$radius-sm: 8px;
$radius-md: 12px;
$radius-lg: 16px;
```

---

### Task 8: 验证和测试

**Files:**
- 测试: `templates/main.php`

- [ ] **Step 1: 启动本地服务器测试**

Run: `php -S localhost:9000`
验证: 访问 http://localhost:9000

- [ ] **Step 2: 检查响应式断点**

在浏览器 DevTools 中测试:
- 宽度 >= 1024px: 侧边栏固定显示
- 宽度 640-1023px: 侧边栏隐藏，汉堡菜单出现
- 宽度 < 640px: 单列布局，统计条压缩

- [ ] **Step 3: 验证所有交互**

- [ ] 文件上传功能
- [ ] 文本保存功能
- [ ] 删除功能
- [ ] 复制文本功能
- [ ] 代码预览弹窗
- [ ] Toast 提示

---

**Plan 自检:**

1. **Spec 覆盖检查:**
   - [x] 浅色背景 #F8F9FA
   - [x] 低饱和度色块区分功能区
   - [x] 侧边栏 200px 固定
   - [x] 双列卡片布局
   - [x] 统计条
   - [x] 响应式策略
   - [x] Noto Sans SC + JetBrains Mono 字体

2. **占位符扫描:** 无 TBD/TODO，所有步骤含实际代码

3. **类型一致性:** 所有选择器、class 名在 CSS 和 HTML 中保持一致

---

**Plan 完整，保存至 `docs/superpowers/plans/2026-04-13-ui-redesign-plan.md`**

两种执行方式可选：

**1. Subagent-Driven（推荐）** — 每步派发独立 subagent，步间复核，快速迭代

**2. Inline Execution** — 当前会话批量执行，设检查点复核

选择哪种方式？