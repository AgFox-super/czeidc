<?php
/**
 * 公共函数
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

/* ---------- 数据库 ---------- */
function t($name) { return $GLOBALS['__cfg']['db']['prefix'] . $name; }

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['__cfg']['db'];
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['user'], $c['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

/* ---------- 站点设置 ---------- */
function &_settings_cache() { static $s = null; return $s; }

function setting($k, $def = '') {
    $s = &_settings_cache();
    if ($s === null) {
        $s = array();
        try {
            foreach (db()->query('SELECT k, v FROM ' . t('settings'))->fetchAll() as $r) { $s[$r['k']] = $r['v']; }
        } catch (Exception $e) { /* 忽略 */ }
    }
    return array_key_exists($k, $s) ? $s[$k] : $def;
}

function set_setting($k, $v) {
    $st = db()->prepare('INSERT INTO ' . t('settings') . ' (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute(array($k, (string)$v));
    $s = &_settings_cache();
    if (is_array($s)) { $s[$k] = (string)$v; }
}

/* ---------- JSON 输出 ---------- */
function json_out($data) {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function ok($data = null) { json_out(array('ok' => true, 'data' => $data)); }
function fail($msg, $code = 400) { http_response_code($code); json_out(array('ok' => false, 'msg' => $msg)); }

/* ---------- 输入 ---------- */
function req($k, $def = '') {
    if (!isset($_POST[$k])) { return $def; }
    $v = $_POST[$k];
    return is_string($v) ? trim($v) : $v;
}

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check() {
    $t = isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '';
    if ($t === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        fail('安全令牌无效，请刷新页面重试', 403);
    }
}

/* ---------- 认证 ---------- */
function current_user() {
    if (empty($_SESSION['uid'])) { return null; }
    $st = db()->prepare('SELECT * FROM ' . t('users') . ' WHERE id = ?');
    $st->execute(array((int)$_SESSION['uid']));
    return $st->fetch();
}
function require_user() {
    $u = current_user();
    if (!$u) { fail('请先登录', 401); }
    if ((int)$u['status'] !== 1) { fail('账号已被禁用，请联系管理员', 403); }
    return $u;
}
function current_admin() {
    if (empty($_SESSION['admin_id'])) { return null; }
    $st = db()->prepare('SELECT * FROM ' . t('admin') . ' WHERE id = ?');
    $st->execute(array((int)$_SESSION['admin_id']));
    return $st->fetch();
}
function require_admin() {
    $a = current_admin();
    if (!$a) { fail('请先登录后台', 401); }
    return $a;
}

/* ---------- 加解密（主机密码等敏感字段） ---------- */
function enc($plain) {
    $key = hex2bin(APP_KEY);
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}
function dec($b64) {
    $raw = base64_decode((string)$b64, true);
    if ($raw === false || strlen($raw) <= 16) { return ''; }
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $key = hex2bin(APP_KEY);
    return (string)openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

/* ---------- URL ---------- */
function root_path() {
    return $GLOBALS['__root_path'];
}
function root_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . root_path();
}

/* ---------- 工具 ---------- */
function cycles() {
    return array('month' => array('月付', 1), 'quarter' => array('季付', 3), 'halfyear' => array('半年付', 6), 'year' => array('年付', 10));
}
function cycle_amount($price, $cycle) {
    $c = cycles();
    if (!isset($c[$cycle])) { return 0; }
    return round((float)$price * $c[$cycle][1], 2);
}
function gen_order_no() {
    return 'XH' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
function random_pwd($n = 10) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $n; $i++) { $s .= $chars[random_int(0, $max)]; }
    return $s;
}
/** 默认主机名：前缀(后台可配，默认 ep) + 10位小写字母数字随机，共 前缀长度+10 位
 * 注意：必须纯小写（宝塔/EP 面板站点名只接受小写，含大写会开通失败）；前缀仅允许小写字母 */
function gen_hostname() {
    $prefix = setting('host_prefix', 'ep');
    $prefix = strtolower(trim($prefix));
    if (!preg_match('/^[a-z]{1,6}$/', $prefix)) { $prefix = 'ep'; }
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < 10; $i++) { $s .= $chars[random_int(0, $max)]; }
    return $prefix . $s;
}
function utf8_cut($s, $maxBytes) {
    if (strlen($s) <= $maxBytes) { return $s; }
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len;) {
        $c = ord($s[$i]);
        $cl = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
        if (strlen($out) + $cl > $maxBytes) { break; }
        $out .= substr($s, $i, $cl);
        $i += $cl;
    }
    return $out;
}
function email_valid($e) {
    return is_string($e) && strlen($e) <= 190 && (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}
function domain_valid($d) {
    return (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)+$/', $d);
}

/* ================= 登录防爆破（连续失败 5 次锁定 15 分钟） ================= */

function ensure_login_table() {
    db()->exec('CREATE TABLE IF NOT EXISTS ' . t('login_attempts') . ' (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(190) NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT \'user\' COMMENT \'user/admin\',
        fails TINYINT NOT NULL DEFAULT 0,
        locked_until INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uk_name_type (username, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** 返回剩余锁定秒数（0=未锁定） */
function login_locked($username, $type) {
    ensure_login_table();
    $st = db()->prepare('SELECT fails, locked_until FROM ' . t('login_attempts') . ' WHERE username = ? AND type = ?');
    $st->execute(array($username, $type));
    $r = $st->fetch();
    if (!$r) { return 0; }
    $until = (int)$r['locked_until'];
    if ($until > time()) { return $until - time(); }
    return 0;
}

/** 登录失败计数：返回新的剩余锁定秒数（0=未锁） */
function login_fail($username, $type) {
    ensure_login_table();
    $now = time();
    $st = db()->prepare('SELECT id, fails, locked_until FROM ' . t('login_attempts') . ' WHERE username = ? AND type = ?');
    $st->execute(array($username, $type));
    $r = $st->fetch();
    if ($r && (int)$r['locked_until'] > $now) { return (int)$r['locked_until'] - $now; }
    $fails = $r ? (int)$r['fails'] + 1 : 1;
    $until = 0;
    if ($fails >= 5) { $until = $now + 900; $fails = 5; }
    if ($r) {
        $st = db()->prepare('UPDATE ' . t('login_attempts') . ' SET fails = ?, locked_until = ?, updated_at = ? WHERE id = ?');
        $st->execute(array($fails, $until, date('Y-m-d H:i:s'), (int)$r['id']));
    } else {
        $st = db()->prepare('INSERT INTO ' . t('login_attempts') . ' (username, type, fails, locked_until, updated_at) VALUES (?,?,?,?,?)');
        $st->execute(array($username, $type, $fails, $until, date('Y-m-d H:i:s')));
    }
    return $until > $now ? $until - $now : 0;
}

/** 登录成功：清空失败计数 */
function login_clear($username, $type) {
    ensure_login_table();
    $st = db()->prepare('DELETE FROM ' . t('login_attempts') . ' WHERE username = ? AND type = ?');
    $st->execute(array($username, $type));
}

/* ================= 验证码防刷（发送限频 + 错误次数限制） ================= */

/** 确保 verify_codes 表有 ip / fails 列（幂等） */
function ensure_verify_cols() {
    foreach (array(
        'ip VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'发送IP\'',
        'fails TINYINT NOT NULL DEFAULT 0 COMMENT \'验证码错误次数\'',
    ) as $sql) {
        preg_match('/^([a-zA-Z_]+)/', $sql, $m);
        if (empty($m[1])) { continue; }
        $col = $m[1];
        $st = db()->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute(array(t('verify_codes'), $col));
        if ((int)$st->fetch()['c'] === 0) {
            db()->exec('ALTER TABLE ' . t('verify_codes') . ' ADD COLUMN ' . $sql);
        }
    }
}

function client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 64) : '';
}
function add_months($datetime, $months) {
    $ts = strtotime($datetime);
    if ($ts === false) { $ts = time(); }
    return date('Y-m-d H:i:s', strtotime('+' . (int)$months . ' months', $ts));
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- 邮件 ---------- */
function send_mail($to, $subject, $htmlBody) {
    $cfg = array(
        'host' => setting('smtp_host'),
        'port' => (int)setting('smtp_port', 465),
        'user' => setting('smtp_user'),
        'pass' => setting('smtp_pass'),
        'from' => setting('smtp_from'),
        'from_name' => setting('smtp_from_name'),
        'secure' => setting('smtp_secure', 'ssl'),
    );
    if ($cfg['host'] === '' || $cfg['from'] === '') {
        return array('ok' => false, 'msg' => '管理员尚未配置SMTP，无法发送邮件');
    }
    $m = new SmtpMailer($cfg);
    if ($m->send($to, $subject, $htmlBody)) {
        return array('ok' => true, 'msg' => '已发送');
    }
    return array('ok' => false, 'msg' => $m->err);
}

function send_verify_mail($email, $code) {
    $site = setting('site_name', '虚拟主机系统');
    $body = '<div style="font-family:Arial,Microsoft YaHei,sans-serif;max-width:520px;margin:0 auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px">'
        . '<div style="font-size:18px;font-weight:600;color:#111827;margin-bottom:16px">' . h($site) . '</div>'
        . '<p style="color:#374151;font-size:14px;line-height:1.7">您好，您正在注册 ' . h($site) . ' 账号。本次验证码为：</p>'
        . '<div style="font-size:26px;font-weight:700;letter-spacing:6px;color:#2563eb;padding:12px 0">' . h($code) . '</div>'
        . '<p style="color:#6b7280;font-size:13px">验证码 10 分钟内有效。若非本人操作，请忽略此邮件。</p></div>';
    return send_mail($email, '[' . $site . '] 注册验证码', $body);
}

function send_test_mail($to) {
    $site = setting('site_name', '虚拟主机系统');
    $body = '<p>这是一封来自 <b>' . h($site) . '</b> 的测试邮件，发送成功说明SMTP配置可用。</p>';
    return send_mail($to, '[' . $site . '] SMTP 测试邮件', $body);
}

/* ---------- 开通与履约（支付成功后调用，幂等） ---------- */
function provision_order($orderId) {
    $orderId = (int)$orderId;
    $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
    $st->execute(array($orderId));
    $order = $st->fetch();
    if (!$order) { return array('ok' => false, 'msg' => '订单不存在'); }

    // 已存在产品：正常返回；开通失败则用已存密码重试
    $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
    $st->execute(array($orderId));
    $prod = $st->fetch();
    if ($prod) {
        if ((int)$prod['status'] === 1) { return array('ok' => true, 'product_id' => (int)$prod['id'], 'msg' => '已开通'); }
        if ((int)$prod['status'] === 3) { return array('ok' => false, 'msg' => '产品已删除，无法重开'); }
        // 魔方上游方案的重试（含余额不足补款后重开）交给上游逻辑处理
        $st = db()->prepare('SELECT * FROM ' . t('plans') . ' WHERE id = ?');
        $st->execute(array((int)$prod['plan_id']));
        $plan0 = $st->fetch();
        if ($plan0 && (int)$plan0['zjmf_api_id'] > 0 && (int)$plan0['upstream_pid'] > 0 && function_exists('zjmf_provision_order')) {
            return zjmf_provision_order($order, $plan0);
        }
        // 重试开通（沿用原主机名与密码）
        $server = panel_server_by_id((int)$prod['server_id']);
        if (!$server) { return array('ok' => false, 'msg' => '服务器不存在', 'product_id' => (int)$prod['id']); }
        $cfg = panel_cfg_by_plan((int)$prod['plan_id'], $prod['domain']);
        if ($cfg === null) { return array('ok' => false, 'msg' => '产品方案不存在', 'product_id' => (int)$prod['id']); }
        $r = panel_create($server, $prod['username'], dec($prod['password']), $cfg);
        if (!panel_ok($r)) {
            $m = panel_errmsg($r);
            return array('ok' => false, 'msg' => $m !== '' ? $m : '开通失败（可重试）', 'product_id' => (int)$prod['id']);
        }
        $now = date('Y-m-d H:i:s');
        $st = db()->prepare('UPDATE ' . t('products') . ' SET status = 1, activated_at = ?, expires_at = ? WHERE id = ?');
        $st->execute(array($now, add_months($now, cycle_months($order['cycle'])), (int)$prod['id']));
        return array('ok' => true, 'product_id' => (int)$prod['id'], 'msg' => '开通成功');
    }

    $st = db()->prepare('SELECT * FROM ' . t('plans') . ' WHERE id = ?');
    $st->execute(array((int)$order['plan_id']));
    $plan = $st->fetch();
    if (!$plan) { return array('ok' => false, 'msg' => '产品方案不存在'); }

    // 魔方财务上游方案：走上游 API 下单开通
    if ((int)$plan['zjmf_api_id'] > 0 && (int)$plan['upstream_pid'] > 0) {
        if (function_exists('zjmf_provision_order')) {
            return zjmf_provision_order($order, $plan);
        }
        return array('ok' => false, 'msg' => '缺少魔方财务上游组件 includes/zjmf.php');
    }

    $server = panel_server_by_id((int)$plan['server_id']);
    if (!$server || (int)$server['status'] !== 1) { return array('ok' => false, 'msg' => '服务器未配置或已停用'); }

    $hostname = $order['domain'] !== '' ? $order['domain'] : gen_hostname();
    $pwd = random_pwd(10);

    $cfg = panel_cfg_from_plan($plan, $order['domain']);
    $r = panel_create($server, $hostname, $pwd, $cfg);
    $okB = panel_ok($r);

    $now = date('Y-m-d H:i:s');
    $expires = $okB ? add_months($now, cycle_months($order['cycle'])) : null;
    $st = db()->prepare('INSERT INTO ' . t('products')
        . ' (order_id, user_id, plan_id, server_id, plan_name, domain, username, password, status, created_at, activated_at, expires_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute(array(
        $orderId, (int)$order['user_id'], (int)$order['plan_id'], (int)$server['id'],
        $order['plan_name'], $order['domain'], $hostname, enc($pwd),
        $okB ? 1 : 0, $now, $okB ? $now : null, $expires
    ));
    $pid = (int)db()->lastInsertId();

    if (!$okB) {
        $m = panel_errmsg($r);
        return array('ok' => false, 'msg' => $m !== '' ? $m : '开通失败', 'product_id' => $pid);
    }
    return array('ok' => true, 'product_id' => $pid, 'msg' => '开通成功');
}

function cycle_months($cycle) {
    switch ($cycle) {
        case 'quarter': return 3;
        case 'halfyear': return 6;
        case 'year': return 12;
        default: return 1;
    }
}

/** 按服务器ID取服务器 */
function panel_server_by_id($id) {
    $st = db()->prepare('SELECT * FROM ' . t('servers') . ' WHERE id = ?');
    $st->execute(array((int)$id));
    return $st->fetch();
}

/* ================= 面板分发层（btn=宝塔原生API / ep=EP面板） ================= */
function panel_type($server) {
    if (isset($server['type']) && $server['type'] === 'ep') { return 'ep'; }
    return 'btn'; // 默认宝塔原生API
}

function panel_missing($file) {
    return array('status' => 0, 'info' => '缺少面板组件 includes/' . $file . '，请补全文件后再试');
}

function panel_create($server, $hostname, $pwd, $cfg) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_create') ? ep_create($server, $hostname, $pwd, $cfg) : panel_missing('ep.php'); }
    return function_exists('bt_native_create') ? bt_native_create($server, $hostname, $pwd, $cfg) : panel_missing('bt_native.php');
}
function panel_set_status($server, $hostname, $status) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_set_status') ? ep_set_status($server, $hostname, $status) : panel_missing('ep.php'); }
    return function_exists('bt_native_set_status') ? bt_native_set_status($server, $hostname, $status) : panel_missing('bt_native.php');
}
function panel_del($server, $hostname) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_del') ? ep_del($server, $hostname) : panel_missing('ep.php'); }
    return function_exists('bt_native_del') ? bt_native_del($server, $hostname) : panel_missing('bt_native.php');
}
function panel_chg_pwd($server, $hostname, $pwd) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_chg_pwd') ? ep_chg_pwd($server, $hostname, $pwd) : panel_missing('ep.php'); }
    return function_exists('bt_native_chg_pwd') ? bt_native_chg_pwd($server, $hostname, $pwd) : panel_missing('bt_native.php');
}
function panel_test($server) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_test') ? ep_test($server) : panel_missing('ep.php'); }
    return function_exists('bt_native_test') ? bt_native_test($server) : panel_missing('bt_native.php');
}
function panel_ok($r) {
    // 结果数组不可能同时满足多种面板的成功判定，直接合并判断即可
    return is_array($r) && (bt_native_ok($r) || ep_ok($r));
}

/** 统一取面板返回的错误详情（宝塔用 info、原生API 用 msg、EP 用 info） */
function panel_errmsg($r) {
    if (is_array($r)) {
        if (isset($r['info']) && $r['info'] !== '') { return (string)$r['info']; }
        if (isset($r['msg']) && $r['msg'] !== '') { return (string)$r['msg']; }
    }
    return '';
}
/** 客户控制面板地址（EP 固定 3312；宝塔原生=面板地址） */
function panel_panel_url($server, $hostname) {
    $t = panel_type($server);
    if ($t === 'ep') { return function_exists('ep_panel_url') ? ep_panel_url($server, $hostname) : ''; }
    return function_exists('bt_native_panel_url') ? bt_native_panel_url($server) : '';
}

/** 由方案组装开通参数 */
function panel_cfg_from_plan($plan, $domain) {
    return array(
        'a1' => (int)$plan['a1'], 'a2' => (int)$plan['a2'], 'a3' => (int)$plan['a3'],
        'a4' => (int)$plan['a4'], 'a5' => (int)$plan['a5'], 'a6' => (int)$plan['a6'],
        'a7' => $plan['a7'], 'a8' => (int)$plan['a8'], 'a9' => (int)$plan['a9'],
        'a10' => (int)$plan['a10'], 'hostdomain' => $domain,
        'plan_name' => $plan['name'],
    );
}

/** 按方案ID取方案并组装开通参数 */
function panel_cfg_by_plan($planId, $domain) {
    $st = db()->prepare('SELECT * FROM ' . t('plans') . ' WHERE id = ?');
    $st->execute(array((int)$planId));
    $plan = $st->fetch();
    if (!$plan) { return null; }
    return panel_cfg_from_plan($plan, $domain);
}

/**
 * 仅标记订单已支付（幂等，快速），不执行开通
 */
function mark_order_paid($orderId, $tradeNo = '') {
    $orderId = (int)$orderId;
    $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
    $st->execute(array($orderId));
    $order = $st->fetch();
    if (!$order) { return array('ok' => false, 'msg' => '订单不存在'); }
    if ($order['status'] === 'paid') { return array('ok' => true, 'order' => $order); }
    if ($order['status'] !== 'pending') { return array('ok' => false, 'msg' => '订单状态异常'); }
    $st = db()->prepare('UPDATE ' . t('orders') . ' SET status = ?, paid_at = ?, trade_no = ? WHERE id = ?');
    $st->execute(array('paid', date('Y-m-d H:i:s'), $tradeNo, $orderId));
    $order['status'] = 'paid';
    return array('ok' => true, 'order' => $order);
}

/**
 * 异步开通：先让调用方输出响应，再执行开通（付款回调提速关键）
 * - php-fpm：fastcgi_finish_request() 先发送响应，再同步执行开通（不占用户等待，仍占一个 worker）
 * - 无 fastcgi（CLI/php -S）：popen 启动 CLI 子进程 async_provision.php 执行
 * - 并发保护：文件锁，同一订单同时只跑一个开通任务（防止重复建单/重复扣款）
 */
function queue_provision($orderId) {
    $orderId = (int)$orderId;
    $lockFile = sys_get_temp_dir() . '/xnzj_prov_' . $orderId . '.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        return array('ok' => true, 'msg' => '开通任务进行中');
    }
    // php-fpm：先发响应再同步开通
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $r = provision_order($orderId);
        if ($fp) { flock($fp, LOCK_UN); @fclose($fp); }
        return $r;
    }
    // 无 fastcgi：CLI 子进程异步（先释放锁，由子进程接管）
    if ($fp) { flock($fp, LOCK_UN); @fclose($fp); $fp = null; }
    $script = XNZJ_ROOT . '/async_provision.php';
    $url = root_url();
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $orderId . ' ' . escapeshellarg($url);
    $ok = @pclose(@popen($cmd . ' > /dev/null 2>&1 &', 'r')) !== false;
    if ($ok) { return array('ok' => true, 'msg' => '开通任务已提交'); }
    // 子进程不可用：同步兜底
    $fp = @fopen($lockFile, 'c');
    if ($fp) { flock($fp, LOCK_EX); }
    $r = provision_order($orderId);
    if ($fp) { flock($fp, LOCK_UN); @fclose($fp); }
    return $r;
}

/**
 * 支付成功后的履约：标记已支付 → 开通（幂等，可重复调用）
 * 注：自动支付路径（notify/return/前台查单）请改用 mark_order_paid + queue_provision 异步开通；
 *     本函数保留给后台手动标记支付等需要同步结果的场景。
 */
function fulfill_order($orderId, $tradeNo = '') {
    $orderId = (int)$orderId;
    $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
    $st->execute(array($orderId));
    $order = $st->fetch();
    if (!$order) { return array('ok' => false, 'msg' => '订单不存在'); }

    if ($order['status'] === 'paid') {
        return provision_order($orderId);
    }
    if ($order['status'] !== 'pending') {
        return array('ok' => false, 'msg' => '订单状态异常');
    }
    $st = db()->prepare('UPDATE ' . t('orders') . ' SET status = ?, paid_at = ?, trade_no = ? WHERE id = ?');
    $st->execute(array('paid', date('Y-m-d H:i:s'), $tradeNo, $orderId));
    return provision_order($orderId);
}
