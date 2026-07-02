# FileShare API 文档

## 概述

FileShare 提供 RESTful API 接口，支持通过命令行工具或脚本进行文件上传、文本保存、项目管理等操作。

**基础 URL：** `https://your-domain.com/index.php`

所有 API 端点通过 `?api={endpoint}` 参数访问。

---

## 认证

### 获取 Token

**POST** `?api=auth/token`

使用管理员密码换取 access_token 和 refresh_token。

**请求体（JSON）：**
```json
{
  "password": "your-admin-password"
}
```

**响应：**
```json
{
  "success": true,
  "access_token": "a1b2c3d4...",
  "access_token_expires_at": 1700000000,
  "refresh_token": "e5f6g7h8...",
  "refresh_token_expires_at": 1700604800,
  "token_type": "Bearer",
  "expires_in": 3600
}
```

- `access_token` 有效期 1 小时
- `refresh_token` 有效期 7 天

### 刷新 Token

**POST** `?api=auth/refresh`

**请求体（JSON）：**
```json
{
  "refresh_token": "e5f6g7h8..."
}
```

**响应：**
```json
{
  "success": true,
  "access_token": "new-token...",
  "access_token_expires_at": 1700003600,
  "token_type": "Bearer",
  "expires_in": 3600
}
```

### 使用 Token

在需要认证的请求中，通过以下方式之一传递 Token：

1. **Authorization Header（推荐）：**
   ```
   Authorization: Bearer {access_token}
   ```

2. **查询参数：**
   ```
   ?api=items&token={access_token}
   ```

---

## 项目操作

### 列出项目

**GET** `?api=items`

**查询参数：**

| 参数 | 类型 | 默认 | 说明 |
|------|------|------|------|
| `q` | string | "" | 搜索关键词（匹配文件名或文本内容） |
| `type` | string | "all" | 类型过滤：all / file / text |
| `category` | string | "" | 分类过滤：image / video / audio / doc / code / archive |
| `sort` | string | "time" | 排序字段：time / size / name / download_count |
| `order` | string | "desc" | 排序方向：desc / asc |
| `page` | int | 1 | 页码 |
| `per_page` | int | 20 | 每页数量（最大 100） |

**响应：**
```json
{
  "success": true,
  "items": [
    {
      "id": 1,
      "share_code": "a1b2c3d4",
      "share_url": "https://your-domain.com/?s=a1b2c3d4",
      "type": "file",
      "name": "example.pdf",
      "size": 1048576,
      "size_formatted": "1 MB",
      "mime_type": "application/pdf",
      "has_password": false,
      "download_count": 5,
      "ip": "192.168.***.***",
      "time": 1700000000,
      "time_formatted": "2023-11-15 00:00:00",
      "expire": 1700086400,
      "expire_formatted": "1天",
      "content_preview": null
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 42,
    "total_pages": 3
  }
}
```

### 获取单个项目

**GET** `?api=item&id={id}`

**响应：**
```json
{
  "success": true,
  "item": { ... }
}
```

### 删除项目

**DELETE** `?api=item&id={id}`

**响应：**
```json
{
  "success": true,
  "message": "删除成功"
}
```

---

## 文件上传

**POST** `?api=upload`

使用 `multipart/form-data` 格式提交。

**Header：**
```
Authorization: Bearer {access_token}
Content-Type: multipart/form-data
```

**表单字段：**

| 字段 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `files[]` | file | 是 | 上传的文件（支持多文件） |
| `duration` | int | 否 | 保留时长秒数，默认 600（10分钟） |
| `access_password` | string | 否 | 访问密码（留空则公开） |

**cURL 示例：**
```bash
# 先获取 Token
TOKEN=$(curl -s -X POST 'https://your-domain.com/?api=auth/token' \
  -H 'Content-Type: application/json' \
  -d '{"password":"your-admin-password"}' | jq -r '.access_token')

# 上传文件
curl -X POST 'https://your-domain.com/?api=upload' \
  -H "Authorization: Bearer $TOKEN" \
  -F 'files[]=@/path/to/file.pdf' \
  -F 'duration=86400'
```

**响应：**
```json
{
  "success": true,
  "message": "成功上传 1 个文件",
  "uploaded": 1,
  "items": [
    {
      "id": 42,
      "share_code": "e5f6g7h8",
      "share_url": "https://your-domain.com/?s=e5f6g7h8",
      "type": "file",
      "name": "file.pdf",
      "size": 1048576,
      "size_formatted": "1 MB"
    }
  ],
  "errors": []
}
```

---

## 文本保存

**POST** `?api=text`

**Header：**
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**请求体：**
```json
{
  "text": "你的文本内容...",
  "duration": 86400,
  "access_password": ""
}
```

**cURL 示例：**
```bash
curl -X POST 'https://your-domain.com/?api=text' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"text":"Hello, World!","duration":3600}'
```

**响应：**
```json
{
  "success": true,
  "message": "文本保存成功",
  "item": {
    "id": 43,
    "share_code": "i9j0k1l2",
    "share_url": "https://your-domain.com/?s=i9j0k1l2",
    "type": "text",
    "content_preview": "Hello, World!"
  }
}
```

---

## 统计信息

**GET** `?api=stats`

**响应：**
```json
{
  "success": true,
  "stats": {
    "total_items": 42,
    "file_count": 30,
    "text_count": 12,
    "total_size": 104857600,
    "total_size_formatted": "100 MB",
    "disk_usage": 104857600,
    "disk_usage_formatted": "100 MB",
    "category_sizes": {
      "image": 52428800,
      "video": 31457280,
      "audio": 0,
      "doc": 10485760,
      "code": 5242880,
      "archive": 5242880
    },
    "daily_uploads": [
      { "day": "2023-11-15", "cnt": 5 },
      { "day": "2023-11-14", "cnt": 3 }
    ]
  }
}
```

---

## 错误响应

所有错误响应包含 `error` 字段和对应的 HTTP 状态码：

| 状态码 | 说明 |
|--------|------|
| 400 | 请求参数错误 |
| 401 | 未认证或 Token 无效/过期 |
| 403 | 权限不足 |
| 404 | 端点或资源不存在 |
| 405 | 不支持的请求方法 |
| 500 | 服务器内部错误 |

**示例：**
```json
{
  "error": "无效的 Token"
}
```

---

## 分享链接

每个上传的文件或保存的文本都会自动生成分享链接：

- **格式：** `https://your-domain.com/?s={share_code}`
- **share_code：** 8 位随机十六进制字符串
- 如设置了访问密码，访问分享页面时需要输入密码

---

## 完整使用示例

```bash
#!/bin/bash
# FileShare API 使用示例

BASE_URL="https://your-domain.com"
ADMIN_PWD="your-admin-password"

# 1. 获取 Token
echo "=== 获取 Token ==="
RESPONSE=$(curl -s -X POST "${BASE_URL}/?api=auth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${ADMIN_PWD}\"}")

TOKEN=$(echo "$RESPONSE" | jq -r '.access_token')
REFRESH=$(echo "$RESPONSE" | jq -r '.refresh_token')
echo "Token: ${TOKEN:0:20}..."

# 2. 上传文件
echo -e "\n=== 上传文件 ==="
curl -s -X POST "${BASE_URL}/?api=upload" \
  -H "Authorization: Bearer $TOKEN" \
  -F 'files[]=@./test.pdf' \
  -F 'duration=86400' | jq .

# 3. 保存文本
echo -e "\n=== 保存文本 ==="
curl -s -X POST "${BASE_URL}/?api=text" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"text":"echo Hello World","duration":3600}' | jq .

# 4. 列出项目
echo -e "\n=== 列出项目 ==="
curl -s "${BASE_URL}/?api=items&per_page=5" \
  -H "Authorization: Bearer $TOKEN" | jq '.items[] | {id, name: (.name // "text"), share_url}'

# 5. 获取统计
echo -e "\n=== 统计信息 ==="
curl -s "${BASE_URL}/?api=stats" \
  -H "Authorization: Bearer $TOKEN" | jq '.stats | {total_items, disk_usage_formatted}'

# 6. 刷新 Token
echo -e "\n=== 刷新 Token ==="
curl -s -X POST "${BASE_URL}/?api=auth/refresh" \
  -H 'Content-Type: application/json' \
  -d "{\"refresh_token\":\"${REFRESH}\"}" | jq '{success, expires_in}'
```
