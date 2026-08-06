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
            <a href="#/dashboard" data-nav="dashboard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg><span>仪表盘</span></a>
            <a href="#/servers" data-nav="servers"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><circle cx="7.5" cy="7.5" r="1" fill="currentColor" stroke="none"/><circle cx="7.5" cy="16.5" r="1" fill="currentColor" stroke="none"/></svg><span>服务器</span></a>
            <a href="#/plans" data-nav="plans"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg><span>产品方案</span></a>
            <a href="#/zjmf" data-nav="zjmf"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg><span>魔方上游</span></a>
            <a href="#/orders" data-nav="orders"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>订单</span></a>
            <a href="#/products" data-nav="products"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg><span>产品</span></a>
            <a href="#/users" data-nav="users"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M21.5 20a6.5 6.5 0 0 0-4-6"/></svg><span>用户</span></a>
            <a href="#/tickets" data-nav="tickets"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M15 5v2a2 2 0 0 0 0 4v2a2 2 0 0 0 0 4v2"/></svg><span>工单</span></a>
            <a href="#/settings" data-nav="settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg><span>系统设置</span></a>
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
