<?php
/**
 * xnzj 安装向导
 * 首次访问：填写数据库信息与管理员信息，自动建库建表、生成配置、创建后台目录
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$root = __DIR__;
$configFile = $root . '/config.php';
$installed = is_file($configFile);

function jout($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function sp($name) { return '`' . str_replace('`', '', $name) . '`'; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

/* ---------- AJAX：测试数据库连接 ---------- */
if ($action === 'test_db' && !$installed) {
    $host = trim(isset($_POST['db_host']) ? $_POST['db_host'] : '');
    $port = (int)(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
    $name = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
    $user = trim(isset($_POST['db_user']) ? $_POST['db_user'] : '');
    $pass = isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '';
    if ($host === '' || $name === '' || $user === '') { jout(array('ok' => false, 'msg' => '请填写完整的数据库信息')); }
    try {
        $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
        ));
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . sp($name) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        jout(array('ok' => true, 'msg' => '连接成功，数据库 ' . $name . ' 可用'));
    } catch (Exception $e) {
        jout(array('ok' => false, 'msg' => '连接失败：' . $e->getMessage()));
    }
}

/* ---------- AJAX：执行安装 ---------- */
if ($action === 'install' && !$installed) {
    $dbHost = trim(isset($_POST['db_host']) ? $_POST['db_host'] : '');
    $dbPort = (int)(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
    $dbName = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
    $dbUser = trim(isset($_POST['db_user']) ? $_POST['db_user'] : '');
    $dbPass = isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '';
    $prefix = trim(isset($_POST['db_prefix']) ? $_POST['db_prefix'] : 'xnzj_');
    $adminPath = trim(isset($_POST['admin_path']) ? $_POST['admin_path'] : '');
    $adminUser = trim(isset($_POST['admin_user']) ? $_POST['admin_user'] : '');
    $adminPass = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';
    $adminPass2 = isset($_POST['admin_pass2']) ? (string)$_POST['admin_pass2'] : '';
    $siteName = trim(isset($_POST['site_name']) ? $_POST['site_name'] : '沧舟云虚拟主机销售系统');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') { jout(array('ok' => false, 'msg' => '请填写完整的数据库信息')); }
    if (!preg_match('/^[a-zA-Z0-9_]{0,20}$/', $prefix)) { jout(array('ok' => false, 'msg' => '表前缀只能包含字母、数字、下划线')); }
    if (!preg_match('/^[a-zA-Z0-9_\-]{2,30}$/', $adminPath)) { jout(array('ok' => false, 'msg' => '后台路径只能包含字母、数字、下划线、中划线（2-30位）')); }
    $reserved = array('includes', 'assets', 'install', 'config', 'api', 'index', 'notify', 'return', 'admin_src', 'static');
    if (in_array(strtolower($adminPath), $reserved)) { jout(array('ok' => false, 'msg' => '该后台路径已被系统保留，请更换')); }
    if ($adminPath === 'install.php') { jout(array('ok' => false, 'msg' => '该后台路径不可用')); }
    if (strlen($adminUser) < 3 || strlen($adminUser) > 30) { jout(array('ok' => false, 'msg' => '管理员账号长度需在3-30位')); }
    if (strlen($adminPass) < 6) { jout(array('ok' => false, 'msg' => '管理员密码至少6位')); }
    if ($adminPass !== $adminPass2) { jout(array('ok' => false, 'msg' => '两次输入的管理员密码不一致')); }

    try {
        $pdo = new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';charset=utf8mb4', $dbUser, $dbPass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
        ));
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . sp($dbName) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE ' . sp($dbName));
    } catch (Exception $e) {
        jout(array('ok' => false, 'msg' => '数据库连接失败：' . $e->getMessage()));
    }

    /* 建表 */
    $p = $prefix;
    $schema = array(
        "CREATE TABLE IF NOT EXISTS {$p}users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}admin (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}verify_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            code VARCHAR(10) NOT NULL,
            purpose VARCHAR(20) NOT NULL DEFAULT 'register',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '' COMMENT '发送IP',
            fails TINYINT NOT NULL DEFAULT 0 COMMENT '验证码错误次数',
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}servers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            host VARCHAR(190) NOT NULL COMMENT '面板域名/IP，不带协议',
            username VARCHAR(100) NOT NULL COMMENT '面板账号(EP可不填)',
            secret VARCHAR(255) NOT NULL COMMENT 'API密钥/通信安全码',
            ip VARCHAR(64) NOT NULL DEFAULT '' COMMENT '服务器IP',
            port SMALLINT NOT NULL DEFAULT 80,
            https TINYINT NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            status TINYINT NOT NULL DEFAULT 1,
            type VARCHAR(10) NOT NULL DEFAULT 'bt' COMMENT 'bt=宝塔 ep=EP面板(Easypanel)',
            ep_module VARCHAR(20) NOT NULL DEFAULT 'php' COMMENT 'EP面板语言模板(php/iis)',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            server_id INT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付价格(元)',
            a1 INT NOT NULL DEFAULT 0 COMMENT 'Web空间(MB)',
            a2 INT NOT NULL DEFAULT 0 COMMENT 'SQL空间(MB)',
            a3 INT NOT NULL DEFAULT -1 COMMENT '绑定域名数(-1无限)',
            a4 INT NOT NULL DEFAULT 0 COMMENT '绑定子目录数(0无限)',
            a5 INT NOT NULL DEFAULT 0 COMMENT '流量限制(GB/月)',
            a6 TINYINT NOT NULL DEFAULT 0 COMMENT '产品类型(0虚拟主机)',
            a7 VARCHAR(50) NOT NULL DEFAULT '80,443s' COMMENT '端口',
            a8 TINYINT NOT NULL DEFAULT 0 COMMENT 'Web备份数',
            a9 TINYINT NOT NULL DEFAULT 0 COMMENT 'SQL备份数',
            a10 TINYINT NOT NULL DEFAULT 1 COMMENT '允许绑定子目录(1是0否)',
            note VARCHAR(255) NOT NULL DEFAULT '' COMMENT '前台显示备注',
            sort INT NOT NULL DEFAULT 0,
            status TINYINT NOT NULL DEFAULT 1,
            zjmf_api_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '魔方上游接口ID(0=本地面板)',
            upstream_pid INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上游商品ID',
            upstream_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '上游商品名',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(32) NOT NULL UNIQUE,
            user_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            plan_name VARCHAR(100) NOT NULL DEFAULT '',
            cycle VARCHAR(10) NOT NULL DEFAULT 'month',
            cycle_name VARCHAR(20) NOT NULL DEFAULT '月付',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pay_type VARCHAR(10) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/cancelled',
            trade_no VARCHAR(64) NOT NULL DEFAULT '',
            domain VARCHAR(190) NOT NULL DEFAULT '' COMMENT '绑定域名',
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_user (user_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            server_id INT UNSIGNED NOT NULL DEFAULT 0,
            plan_name VARCHAR(100) NOT NULL DEFAULT '',
            domain VARCHAR(190) NOT NULL DEFAULT '',
            username VARCHAR(100) NOT NULL DEFAULT '' COMMENT '主机账号',
            password TEXT NOT NULL COMMENT 'AES加密后的主机密码',
            status TINYINT NOT NULL DEFAULT 0 COMMENT '1正常 0开通失败 2暂停 3已删除',
            upstream_hostid INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上游主机ID',
            upstream_token VARCHAR(64) NOT NULL DEFAULT '' COMMENT '上游回传验签token',
            upstream_status VARCHAR(20) NOT NULL DEFAULT '' COMMENT '上游主机状态',
            created_at DATETIME NOT NULL,
            activated_at DATETIME NULL,
            expires_at DATETIME NULL,
            UNIQUE KEY uk_order (order_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            subject VARCHAR(190) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open/replied/closed',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}ticket_replies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            admin TINYINT NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}zjmf_apis (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            hostname VARCHAR(190) NOT NULL COMMENT '上游魔方财务地址(带协议)',
            username VARCHAR(190) NOT NULL COMMENT '上游注册账号(手机/邮箱)',
            password TEXT NOT NULL COMMENT 'AES加密后的API密钥',
            status TINYINT NOT NULL DEFAULT 1,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {$p}login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(190) NOT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'user' COMMENT 'user/admin',
            fails TINYINT NOT NULL DEFAULT 0,
            locked_until INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_name_type (username, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
    try {
        foreach ($schema as $sql) { $pdo->exec($sql); }
    } catch (Exception $e) {
        jout(array('ok' => false, 'msg' => '建表失败：' . $e->getMessage()));
    }

    /* 写入配置 */
    $appKey = bin2hex(random_bytes(32));
    $cfgContent = "<?php\n"
        . "if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }\n"
        . "return array(\n"
        . "    'db' => array('host' => " . var_export($dbHost, true) . ", 'port' => " . (int)$dbPort . ", 'name' => " . var_export($dbName, true)
        . ", 'user' => " . var_export($dbUser, true) . ", 'pass' => " . var_export($dbPass, true) . ", 'prefix' => " . var_export($prefix, true) . "),\n"
        . "    'admin_path' => " . var_export($adminPath, true) . ",\n"
        . "    'app_key' => " . var_export($appKey, true) . ",\n"
        . ");\n";
    $tmpFile = $configFile . '.tmp';
    if (@file_put_contents($tmpFile, $cfgContent) === false) {
        jout(array('ok' => false, 'msg' => '无法写入 ' . $configFile . '，请检查目录写入权限'));
    }
    rename($tmpFile, $configFile);

    /* 复制后台目录 */
    $srcAdmin = $root . '/_admin_src';
    $dstAdmin = $root . '/' . $adminPath;
    if (!is_dir($srcAdmin)) {
        @unlink($configFile);
        jout(array('ok' => false, 'msg' => '缺少 _admin_src 目录，安装中止（已回滚配置）'));
    }
    if (!is_dir($dstAdmin) && !@mkdir($dstAdmin, 0755, true)) {
        @unlink($configFile);
        jout(array('ok' => false, 'msg' => '无法创建后台目录 ' . $adminPath . '，请检查目录写入权限'));
    }
    foreach (array('index.php', 'api.php') as $f) {
        $content = @file_get_contents($srcAdmin . '/' . $f);
        if ($content === false || @file_put_contents($dstAdmin . '/' . $f, $content) === false) {
            @unlink($configFile);
            jout(array('ok' => false, 'msg' => '写入后台文件失败，安装中止（已回滚配置）'));
        }
    }
    foreach (array('index.php', 'api.php') as $f) { @unlink($srcAdmin . '/' . $f); }
    @rmdir($srcAdmin);

    /* 初始化数据 */
    try {
        $st = $pdo->prepare("INSERT INTO {$p}admin (username, password, created_at) VALUES (?, ?, ?)");
        $st->execute(array($adminUser, password_hash($adminPass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')));

        $defaults = array(
            'site_name' => $siteName !== '' ? $siteName : '沧舟云虚拟主机销售系统',
            'site_slogan' => '简单可靠的虚拟主机',
            'logo' => 'https://idc.mcedm.top/logo.png',
            'host_prefix' => 'ep',
            'smtp_host' => '', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '',
            'smtp_from' => '', 'smtp_from_name' => '', 'smtp_secure' => 'ssl',
            'pay_api' => 'https://pay.xicheny.com', 'pay_pid' => '', 'pay_private_key' => '', 'pay_public_key' => '',
        );
        $st = $pdo->prepare("INSERT INTO {$p}settings (k, v) VALUES (?, ?)");
        foreach ($defaults as $k => $v) { $st->execute(array($k, $v)); }
    } catch (Exception $e) {
        jout(array('ok' => false, 'msg' => '初始化数据失败：' . $e->getMessage()));
    }

    jout(array('ok' => true, 'data' => array('admin_path' => $adminPath, 'site_name' => $siteName)));
}

/* ---------- 安装页面 ---------- */
$siteName = '沧舟云虚拟主机销售系统';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - <?php echo h($siteName); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;background:#f6f7f9;color:#1f2937;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:40px 16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);width:100%;max-width:560px;padding:32px}
.logo{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:8px}
.logo img{height:36px;width:auto}
.logo span{font-size:20px;font-weight:700}
.sub{text-align:center;color:#6b7280;font-size:13px;margin-bottom:24px}
h2{font-size:17px;margin:22px 0 12px;display:flex;align-items:center;gap:8px}
h2:first-child{margin-top:0}
h2 .n{width:20px;height:20px;border-radius:50%;background:#2563eb;color:#fff;font-size:12px;display:inline-flex;align-items:center;justify-content:center}
label{display:block;font-size:13px;color:#374151;margin:12px 0 4px}
label .tip{color:#9ca3af;font-weight:400}
input[type=text],input[type=password],input[type=number]{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;outline:none;background:#fff}
input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.row{display:flex;gap:10px}
.row>div{flex:1}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border:none;border-radius:8px;font-size:14px;cursor:pointer;background:#2563eb;color:#fff;width:100%;margin-top:22px;font-weight:500}
.btn:hover{background:#1d4ed8}
.btn.ghost{background:#f3f4f6;color:#374151;margin-top:10px}
.btn.ghost:hover{background:#e5e7eb}
.msg{padding:10px 12px;border-radius:8px;font-size:13px;margin-top:12px;display:none}
.msg.ok{display:block;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.msg.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.okbox{text-align:center;padding:20px 0}
.okbox .big{font-size:44px}
.okbox a{color:#2563eb;text-decoration:none}
.links{margin-top:16px;display:flex;flex-direction:column;gap:8px}
.copyright{color:#9ca3af;font-size:12px;margin-top:24px}
.hint{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 12px;font-size:13px;margin-top:14px}
</style>
</head>
<body>

<div class="card">
<?php if ($installed): ?>
    <div class="okbox">
        <div class="big">✅</div>
        <h2 style="justify-content:center;margin:10px 0">系统已安装完成</h2>
        <p class="sub">如需重新安装，请删除网站目录下的 <b>config.php</b> 后再次访问本页面</p>
        <div class="links">
            <a class="btn" href="./">进入前台</a>
        </div>
    </div>
<?php else: ?>
    <div class="logo"><img src="https://idc.mcedm.top/logo.png" alt="" onerror="this.style.display='none'"><span><?php echo h($siteName); ?></span></div>
    <p class="sub">虚拟主机订购系统 · 安装向导</p>

    <h2><span class="n">1</span>数据库配置</h2>
    <label>数据库地址 <span class="tip">(通常为 127.0.0.1 或 localhost)</span></label>
    <div class="row">
        <div><input type="text" id="db_host" value="127.0.0.1"></div>
        <div style="max-width:110px"><input type="number" id="db_port" value="3306"></div>
    </div>
    <label>数据库名</label>
    <input type="text" id="db_name" placeholder="例如 xnzj">
    <label>数据库用户名</label>
    <input type="text" id="db_user">
    <label>数据库密码</label>
    <input type="password" id="db_pass">
    <label>表前缀</label>
    <input type="text" id="db_prefix" value="xnzj_">
    <div id="db_msg" class="msg"></div>
    <button class="btn ghost" id="btn_test" type="button">测试数据库连接</button>

    <h2><span class="n">2</span>管理员配置</h2>
    <label>后台路径 <span class="tip">(仅管理员使用，如 admin / myadmin)</span></label>
    <input type="text" id="admin_path" value="admin" placeholder="admin">
    <label>管理员账号</label>
    <input type="text" id="admin_user">
    <label>管理员密码 <span class="tip">(至少6位)</span></label>
    <input type="password" id="admin_pass">
    <label>确认密码</label>
    <input type="password" id="admin_pass2">
    <label>站点名称 <span class="tip">(可稍后在后台修改)</span></label>
    <input type="text" id="site_name" value="<?php echo h($siteName); ?>">

    <div id="install_msg" class="msg"></div>
    <button class="btn" id="btn_install" type="button">开始安装</button>
    <div class="hint">安装后请删除本文件 <b>install.php</b>，并妥善保管后台路径。</div>
<?php endif; ?>
</div>
<p class="copyright">© <?php echo date('Y'); ?> <?php echo h($siteName); ?></p>

<script>
var testOk = false;
function setMsg(el, ok, text) {
    el.className = 'msg ' + (ok ? 'ok' : 'err');
    el.textContent = text;
}
function post(data) {
    var body = new URLSearchParams();
    for (var k in data) body.append(k, data[k]);
    return fetch('install.php', {method: 'POST', body: body}).then(function (r) { return r.json(); });
}
var btnTest = document.getElementById('btn_test');
var btnInstall = document.getElementById('btn_install');
if (btnTest) {
    btnTest.onclick = function () {
        var d = {action: 'test_db', db_host: v('db_host'), db_port: v('db_port'), db_name: v('db_name'), db_user: v('db_user'), db_pass: v('db_pass')};
        setMsg(document.getElementById('db_msg'), false, '正在测试…');
        post(d).then(function (j) { setMsg(document.getElementById('db_msg'), j.ok, j.msg); testOk = j.ok; });
    };
    btnInstall.onclick = function () {
        var d = {action: 'install',
            db_host: v('db_host'), db_port: v('db_port'), db_name: v('db_name'), db_user: v('db_user'), db_pass: v('db_pass'),
            db_prefix: v('db_prefix'), admin_path: v('admin_path'), admin_user: v('admin_user'),
            admin_pass: v('admin_pass'), admin_pass2: v('admin_pass2'), site_name: v('site_name')};
        if (!d.admin_user || !d.admin_pass) { setMsg(document.getElementById('install_msg'), false, '请填写管理员账号和密码'); return; }
        setMsg(document.getElementById('install_msg'), false, '正在安装，请稍候…');
        btnInstall.disabled = true;
        post(d).then(function (j) {
            if (j.ok) {
                setMsg(document.getElementById('install_msg'), true, '✅ 安装成功！正在跳转…');
                setTimeout(function () { location.href = j.data.admin_path + '/'; }, 1200);
            } else {
                setMsg(document.getElementById('install_msg'), false, j.msg);
                btnInstall.disabled = false;
            }
        });
    };
}
function v(id) { return document.getElementById(id).value.trim(); }
</script>
</body>
</html>
