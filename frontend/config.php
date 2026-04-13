<?php
/**
 * 前端配置
 * 作者：Hackerdallas
 */

// 站点标题
define('title', '小熊的短链接网站');
define('shortTitle', '短链');
define('description', 'A quick description on why your site is so fantastic, what it does and why people should definitely start using it. Oh, and how it\'s free.');
define('favicon', '/frontend/assets/img/favicon.ico');
define('logo', '/frontend/assets/img/logo-black.png');

// reCAPTCHA V3配置
define("enableRecaptcha", false);
define("recaptchaV3SiteKey", 'YOUR_SITE_KEY_HERE');
define("recaptchaV3SecretKey", 'YOUR_SECRET_KEY_HERE');

// 认证与功能开关
define('requireAuth', false);
define('enableCustomURL', true);

// 主题配色
define('colour', '#007bff');

// 背景图片（可选）
// define('backgroundImage', 'https://picsum.photos/1920/1080');

// 页脚链接
$footerLinks = [
    "About"   =>  "https://sleeky.flynntes.com/",
    "Contact" =>  "https://yourls.org/",
    "Legal"   =>  "https://yourls.org/",
    "Admin"   =>  "/admin"
];
?>
