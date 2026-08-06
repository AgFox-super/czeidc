<?php
/**
 * 前台 AJAX API
 */
define('XNZJ_BOOT', true);
define('XNZJ_API', true);
require __DIR__ . '/includes/boot.php';

// 致命错误（未定义函数/类等）转 JSON 响应，避免前端拿到空响应报 "Unexpected end of JSON input"
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('ok' => false, 'msg' => '系统错误：' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')，请检查PHP扩展/组件是否完整'), JSON_UNESCAPED_UNICODE);
    }
});

header('X-Content-Type-Options: nosniff');

try {
    $action = req('action', '');
    if ($action === '') { fail('缺少参数 action'); }

    // 所有接口统一 CSRF 校验（notify/return 除外，它们走签名验签）
    csrf_check();

    switch ($action) {

        /* ================= 公开接口 ================= */

        case 'sendcode':
            $email = req('email');
            if (!email_valid($email)) { fail('邮箱格式不正确'); }
            $ip = client_ip();
            // 60 秒限频（同一邮箱）
            $st = db()->prepare('SELECT created_at FROM ' . t('verify_codes') . ' WHERE email = ? ORDER BY id DESC LIMIT 1');
            $st->execute(array($email));
            $last = $st->fetch();
            if ($last && (time() - strtotime($last['created_at'])) < 60) { fail('发送太频繁，请稍后再试'); }
            // 防刷：同一邮箱 24 小时内最多 10 次
            $st = db()->prepare('SELECT COUNT(*) c FROM ' . t('verify_codes') . ' WHERE email = ? AND created_at >= ?');
            $st->execute(array($email, date('Y-m-d H:i:s', time() - 86400)));
            if ((int)$st->fetch()['c'] >= 10) { fail('该邮箱今日发送次数过多，请明天再试'); }
            // 防刷：同一 IP 1 小时内最多 10 次
            if ($ip !== '') {
                $st = db()->prepare('SELECT COUNT(*) c FROM ' . t('verify_codes') . ' WHERE ip = ? AND created_at >= ?');
                $st->execute(array($ip, date('Y-m-d H:i:s', time() - 3600)));
                if ((int)$st->fetch()['c'] >= 10) { fail('发送太频繁，请稍后再试'); }
            }
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            ensure_verify_cols();
            $st = db()->prepare('DELETE FROM ' . t('verify_codes') . ' WHERE email = ?');
            $st->execute(array($email));
            $st = db()->prepare('INSERT INTO ' . t('verify_codes') . ' (email, code, purpose, expires_at, created_at, ip, fails) VALUES (?,?,?,?,?,?,0)');
            $st->execute(array($email, $code, 'register', date('Y-m-d H:i:s', time() + 600), date('Y-m-d H:i:s'), $ip));
            $r = send_verify_mail($email, $code);
            if (!$r['ok']) { fail($r['msg']); }
            ok();
            break;

        case 'register':
            csrf_check();
            $email = req('email');
            $code = req('code');
            $password = req('password');
            $confirm = req('confirm');
            if (!email_valid($email)) { fail('邮箱格式不正确'); }
            if (!preg_match('/^\d{6}$/', $code)) { fail('验证码格式不正确'); }
            if (strlen($password) < 6) { fail('密码至少6位'); }
            if ($confirm === '' || $password !== $confirm) { fail('两次输入的密码不一致'); }
            $st = db()->prepare('SELECT id FROM ' . t('users') . ' WHERE email = ?');
            $st->execute(array($email));
            if ($st->fetch()) { fail('该邮箱已注册'); }
            $st = db()->prepare('SELECT * FROM ' . t('verify_codes') . ' WHERE email = ? AND code = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
            $st->execute(array($email, $code, 'register'));
            $row = $st->fetch();
            if (!$row) {
                // 验证码不匹配：错误次数 +1，超过 5 次作废（防暴力猜）
                ensure_verify_cols();
                $st = db()->prepare('SELECT id, fails FROM ' . t('verify_codes') . ' WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
                $st->execute(array($email, 'register'));
                $r2 = $st->fetch();
                if ($r2) {
                    $f = (int)$r2['fails'] + 1;
                    if ($f >= 5) {
                        $st = db()->prepare('DELETE FROM ' . t('verify_codes') . ' WHERE email = ?');
                        $st->execute(array($email));
                        fail('验证码错误次数过多，请重新获取');
                    }
                    $st = db()->prepare('UPDATE ' . t('verify_codes') . ' SET fails = ? WHERE id = ?');
                    $st->execute(array($f, (int)$r2['id']));
                }
                fail('验证码错误');
            }
            if (strtotime($row['expires_at']) < time()) { fail('验证码已过期，请重新获取'); }
            if ((int)$row['fails'] >= 5) {
                $st = db()->prepare('DELETE FROM ' . t('verify_codes') . ' WHERE email = ?');
                $st->execute(array($email));
                fail('验证码错误次数过多，请重新获取');
            }
            $st = db()->prepare('INSERT INTO ' . t('users') . ' (email, password, status, created_at) VALUES (?,?,1,?)');
            $st->execute(array($email, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')));
            $uid = (int)db()->lastInsertId();
            $st = db()->prepare('DELETE FROM ' . t('verify_codes') . ' WHERE email = ?');
            $st->execute(array($email));
            $_SESSION['uid'] = $uid;
            ok(array('id' => $uid, 'email' => $email));
            break;

        case 'login':
            csrf_check();
            $email = req('email');
            $password = req('password');
            if (!email_valid($email) || $password === '') { fail('请输入邮箱和密码'); }
            // 防爆破：锁定期间直接拒绝
            $locked = login_locked($email, 'user');
            if ($locked > 0) {
                http_response_code(429);
                fail('登录失败次数过多，请 ' . max(1, ceil($locked / 60)) . ' 分钟后再试');
            }
            $st = db()->prepare('SELECT * FROM ' . t('users') . ' WHERE email = ?');
            $st->execute(array($email));
            $u = $st->fetch();
            if (!$u || !password_verify($password, $u['password'])) {
                $remain = login_fail($email, 'user');
                $msg = '邮箱或密码错误';
                if ($remain > 0) { $msg .= '（连续失败5次将锁定15分钟，已锁定，请 ' . max(1, ceil($remain / 60)) . ' 分钟后再试）'; }
                else { $msg .= '（连续失败5次将锁定15分钟）'; }
                fail($msg);
            }
            if ((int)$u['status'] !== 1) { fail('账号已被禁用，请联系管理员'); }
            login_clear($email, 'user');
            $_SESSION['uid'] = (int)$u['id'];
            ok(array('id' => (int)$u['id'], 'email' => $u['email']));
            break;

        case 'logout':
            unset($_SESSION['uid']);
            ok();
            break;

        case 'me':
            $u = current_user();
            ok($u ? array('id' => (int)$u['id'], 'email' => $u['email'], 'created_at' => $u['created_at']) : null);
            break;

        case 'plans':
            $rows = db()->query('SELECT p.*, s.name AS server_name FROM ' . t('plans') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id WHERE p.status = 1 ORDER BY p.sort ASC, p.id ASC')->fetchAll();
            $list = array();
            foreach ($rows as $r) {
                $list[] = array(
                    'id' => (int)$r['id'],
                    'name' => $r['name'],
                    'price' => $r['price'],
                    'server_name' => $r['server_name'] ? $r['server_name'] : ((int)$r['zjmf_api_id'] > 0 ? '魔方上游' : ''),
                    'note' => $r['note'],
                    'specs' => array(
                        'web' => (int)$r['a1'], 'sql' => (int)$r['a2'], 'domain' => (int)$r['a3'],
                        'dir' => (int)$r['a4'], 'flow' => (int)$r['a5'], 'ports' => $r['a7'],
                    ),
                );
            }
            ok($list);
            break;

        /* ================= 需登录 ================= */

        case 'order_create':
            csrf_check();
            $u = require_user();
            $planId = (int)req('plan_id');
            $cycle = req('cycle', 'month');
            $payType = req('pay_type', 'alipay');
            $domain = req('domain');
            $cycles = cycles();
            if (!isset($cycles[$cycle])) { fail('请选择正确的购买周期'); }
            if (!in_array($payType, array('alipay', 'wxpay'), true)) { fail('请选择正确的支付方式'); }
            if ($domain !== '' && !domain_valid($domain)) { fail('绑定域名格式不正确'); }
            $st = db()->prepare('SELECT * FROM ' . t('plans') . ' WHERE id = ? AND status = 1');
            $st->execute(array($planId));
            $plan = $st->fetch();
            if (!$plan) { fail('产品方案不存在或已下架'); }
            $amount = cycle_amount($plan['price'], $cycle);
            if ($amount <= 0) { fail('价格异常'); }
            $orderNo = gen_order_no();
            $st = db()->prepare('INSERT INTO ' . t('orders')
                . ' (order_no, user_id, plan_id, plan_name, cycle, cycle_name, amount, pay_type, status, domain, created_at)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($orderNo, (int)$u['id'], $planId, $plan['name'], $cycle, $cycles[$cycle][0], $amount, $payType, 'pending', $domain, date('Y-m-d H:i:s')));
            $orderId = (int)db()->lastInsertId();
            $order = array('id' => $orderId, 'order_no' => $orderNo, 'plan_name' => $plan['name'], 'cycle_name' => $cycles[$cycle][0], 'amount' => $amount);
            $form = yipay_pay_form($order, $payType);
            if ($form === null) { fail('支付尚未配置，请联系管理员', 500); }
            ok(array('order' => $order, 'pay_form' => $form));
            break;

        case 'order_pay':
            csrf_check();
            $u = require_user();
            $orderId = (int)req('order_id');
            $payType = req('pay_type', 'alipay');
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ? AND user_id = ?');
            $st->execute(array($orderId, (int)$u['id']));
            $order = $st->fetch();
            if (!$order) { fail('订单不存在'); }
            if ($order['status'] !== 'pending') { fail('订单当前状态不可支付'); }
            if (!in_array($payType, array('alipay', 'wxpay'), true)) { fail('请选择正确的支付方式'); }
            $form = yipay_pay_form($order, $payType);
            if ($form === null) { fail('支付尚未配置，请联系管理员', 500); }
            ok(array('order' => array(
                'id' => (int)$order['id'], 'order_no' => $order['order_no'], 'plan_name' => $order['plan_name'],
                'cycle_name' => $order['cycle_name'], 'amount' => $order['amount'],
            ), 'pay_form' => $form));
            break;

        case 'order_status':
            csrf_check();
            $u = require_user();
            $orderId = (int)req('order_id');
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ? AND user_id = ?');
            $st->execute(array($orderId, (int)$u['id']));
            $order = $st->fetch();
            if (!$order) { fail('订单不存在'); }
            $synced = false;
            if ($order['status'] === 'pending') {
                // 主动向支付平台查单
                $q = yipay_query($order['order_no']);
                if ($q !== null && $q['ok'] && (int)$q['status'] === 1) {
                    mark_order_paid((int)$order['id'], (string)(isset($q['data']['trade_no']) ? $q['data']['trade_no'] : ''));
                    $synced = true;
                }
            }
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
            $st->execute(array($orderId));
            $order = $st->fetch();
            $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
            $st->execute(array($orderId));
            $prod = $st->fetch();
            // 先输出响应，再异步开通（避免查单接口同步等待开通）
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(array('ok' => true, 'data' => array(
                'status' => $order['status'],
                'synced' => $synced,
                'product_id' => $prod ? (int)$prod['id'] : 0,
                'trade_no' => $order['trade_no'],
                'paid_at' => $order['paid_at'],
            )), JSON_UNESCAPED_UNICODE);
            if ($synced) { queue_provision((int)$order['id']); }
            exit;
            break;

        case 'orders':
            $u = require_user();
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE user_id = ? ORDER BY id DESC LIMIT 50');
            $st->execute(array((int)$u['id']));
            ok($st->fetchAll());
            break;

        case 'products':
            $u = require_user();
            $st = db()->prepare('SELECT p.*, s.name AS server_name, s.host AS server_host FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id WHERE p.user_id = ? ORDER BY p.id DESC');
            $st->execute(array((int)$u['id']));
            $list = array();
            foreach ($st->fetchAll() as $r) {
                $list[] = array(
                    'id' => (int)$r['id'], 'plan_name' => $r['plan_name'], 'domain' => $r['domain'],
                    'username' => $r['username'], 'status' => (int)$r['status'],
                    'created_at' => $r['created_at'], 'activated_at' => $r['activated_at'],
                    'expires_at' => $r['expires_at'], 'server_name' => $r['server_name'],
                );
            }
            ok($list);
            break;

        case 'product_get':
            csrf_check();
            $u = require_user();
            $id = (int)req('id');
            $st = db()->prepare('SELECT p.*, s.name AS server_name, s.host AS server_host, s.ip AS server_ip, s.port AS server_port, s.https AS server_https, s.type AS server_type, pl.a1, pl.a2, pl.a3, pl.a4, pl.a5, pl.a7, pl.note AS plan_note, pl.zjmf_api_id FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id LEFT JOIN ' . t('plans') . ' pl ON pl.id = p.plan_id WHERE p.id = ? AND p.user_id = ?');
            $st->execute(array($id, (int)$u['id']));
            $p = $st->fetch();
            if (!$p) { fail('产品不存在'); }
            $panelUrl = '';
            if ((int)$p['status'] === 1 && !empty($p['server_host'])) {
                $server = array('host' => $p['server_host'], 'https' => (int)$p['server_https'], 'type' => $p['server_type'], 'username' => '', 'secret' => '', 'ip' => $p['server_ip'], 'port' => (int)$p['server_port']);
                $panelUrl = panel_panel_url($server, $p['username']);
            }
            // 魔方财务上游产品：登录面板信息从上游获取（module_client_area + host_data）
            $panel = null;
            if ((int)$p['status'] === 1 && (int)$p['upstream_hostid'] > 0 && (int)$p['zjmf_api_id'] > 0) {
                $api = zjmf_api_row((int)$p['zjmf_api_id']);
                if ($api && (int)$api['status'] === 1) {
                    $panel = zjmf_panel_info($api, (int)$p['upstream_hostid']);
                }
            }
            ok(array(
                'id' => (int)$p['id'], 'plan_name' => $p['plan_name'], 'domain' => $p['domain'],
                'username' => $p['username'], 'password' => dec($p['password']),
                'status' => (int)$p['status'], 'created_at' => $p['created_at'],
                'activated_at' => $p['activated_at'], 'expires_at' => $p['expires_at'],
                'server_name' => $p['server_name'], 'server_host' => $p['server_host'],
                'panel_url' => $panelUrl,
                'panel' => $panel,
                'specs' => array('web' => (int)$p['a1'], 'sql' => (int)$p['a2'], 'domain' => (int)$p['a3'], 'dir' => (int)$p['a4'], 'flow' => (int)$p['a5'], 'ports' => $p['a7']),
                'note' => $p['plan_note'],
            ));
            break;

        case 'product_repwd':
            csrf_check();
            $u = require_user();
            $id = (int)req('id');
            $pwd = req('pwd');
            if (strlen($pwd) < 6 || strlen($pwd) > 32) { fail('密码长度需在6-32位'); }
            $st = db()->prepare('SELECT p.*, s.host AS server_host, s.https AS server_https, s.username AS server_user, s.secret AS server_secret, s.ip AS server_ip, s.port AS server_port, s.type AS server_type, pl.zjmf_api_id FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id LEFT JOIN ' . t('plans') . ' pl ON pl.id = p.plan_id WHERE p.id = ? AND p.user_id = ?');
            $st->execute(array($id, (int)$u['id']));
            $p = $st->fetch();
            if (!$p) { fail('产品不存在'); }
            if ((int)$p['status'] !== 1) { fail('产品当前状态不可修改密码'); }
            // 魔方财务上游产品：调上游改密
            if ((int)$p['upstream_hostid'] > 0 && (int)$p['zjmf_api_id'] > 0) {
                $api = zjmf_api_row((int)$p['zjmf_api_id']);
                if (!$api || (int)$api['status'] !== 1) { fail('上游接口未配置或已停用'); }
                $r = zjmf_provision_default($api, (int)$p['upstream_hostid'], 'crack_pass', array('password' => $pwd));
                if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '修改密码失败'); }
                $st = db()->prepare('UPDATE ' . t('products') . ' SET password = ? WHERE id = ?');
                $st->execute(array(enc($pwd), $id));
                ok();
                break;
            }
            $server = array('host' => $p['server_host'], 'https' => (int)$p['server_https'], 'type' => $p['server_type'], 'username' => $p['server_user'], 'secret' => $p['server_secret'], 'ip' => $p['server_ip'], 'port' => (int)$p['server_port']);
            $r = panel_chg_pwd($server, $p['username'], $pwd);
            if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '修改密码失败'); }
            $st = db()->prepare('UPDATE ' . t('products') . ' SET password = ? WHERE id = ?');
            $st->execute(array(enc($pwd), $id));
            ok();
            break;

        case 'product_terminate':
            csrf_check();
            $u = require_user();
            $id = (int)req('id');
            $st = db()->prepare('SELECT p.*, s.host AS server_host, s.https AS server_https, s.username AS server_user, s.secret AS server_secret, s.ip AS server_ip, s.port AS server_port, s.type AS server_type, pl.zjmf_api_id FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id LEFT JOIN ' . t('plans') . ' pl ON pl.id = p.plan_id WHERE p.id = ? AND p.user_id = ?');
            $st->execute(array($id, (int)$u['id']));
            $p = $st->fetch();
            if (!$p) { fail('产品不存在'); }
            if ((int)$p['status'] === 3) { fail('产品已删除'); }
            // 魔方财务上游产品：调上游删除
            if ((int)$p['upstream_hostid'] > 0 && (int)$p['zjmf_api_id'] > 0) {
                $api = zjmf_api_row((int)$p['zjmf_api_id']);
                if (!$api || (int)$api['status'] !== 1) { fail('上游接口未配置或已停用'); }
                $r = zjmf_host_cancel($api, (int)$p['upstream_hostid']);
                if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '删除失败'); }
                $st = db()->prepare('UPDATE ' . t('products') . ' SET status = 3 WHERE id = ?');
                $st->execute(array($id));
                $st = db()->prepare('UPDATE ' . t('orders') . ' SET status = ? WHERE id = ?');
                $st->execute(array('cancelled', (int)$p['order_id']));
                ok();
                break;
            }
            $server = array('host' => $p['server_host'], 'https' => (int)$p['server_https'], 'type' => $p['server_type'], 'username' => $p['server_user'], 'secret' => $p['server_secret'], 'ip' => $p['server_ip'], 'port' => (int)$p['server_port']);
            $r = panel_del($server, $p['username']);
            if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '删除失败'); }
            $st = db()->prepare('UPDATE ' . t('products') . ' SET status = 3 WHERE id = ?');
            $st->execute(array($id));
            $st = db()->prepare('UPDATE ' . t('orders') . ' SET status = ? WHERE id = ?');
            $st->execute(array('cancelled', (int)$p['order_id']));
            ok();
            break;

        case 'tickets':
            $u = require_user();
            $st = db()->prepare('SELECT * FROM ' . t('tickets') . ' WHERE user_id = ? ORDER BY id DESC LIMIT 100');
            $st->execute(array((int)$u['id']));
            ok($st->fetchAll());
            break;

        case 'ticket_create':
            csrf_check();
            $u = require_user();
            $subject = req('subject');
            $content = req('content');
            if (mb_strlen($subject) < 2 || mb_strlen($subject) > 80) { fail('工单标题长度需在2-80字'); }
            if (mb_strlen($content) < 2 || mb_strlen($content) > 5000) { fail('工单内容长度需在2-5000字'); }
            $now = date('Y-m-d H:i:s');
            $st = db()->prepare('INSERT INTO ' . t('tickets') . ' (user_id, subject, status, created_at, updated_at) VALUES (?,?,?,?,?)');
            $st->execute(array((int)$u['id'], $subject, 'open', $now, $now));
            $tid = (int)db()->lastInsertId();
            $st = db()->prepare('INSERT INTO ' . t('ticket_replies') . ' (ticket_id, admin, content, created_at) VALUES (?,0,?,?)');
            $st->execute(array($tid, $content, $now));
            ok(array('id' => $tid));
            break;

        case 'ticket_get':
            csrf_check();
            $u = require_user();
            $id = (int)req('id');
            $st = db()->prepare('SELECT * FROM ' . t('tickets') . ' WHERE id = ? AND user_id = ?');
            $st->execute(array($id, (int)$u['id']));
            $tk = $st->fetch();
            if (!$tk) { fail('工单不存在'); }
            $st = db()->prepare('SELECT * FROM ' . t('ticket_replies') . ' WHERE ticket_id = ? ORDER BY id ASC');
            $st->execute(array($id));
            ok(array('ticket' => $tk, 'replies' => $st->fetchAll()));
            break;

        case 'ticket_reply':
            csrf_check();
            $u = require_user();
            $id = (int)req('id');
            $content = req('content');
            if (mb_strlen($content) < 1 || mb_strlen($content) > 5000) { fail('回复内容长度需在1-5000字'); }
            $st = db()->prepare('SELECT * FROM ' . t('tickets') . ' WHERE id = ? AND user_id = ?');
            $st->execute(array($id, (int)$u['id']));
            $tk = $st->fetch();
            if (!$tk) { fail('工单不存在'); }
            $now = date('Y-m-d H:i:s');
            $st = db()->prepare('INSERT INTO ' . t('ticket_replies') . ' (ticket_id, admin, content, created_at) VALUES (?,0,?,?)');
            $st->execute(array($id, $content, $now));
            $st = db()->prepare('UPDATE ' . t('tickets') . ' SET status = ?, updated_at = ? WHERE id = ?');
            $st->execute(array($tk['status'] === 'closed' ? 'open' : 'open', $now, $id));
            ok();
            break;

        case 'change_password':
            csrf_check();
            $u = require_user();
            $old = req('old');
            $new = req('new');
            if (!password_verify($old, $u['password'])) { fail('原密码不正确'); }
            if (strlen($new) < 6) { fail('新密码至少6位'); }
            $st = db()->prepare('UPDATE ' . t('users') . ' SET password = ? WHERE id = ?');
            $st->execute(array(password_hash($new, PASSWORD_DEFAULT), (int)$u['id']));
            ok();
            break;

        default:
            fail('未知操作');
    }
} catch (PDOException $e) {
    fail('数据库错误：' . $e->getMessage(), 500);
} catch (Exception $e) {
    fail('系统错误：' . $e->getMessage(), 500);
}
