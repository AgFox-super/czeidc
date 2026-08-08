<?php
/**
 * 前台单页入口（全部交互走 AJAX，仅加载一次页面）
 */
define('XNZJ_BOOT', true);
require __DIR__ . '/includes/boot.php';

// 管理员模拟登录：一次性签名链接（HMAC(APP_KEY) 签名 + 5分钟有效，链接在后台用户管理中生成）
if (isset($_GET['login_as']) && is_string($_GET['login_as'])) {
    $parts = explode('.', $_GET['login_as']);
    if (count($parts) === 3) {
        list($uid, $exp, $sig) = $parts;
        if ((int)$exp > time() && hash_equals(hash_hmac('sha256', $uid . '.' . $exp, APP_KEY), $sig)) {
            $st = db()->prepare('SELECT id FROM ' . t('users') . ' WHERE id = ? AND status = 1');
            $st->execute(array((int)$uid));
            if ($st->fetch()) {
                $_SESSION['uid'] = (int)$uid;
                header('Location: index.php');
                exit;
            }
        }
    }
}

$site = setting('site_name', '沧舟云虚拟主机销售系统');
$logo = setting('logo', '');
$slogan = setting('site_slogan', '');
$me = current_user();
$boot = array(
    'site' => $site,
    'logo' => $logo,
    'slogan' => $slogan,
    'me' => $me ? array('id' => (int)$me['id'], 'email' => $me['email']) : null,
    'csrf' => csrf_token(),
    'payConfigured' => yipay_configured(),
);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?php echo h(csrf_token()); ?>">
<title><?php echo h($site); ?></title>
<link rel="stylesheet" href="assets/css/app.css">
<link rel="icon" href="<?php echo h($logo ? $logo : 'data:,'); ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="#/">
            <?php if ($logo): ?><img class="brand-logo" src="<?php echo h($logo); ?>" alt="" onerror="this.style.display='none'"><?php endif; ?>
            <span class="brand-name"><?php echo h($site); ?></span>
        </a>
        <button class="nav-toggle" id="navToggle" aria-label="菜单"><span></span><span></span><span></span></button>
        <nav class="nav" id="nav">
            <a href="#/" data-nav="home">首页</a>
            <a href="#/products" data-nav="products">我的产品</a>
            <a href="#/tickets" data-nav="tickets">工单</a>
            <a href="#/profile" data-nav="profile">个人中心</a>
        </nav>
        <div class="nav-right" id="navRight"></div>
    </div>
</header>

<main id="view" class="container"></main>

<footer class="footer">© <?php echo date('Y'); ?> <?php echo h($site); ?> · Powered by xnzj</footer>

<div id="modal" class="modal-mask" style="display:none">
    <div class="modal-box" id="modalBox"></div>
</div>
<div id="toast" class="toast-wrap"></div>

<script>window.BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="assets/js/app.js"></script>
</body>
</html>
