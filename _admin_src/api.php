<?php
/**
 * 管理后台 AJAX API（安装时复制到 {后台路径}/api.php）
 */
define('XNZJ_BOOT', true);
define('XNZJ_ADMIN_DIR', __DIR__);
define('XNZJ_API', true);
require dirname(__DIR__) . '/includes/boot.php';

// 致命错误转 JSON 响应，避免前端拿到空响应报 "Unexpected end of JSON input"
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

    // 所有后台接口统一 CSRF 校验
    csrf_check();

    switch ($action) {

        case 'login':
            $username = req('username');
            $password = req('password');
            // 防爆破：锁定期间直接拒绝
            $locked = login_locked($username, 'admin');
            if ($locked > 0) {
                http_response_code(429);
                fail('登录失败次数过多，请 ' . max(1, ceil($locked / 60)) . ' 分钟后再试');
            }
            $st = db()->prepare('SELECT * FROM ' . t('admin') . ' WHERE username = ?');
            $st->execute(array($username));
            $a = $st->fetch();
            if (!$a || !password_verify($password, $a['password'])) {
                $remain = login_fail($username, 'admin');
                $msg = '账号或密码错误';
                if ($remain > 0) { $msg .= '（连续失败5次将锁定15分钟，已锁定，请 ' . max(1, ceil($remain / 60)) . ' 分钟后再试）'; }
                else { $msg .= '（连续失败5次将锁定15分钟）'; }
                fail($msg);
            }
            login_clear($username, 'admin');
            $_SESSION['admin_id'] = (int)$a['id'];
            ok(array('id' => (int)$a['id'], 'username' => $a['username']));
            break;

        case 'logout':
            unset($_SESSION['admin_id']);
            ok();
            break;

        case 'me':
            $a = current_admin();
            ok($a ? array('id' => (int)$a['id'], 'username' => $a['username']) : null);
            break;

        /* ---------- 修改管理员密码 ---------- */
        case 'admin_changepwd':
            require_admin();
            $old = req('old');
            $new = req('new');
            if (strlen($new) < 6) { fail('新密码至少6位'); }
            $a = current_admin();
            if (!password_verify($old, $a['password'])) { fail('原密码不正确'); }
            $st = db()->prepare('UPDATE ' . t('admin') . ' SET password = ? WHERE id = ?');
            $st->execute(array(password_hash($new, PASSWORD_DEFAULT), (int)$a['id']));
            ok();
            break;

        /* ---------- 仪表盘 ---------- */
        case 'stats':
            require_admin();
            $st = db()->query('SELECT COUNT(*) c FROM ' . t('users'))->fetch();
            $users = (int)$st['c'];
            $st = db()->query('SELECT COUNT(*) c FROM ' . t('orders'))->fetch();
            $orders = (int)$st['c'];
            $st = db()->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM " . t('orders') . " WHERE status = 'paid'")->fetch();
            $paidOrders = (int)$st['c'];
            $income = $st['s'];
            $st = db()->query('SELECT COUNT(*) c FROM ' . t('products') . ' WHERE status = 1')->fetch();
            $activeProducts = (int)$st['c'];
            $st = db()->query("SELECT COUNT(*) c FROM " . t('tickets') . " WHERE status IN ('open','replied')")->fetch();
            $openTickets = (int)$st['c'];
            $st = db()->query('SELECT * FROM ' . t('orders') . ' ORDER BY id DESC LIMIT 8')->fetchAll();
            $recentOrders = $st;
            ok(compact('users', 'orders', 'paidOrders', 'income', 'activeProducts', 'openTickets', 'recentOrders'));
            break;

        /* ---------- 服务器 ---------- */
        case 'servers_list':
            require_admin();
            ok(db()->query('SELECT * FROM ' . t('servers') . ' ORDER BY id DESC')->fetchAll());
            break;

        case 'server_save':
            require_admin();
            $id = (int)req('id');
            $name = req('name');
            $host = req('host');
            $username = req('username');
            $secret = req('secret');
            $ip = req('ip');
            $port = (int)req('port', 80);
            $https = req('https') ? 1 : 0;
            $note = req('note');
            $status = req('status') ? 1 : 0;
            $type = in_array(req('type'), array('btn', 'ep'), true) ? req('type') : 'btn';
            $epModule = req('ep_module', '74');
            if ($epModule === '') { $epModule = $type === 'ep' ? 'php' : '74'; }
            if ($name === '' || $host === '' || $secret === '') { fail('请填写完整信息'); }
            if ($type !== 'ep' && $username === '') { fail('宝塔面板请填写面板登录账号'); }
            if ($port < 1 || $port > 65535) { fail('端口不正确'); }
            if ($id > 0) {
                $st = db()->prepare('UPDATE ' . t('servers') . ' SET name=?, host=?, username=?, secret=?, ip=?, port=?, https=?, note=?, status=?, type=?, ep_module=? WHERE id=?');
                $st->execute(array($name, $host, $username, $secret, $ip, $port, $https, $note, $status, $type, $epModule, $id));
            } else {
                $st = db()->prepare('INSERT INTO ' . t('servers') . ' (name, host, username, secret, ip, port, https, note, status, type, ep_module, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute(array($name, $host, $username, $secret, $ip, $port, $https, $note, $status, $type, $epModule, date('Y-m-d H:i:s')));
                $id = (int)db()->lastInsertId();
            }
            ok(array('id' => $id));
            break;

        case 'server_delete':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT COUNT(*) c FROM ' . t('plans') . ' WHERE server_id = ?');
            $st->execute(array($id));
            if ((int)$st->fetch()['c'] > 0) { fail('该服务器下存在产品方案，无法删除'); }
            $st = db()->prepare('DELETE FROM ' . t('servers') . ' WHERE id = ?');
            $st->execute(array($id));
            ok();
            break;

        case 'server_test':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT * FROM ' . t('servers') . ' WHERE id = ?');
            $st->execute(array($id));
            $s = $st->fetch();
            if (!$s) { fail('服务器不存在'); }
            $r = panel_test($s);
            if (panel_ok($r)) {
                ok(array('msg' => '连接成功' . (isset($r['info']) && $r['info'] ? '：' . $r['info'] : '')));
            }
            $m = panel_errmsg($r); fail($m !== '' ? '连接失败：' . $m : '连接失败');
            break;

        /* ---------- 产品方案 ---------- */
        case 'plans_list':
            require_admin();
            $rows = db()->query('SELECT p.*, s.name AS server_name FROM ' . t('plans') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id ORDER BY p.sort ASC, p.id DESC')->fetchAll();
            ok($rows);
            break;

        case 'plan_save':
            require_admin();
            $id = (int)req('id');
            $serverId = (int)req('server_id');
            $name = req('name');
            $price = (float)req('price');
            $a1 = (int)req('a1', 0); $a2 = (int)req('a2', 0); $a3 = (int)req('a3', -1);
            $a4 = (int)req('a4', 0); $a5 = (int)req('a5', 0); $a6 = (int)req('a6', 0);
            $a7 = req('a7', '80,443s'); $a8 = (int)req('a8', 0); $a9 = (int)req('a9', 0);
            $a10 = (int)req('a10', 1); $note = req('note'); $sort = (int)req('sort', 0);
            $status = req('status') ? 1 : 0;
            $zjmfApiId = (int)req('zjmf_api_id', 0);
            $upstreamPid = (int)req('upstream_pid', 0);
            $upstreamName = req('upstream_name');
            if ($name === '' || ($zjmfApiId < 1 && $serverId < 1)) { fail('请填写方案名称并选择服务器或上游商品'); }
            if ($price < 0) { fail('价格不正确'); }
            if ($zjmfApiId > 0 && $upstreamPid < 1) { fail('请选择上游商品'); }
            if ($id > 0) {
                $st = db()->prepare('UPDATE ' . t('plans') . ' SET server_id=?, name=?, price=?, a1=?, a2=?, a3=?, a4=?, a5=?, a6=?, a7=?, a8=?, a9=?, a10=?, note=?, sort=?, status=?, zjmf_api_id=?, upstream_pid=?, upstream_name=? WHERE id=?');
                $st->execute(array($serverId, $name, $price, $a1, $a2, $a3, $a4, $a5, $a6, $a7, $a8, $a9, $a10, $note, $sort, $status, $zjmfApiId, $upstreamPid, $upstreamName, $id));
            } else {
                $st = db()->prepare('INSERT INTO ' . t('plans') . ' (server_id, name, price, a1, a2, a3, a4, a5, a6, a7, a8, a9, a10, note, sort, status, zjmf_api_id, upstream_pid, upstream_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute(array($serverId, $name, $price, $a1, $a2, $a3, $a4, $a5, $a6, $a7, $a8, $a9, $a10, $note, $sort, $status, $zjmfApiId, $upstreamPid, $upstreamName, date('Y-m-d H:i:s')));
                $id = (int)db()->lastInsertId();
            }
            ok(array('id' => $id));
            break;

        case 'plan_delete':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT COUNT(*) c FROM ' . t('orders') . ' WHERE plan_id = ?');
            $st->execute(array($id));
            if ((int)$st->fetch()['c'] > 0) { fail('该方案已有订单，无法删除（可停用）'); }
            $st = db()->prepare('DELETE FROM ' . t('plans') . ' WHERE id = ?');
            $st->execute(array($id));
            ok();
            break;

        /* ---------- 订单 ---------- */
        case 'orders_list':
            require_admin();
            $where = '1=1';
            $args = array();
            $status = req('status');
            if (in_array($status, array('pending', 'paid', 'cancelled'), true)) {
                $where .= ' AND status = ?';
                $args[] = $status;
            }
            $kw = req('kw');
            if ($kw !== '') {
                $where .= ' AND (order_no LIKE ? OR plan_name LIKE ?)';
                $args[] = '%' . $kw . '%';
                $args[] = '%' . $kw . '%';
            }
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 200');
            $st->execute($args);
            $orders = $st->fetchAll();
            $userIds = array();
            foreach ($orders as $o) { $userIds[(int)$o['user_id']] = 1; }
            $emails = array();
            if ($userIds) {
                $in = implode(',', array_map('intval', array_keys($userIds)));
                foreach (db()->query('SELECT id, email FROM ' . t('users') . ' WHERE id IN (' . $in . ')')->fetchAll() as $u) {
                    $emails[(int)$u['id']] = $u['email'];
                }
            }
            foreach ($orders as &$o) {
                $o['email'] = isset($emails[(int)$o['user_id']]) ? $emails[(int)$o['user_id']] : '';
                $st = db()->prepare('SELECT id, status FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
                $st->execute(array((int)$o['id']));
                $o['product'] = $st->fetch();
            }
            unset($o);
            ok($orders);
            break;

        case 'order_get':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
            $st->execute(array($id));
            $o = $st->fetch();
            if (!$o) { fail('订单不存在'); }
            $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
            $st->execute(array($id));
            $o['product'] = $st->fetch();
            $st = db()->prepare('SELECT email FROM ' . t('users') . ' WHERE id = ?');
            $st->execute(array((int)$o['user_id']));
            $u = $st->fetch();
            $o['email'] = $u ? $u['email'] : '';
            ok($o);
            break;

        case 'order_pay':
            // 手动标记已支付并开通（线下收款场景）
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
            $st->execute(array($id));
            $o = $st->fetch();
            if (!$o) { fail('订单不存在'); }
            $r = fulfill_order($id, 'MANUAL-' . date('YmdHis'));
            if ($r['ok']) { ok(array('msg' => '已标记支付并开通成功')); }
            fail($r['msg']);
            break;

        case 'order_provision':
            // 已支付订单重新开通
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE id = ?');
            $st->execute(array($id));
            $o = $st->fetch();
            if (!$o) { fail('订单不存在'); }
            if ($o['status'] !== 'paid') { fail('订单未支付'); }
            $r = provision_order($id);
            if ($r['ok']) { ok(array('msg' => '开通成功')); }
            fail($r['msg']);
            break;

        case 'order_cancel':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('UPDATE ' . t('orders') . ' SET status = ? WHERE id = ? AND status = ?');
            $st->execute(array('cancelled', $id, 'pending'));
            ok();
            break;

        /* ---------- 产品 ---------- */
        case 'products_list':
            require_admin();
            $kw = req('kw');
            $sql = 'SELECT p.*, s.name AS server_name, u.email FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id LEFT JOIN ' . t('users') . ' u ON u.id = p.user_id';
            $args = array();
            if ($kw !== '') {
                $sql .= ' WHERE p.username LIKE ? OR p.domain LIKE ? OR u.email LIKE ?';
                $args = array('%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%');
            }
            $sql .= ' ORDER BY p.id DESC LIMIT 200';
            $st = db()->prepare($sql);
            $st->execute($args);
            ok($st->fetchAll());
            break;

        case 'product_action':
            require_admin();
            $id = (int)req('id');
            $act = req('act');
            $st = db()->prepare('SELECT p.*, s.host AS server_host, s.https AS server_https, s.username AS server_user, s.secret AS server_secret, s.ip AS server_ip, s.port AS server_port, s.type AS server_type, pl.zjmf_api_id, pl.upstream_pid, pl.upstream_name FROM ' . t('products') . ' p LEFT JOIN ' . t('servers') . ' s ON s.id = p.server_id LEFT JOIN ' . t('plans') . ' pl ON pl.id = p.plan_id WHERE p.id = ?');
            $st->execute(array($id));
            $p = $st->fetch();
            if (!$p) { fail('产品不存在'); }
            $server = array('host' => $p['server_host'], 'https' => (int)$p['server_https'], 'type' => $p['server_type'], 'username' => $p['server_user'], 'secret' => $p['server_secret'], 'ip' => $p['server_ip'], 'port' => (int)$p['server_port']);

            // 魔方财务上游产品：暂停/恢复/删除/改密/续费/同步全部走上游 API
            $isUp = (int)$p['upstream_hostid'] > 0 && (int)$p['zjmf_api_id'] > 0;
            $upApi = $isUp ? zjmf_api_row((int)$p['zjmf_api_id']) : null;
            if ($isUp && (!$upApi || (int)$upApi['status'] !== 1)) { fail('上游接口未配置或已停用'); }

            if ($act === 'suspend') {
                if ($isUp) {
                    $r = zjmf_provision_default($upApi, (int)$p['upstream_hostid'], 'suspend', array('reason' => '管理员暂停'));
                    if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '暂停失败'); }
                } else {
                    $r = panel_set_status($server, $p['username'], 1);
                    if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '暂停失败'); }
                }
                db()->exec('UPDATE ' . t('products') . " SET status = 2 WHERE id = " . (int)$id);
                ok();
            } elseif ($act === 'unsuspend') {
                if ($isUp) {
                    $r = zjmf_provision_default($upApi, (int)$p['upstream_hostid'], 'unsuspend');
                    if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '恢复失败'); }
                } else {
                    $r = panel_set_status($server, $p['username'], 0);
                    if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '恢复失败'); }
                }
                db()->exec('UPDATE ' . t('products') . " SET status = 1 WHERE id = " . (int)$id);
                ok();
            } elseif ($act === 'terminate') {
                if ($isUp) {
                    $r = zjmf_host_cancel($upApi, (int)$p['upstream_hostid']);
                    if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '删除失败'); }
                } else {
                    $r = panel_del($server, $p['username']);
                    if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '删除失败'); }
                }
                db()->exec('UPDATE ' . t('products') . " SET status = 3 WHERE id = " . (int)$id);
                db()->exec('UPDATE ' . t('orders') . " SET status = 'cancelled' WHERE id = " . (int)$p['order_id']);
                ok();
            } elseif ($act === 'repwd') {
                $pwd = req('pwd');
                if (strlen($pwd) < 6 || strlen($pwd) > 32) { fail('密码长度需在6-32位'); }
                if ($isUp) {
                    $r = zjmf_provision_default($upApi, (int)$p['upstream_hostid'], 'crack_pass', array('password' => $pwd));
                    if (!isset($r['status']) || (int)$r['status'] !== 200) { $m = isset($r['msg']) ? $r['msg'] : ''; fail($m !== '' ? $m : '修改密码失败'); }
                } else {
                    $r = panel_chg_pwd($server, $p['username'], $pwd);
                    if (!panel_ok($r)) { $m = panel_errmsg($r); fail($m !== '' ? $m : '修改密码失败'); }
                }
                $st = db()->prepare('UPDATE ' . t('products') . ' SET password = ? WHERE id = ?');
                $st->execute(array(enc($pwd), $id));
                ok();
            } elseif ($act === 'renew') {
                // 续费：上游产品走上游续费并扣上游余额；本地产品仅延长到期时间
                $st = db()->prepare('SELECT cycle FROM ' . t('orders') . ' WHERE id = ?');
                $st->execute(array((int)$p['order_id']));
                $o = $st->fetch();
                $cycle = $o && isset($o['cycle']) ? $o['cycle'] : 'month';
                $now = date('Y-m-d H:i:s');
                $base = $p['expires_at'] && strtotime($p['expires_at']) > time() ? $p['expires_at'] : $now;
                $newExp = add_months($base, cycle_months($cycle));
                if ($isUp) {
                    $r = zjmf_host_renew($upApi, (int)$p['upstream_hostid'], zjmf_cycle($cycle));
                    if (!$r['ok']) { fail($r['msg']); }
                }
                $st = db()->prepare('UPDATE ' . t('products') . ' SET expires_at = ? WHERE id = ?');
                $st->execute(array($newExp, $id));
                ok(array('msg' => '续费成功，到期时间：' . $newExp));
            } elseif ($act === 'sync') {
                if (!$isUp) { fail('仅魔方财务上游产品支持同步'); }
                $h = zjmf_host_header($upApi, (int)$p['upstream_hostid']);
                if (!$h['ok']) { fail($h['msg']); }
                $d = $h['data'];
                $upd = array();
                if (isset($d['domain'])) { $upd['domain'] = substr($d['domain'], 0, 190); }
                if (isset($d['username'])) { $upd['username'] = substr($d['username'], 0, 100); }
                if (isset($d['password']) && $d['password'] !== '') { $upd['password'] = enc($d['password']); }
                if (isset($d['dedicatedip']) && $d['dedicatedip'] !== '') { $upd['domain'] = substr($d['dedicatedip'], 0, 190); }
                $map = array('Active' => 1, 'Suspended' => 2, 'Terminated' => 3, 'Deleted' => 3, 'Cancelled' => 3, 'Pending' => 0);
                if (isset($d['domainstatus']) && isset($map[$d['domainstatus']])) {
                    $upd['status'] = $map[$d['domainstatus']];
                    $upd['upstream_status'] = substr($d['domainstatus'], 0, 20);
                }
                if (isset($d['nextduedate']) && (int)$d['nextduedate'] > 0) { $upd['expires_at'] = date('Y-m-d H:i:s', (int)$d['nextduedate']); }
                if (!empty($upd)) {
                    $set = array();
                    foreach ($upd as $k => $v) { $set[] = $k . ' = ?'; }
                    $st = db()->prepare('UPDATE ' . t('products') . ' SET ' . implode(',', $set) . ' WHERE id = ?');
                    $args = array_values($upd);
                    $args[] = $id;
                    $st->execute($args);
                }
                ok(array('msg' => '同步完成' . (isset($d['domainstatus']) ? '，上游状态：' . $d['domainstatus'] : '')));
            }
            fail('未知操作');
            break;

        /* ---------- 用户 ---------- */
        case 'users_list':
            require_admin();
            $kw = req('kw');
            $sql = 'SELECT id, email, status, created_at FROM ' . t('users');
            $args = array();
            if ($kw !== '') { $sql .= ' WHERE email LIKE ?'; $args[] = '%' . $kw . '%'; }
            $sql .= ' ORDER BY id DESC LIMIT 200';
            $st = db()->prepare($sql);
            $st->execute($args);
            ok($st->fetchAll());
            break;

        case 'user_toggle':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT status FROM ' . t('users') . ' WHERE id = ?');
            $st->execute(array($id));
            $u = $st->fetch();
            if (!$u) { fail('用户不存在'); }
            $new = (int)$u['status'] === 1 ? 0 : 1;
            $st = db()->prepare('UPDATE ' . t('users') . ' SET status = ? WHERE id = ?');
            $st->execute(array($new, $id));
            ok(array('status' => $new));
            break;

        case 'user_login_as':
            // 以该客户身份登录：生成一次性签名链接（HMAC + 5分钟有效）
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT id, email, status FROM ' . t('users') . ' WHERE id = ?');
            $st->execute(array($id));
            $u = $st->fetch();
            if (!$u) { fail('用户不存在'); }
            if ((int)$u['status'] !== 1) { fail('该用户已被禁用，无法模拟登录'); }
            $exp = time() + 300;
            $sig = hash_hmac('sha256', $id . '.' . $exp, APP_KEY);
            ok(array(
                'url' => root_url() . '/index.php?login_as=' . $id . '.' . $exp . '.' . $sig,
                'email' => $u['email'],
            ));
            break;

        case 'user_changepwd':
            // 管理员强制重置用户密码（改后用户需用新密码登录）
            require_admin();
            $id = (int)req('id');
            $pwd = req('pwd');
            if (strlen($pwd) < 6 || strlen($pwd) > 32) { fail('密码长度需在6-32位'); }
            $st = db()->prepare('SELECT id, email, status FROM ' . t('users') . ' WHERE id = ?');
            $st->execute(array($id));
            $u = $st->fetch();
            if (!$u) { fail('用户不存在'); }
            $st = db()->prepare('UPDATE ' . t('users') . ' SET password = ? WHERE id = ?');
            $st->execute(array(password_hash($pwd, PASSWORD_DEFAULT), $id));
            ok(array('email' => $u['email']));
            break;

        /* ---------- 工单 ---------- */
        case 'tickets_list':
            require_admin();
            $status = req('status');
            $sql = 'SELECT t.*, u.email FROM ' . t('tickets') . ' t LEFT JOIN ' . t('users') . ' u ON u.id = t.user_id';
            $args = array();
            if (in_array($status, array('open', 'replied', 'closed'), true)) {
                $sql .= ' WHERE t.status = ?';
                $args[] = $status;
            }
            $sql .= ' ORDER BY t.id DESC LIMIT 200';
            $st = db()->prepare($sql);
            $st->execute($args);
            ok($st->fetchAll());
            break;

        case 'ticket_get':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT t.*, u.email FROM ' . t('tickets') . ' t LEFT JOIN ' . t('users') . ' u ON u.id = t.user_id WHERE t.id = ?');
            $st->execute(array($id));
            $tk = $st->fetch();
            if (!$tk) { fail('工单不存在'); }
            $st = db()->prepare('SELECT * FROM ' . t('ticket_replies') . ' WHERE ticket_id = ? ORDER BY id ASC');
            $st->execute(array($id));
            ok(array('ticket' => $tk, 'replies' => $st->fetchAll()));
            break;

        case 'ticket_reply':
            require_admin();
            $id = (int)req('id');
            $content = req('content');
            if (mb_strlen($content) < 1 || mb_strlen($content) > 5000) { fail('回复内容长度需在1-5000字'); }
            $now = date('Y-m-d H:i:s');
            $st = db()->prepare('INSERT INTO ' . t('ticket_replies') . ' (ticket_id, admin, content, created_at) VALUES (?,1,?,?)');
            $st->execute(array($id, $content, $now));
            $st = db()->prepare('UPDATE ' . t('tickets') . ' SET status = ?, updated_at = ? WHERE id = ?');
            $st->execute(array('replied', $now, $id));
            ok();
            break;

        case 'ticket_status':
            require_admin();
            $id = (int)req('id');
            $status = req('status');
            if (!in_array($status, array('open', 'closed'), true)) { fail('状态不正确'); }
            $st = db()->prepare('UPDATE ' . t('tickets') . ' SET status = ?, updated_at = ? WHERE id = ?');
            $st->execute(array($status, date('Y-m-d H:i:s'), $id));
            ok();
            break;

        /* ---------- 魔方财务上游 ---------- */
        case 'zjmf_list':
            require_admin();
            zjmf_ensure_schema();
            ok(db()->query('SELECT * FROM ' . t('zjmf_apis') . ' ORDER BY id DESC')->fetchAll());
            break;

        case 'zjmf_save':
            require_admin();
            zjmf_ensure_schema();
            $id = (int)req('id');
            $name = req('name');
            $hostname = zjmf_normalize_host(req('hostname'));
            $username = req('username');
            $password = req('password');
            $note = req('note');
            $status = req('status') ? 1 : 0;
            if ($name === '' || $hostname === '' || $username === '') { fail('请填写名称、接口地址和用户名'); }
            if (!preg_match('#^https?://#i', $hostname)) { fail('接口地址格式不正确'); }
            if ($id > 0) {
                $api = zjmf_api_row($id);
                if (!$api) { fail('接口不存在'); }
                if ($password !== '') {
                    $st = db()->prepare('UPDATE ' . t('zjmf_apis') . ' SET name=?, hostname=?, username=?, password=?, note=?, status=? WHERE id=?');
                    $st->execute(array($name, $hostname, $username, enc($password), $note, $status, $id));
                } else {
                    $st = db()->prepare('UPDATE ' . t('zjmf_apis') . ' SET name=?, hostname=?, username=?, note=?, status=? WHERE id=?');
                    $st->execute(array($name, $hostname, $username, $note, $status, $id));
                }
                // 重置 JWT 缓存
                set_setting('zjmf_jwt_' . $id, '');
            } else {
                if ($password === '') { fail('请填写API密钥'); }
                $st = db()->prepare('INSERT INTO ' . t('zjmf_apis') . ' (name, hostname, username, password, status, note, created_at) VALUES (?,?,?,?,?,?,?)');
                $st->execute(array($name, $hostname, $username, enc($password), $status, $note, date('Y-m-d H:i:s')));
                $id = (int)db()->lastInsertId();
            }
            ok(array('id' => $id));
            break;

        case 'zjmf_delete':
            require_admin();
            $id = (int)req('id');
            $st = db()->prepare('SELECT COUNT(*) c FROM ' . t('plans') . ' WHERE zjmf_api_id = ?');
            $st->execute(array($id));
            if ((int)$st->fetch()['c'] > 0) { fail('该上游接口下存在绑定方案，无法删除（可停用）'); }
            $st = db()->prepare('DELETE FROM ' . t('zjmf_apis') . ' WHERE id = ?');
            $st->execute(array($id));
            set_setting('zjmf_jwt_' . $id, '');
            ok();
            break;

        case 'zjmf_test':
            require_admin();
            $id = (int)req('id');
            $api = zjmf_api_row($id);
            if (!$api) { fail('接口不存在'); }
            $r = zjmf_test($api);
            if ($r['ok']) { ok(array('msg' => $r['msg'], 'credit' => isset($r['credit']) ? $r['credit'] : '')); }
            fail($r['msg']);
            break;

        case 'zjmf_credit':
            require_admin();
            $id = (int)req('id');
            $api = zjmf_api_row($id);
            if (!$api) { fail('接口不存在'); }
            $r = zjmf_credit($api);
            ok(array('credit' => $r['credit'], 'currency' => $r['currency']));
            break;

        case 'zjmf_products':
            require_admin();
            $id = (int)req('id');
            $api = zjmf_api_row($id);
            if (!$api) { fail('接口不存在'); }
            $r = zjmf_products($api);
            if (!$r['ok']) { fail($r['msg']); }
            $list = $r['data'];
            // 规范化字段，便于前端展示
            $out = array();
            foreach ((array)$list as $item) {
                if (!is_array($item) || empty($item['id'])) { continue; }
                $out[] = array(
                    'id' => (int)$item['id'],
                    'name' => isset($item['name']) ? $item['name'] : ('商品#' . $item['id']),
                    'type' => isset($item['type']) ? $item['type'] : '',
                    'price' => isset($item['product_price']) ? $item['product_price'] : (isset($item['price']) ? $item['price'] : ''),
                    'cycle' => isset($item['billingcycle_zh']) ? $item['billingcycle_zh'] : (isset($item['cycle']) ? $item['cycle'] : ''),
                    'description' => isset($item['description']) ? $item['description'] : '',
                );
            }
            ok(array('list' => $out, 'count' => count($out)));
            break;

        case 'zjmf_import':
            // 将上游商品导入为本地方案（开通方式=魔方上游）
            require_admin();
            zjmf_ensure_schema();
            $apiId = (int)req('api_id');
            $pid = (int)req('pid');
            $name = req('name');
            $price = (float)req('price');
            $note = req('note');
            $status = req('status') ? 1 : 0;
            if ($apiId < 1 || $pid < 1 || $name === '') { fail('参数不完整'); }
            if ($price < 0) { fail('价格不正确'); }
            $st = db()->prepare('INSERT INTO ' . t('plans') . ' (server_id, name, price, a1, a2, a3, a4, a5, a6, a7, a8, a9, a10, note, sort, status, zjmf_api_id, upstream_pid, upstream_name, created_at) VALUES (0,?,?,0,0,-1,0,0,0,\'80,443s\',0,0,1,?,0,?,?,?,?,?)');
            $st->execute(array($name, $price, $note, $status, $apiId, $pid, $name, date('Y-m-d H:i:s')));
            ok(array('id' => (int)db()->lastInsertId()));
            break;

        /* ---------- 系统设置 ---------- */
        case 'settings_get':
            require_admin();
            ok(array(
                'site' => array(
                    'site_name' => setting('site_name'),
                    'site_slogan' => setting('site_slogan'),
                    'logo' => setting('logo'),
                    'host_prefix' => setting('host_prefix', 'ep'),
                ),
                'smtp' => array(
                    'smtp_host' => setting('smtp_host'),
                    'smtp_port' => setting('smtp_port', '465'),
                    'smtp_user' => setting('smtp_user'),
                    'smtp_pass' => setting('smtp_pass'),
                    'smtp_from' => setting('smtp_from'),
                    'smtp_from_name' => setting('smtp_from_name'),
                    'smtp_secure' => setting('smtp_secure', 'ssl'),
                ),
                'pay' => array(
                    'pay_api' => setting('pay_api'),
                    'pay_pid' => setting('pay_pid'),
                    'pay_private_key' => setting('pay_private_key'),
                    'pay_public_key' => setting('pay_public_key'),
                    'notify_url' => root_url() . '/notify.php',
                    'return_url' => root_url() . '/return.php',
                ),
            ));
            break;

        case 'settings_save':
            require_admin();
            $section = req('section');
            $fields = array();
            if ($section === 'site') {
                $fields = array('site_name', 'site_slogan', 'logo', 'host_prefix');
            } elseif ($section === 'smtp') {
                $fields = array('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'smtp_secure');
            } elseif ($section === 'pay') {
                $fields = array('pay_api', 'pay_pid', 'pay_private_key', 'pay_public_key');
            } else {
                fail('未知设置项');
            }
            foreach ($fields as $f) {
                if (isset($_POST[$f])) { set_setting($f, (string)$_POST[$f]); }
            }
            ok();
            break;

        case 'smtp_test':
            require_admin();
            $to = req('to');
            if (!email_valid($to)) { fail('请输入正确的收件邮箱'); }
            $r = send_test_mail($to);
            if ($r['ok']) { ok(array('msg' => '测试邮件已发送到 ' . $to)); }
            fail($r['msg']);
            break;

        default:
            fail('未知操作');
    }
} catch (PDOException $e) {
    fail('数据库错误：' . $e->getMessage(), 500);
} catch (Exception $e) {
    fail('系统错误：' . $e->getMessage(), 500);
}
