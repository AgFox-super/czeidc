<?php
/**
 * 管理后台入口（安装时复制到 {后台路径}/index.php）
 */
define('XNZJ_BOOT', true);
define('XNZJ_ADMIN_DIR', __DIR__);
require dirname(__DIR__) . '/includes/boot.php';

$site = setting('site_name', '沧舟云虚拟主机销售系统');
$me = current_admin();
$boot = array(
    'site' => $site,
    'csrf' => csrf_token(),
    'me' => $me ? array('id' => (int)$me['id'], 'username' => $me['username']) : null,
);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?php echo h(csrf_token()); ?>">
<title>管理后台 - <?php echo h($site); ?></title>
<link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="admin-body">
<div class="admin-layout" id="adminLayout" style="display:none">
    <aside class="sidebar">
        <div class="sidebar-brand"><?php echo h($site); ?><small>管理后台</small></div>
        <nav class="sidebar-nav" id="adminNav">
            <a href="#/dashboard" data-nav="dashboard">📊 仪表盘</a>
            <a href="#/servers" data-nav="servers">🖥 服务器</a>
            <a href="#/plans" data-nav="plans">📦 产品方案</a>
            <a href="#/zjmf" data-nav="zjmf">🔗 魔方上游</a>
            <a href="#/orders" data-nav="orders">🧾 订单</a>
            <a href="#/products" data-nav="products">🚀 产品</a>
            <a href="#/users" data-nav="users">👤 用户</a>
            <a href="#/tickets" data-nav="tickets">🎫 工单</a>
            <a href="#/settings" data-nav="settings">⚙️ 系统设置</a>
        </nav>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <span id="adminPageTitle">仪表盘</span>
            <div class="admin-user">
                <span id="adminUserName"></span>
                <a href="javascript:;" id="btnLogout">退出</a>
            </div>
        </header>
        <main id="view" class="container admin-view"></main>
    </div>
</div>
<div id="loginView" class="admin-login" style="display:none">
    <div class="card" style="max-width:380px">
        <div class="admin-login-title"><?php echo h($site); ?> · 管理后台</div>
        <label>管理员账号</label>
        <input type="text" id="loginUser" autocomplete="username">
        <label>密码</label>
        <input type="password" id="loginPass" autocomplete="current-password">
        <div id="loginMsg" class="msg" style="display:none"></div>
        <button class="btn" id="btnLogin">登 录</button>
    </div>
</div>
<div id="modal" class="modal-mask" style="display:none">
    <div class="modal-box" id="modalBox"></div>
</div>
<div id="toast" class="toast-wrap"></div>
<script>window.BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="../assets/js/admin.js"></script>
</body>
</html>
