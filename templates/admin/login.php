<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - <?php echo htmlspecialchars(SITE_TITLE); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/reset.css?v=<?php echo time(); ?>">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--bg-secondary);
            font-family: 'Noto Sans SC', sans-serif;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 40px;
            width: 100%;
            max-width: 380px;
            box-shadow: var(--card-shadow);
        }
        .login-card h1 {
            text-align: center;
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .login-card p {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 24px;
            font-size: 14px;
        }
        .login-card input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 14px;
            box-sizing: border-box;
            margin-bottom: 16px;
        }
        .login-card input:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        .login-card button {
            width: 100%;
            padding: 12px;
            background: var(--btn-primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .login-card button:hover { opacity: 0.9; }
        .login-error {
            color: var(--accent-red);
            text-align: center;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .login-back {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
        }
        .login-back:hover { color: var(--accent-blue); }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>管理后台</h1>
        <p>请输入管理员密码</p>
        <?php if (isset($adminError)): ?>
            <div class="login-error"><?php echo htmlspecialchars($adminError); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="管理员密码" required autofocus>
            <button type="submit">登录</button>
        </form>
        <a href="/" class="login-back">返回首页</a>
    </div>
</body>
</html>
