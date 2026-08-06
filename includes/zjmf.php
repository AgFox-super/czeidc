<?php
/**
 * 魔方财务（智简魔方）上下游 API 客户端
 *
 * xnzj 作为「下游/代理商」对接上游魔方财务系统：
 *   - 认证：POST {上游}/zjmf_api_login 获取 JWT（请求头 Authorization: Bearer xxx，405 时强制重登重试）
 *   - 商品：GET /cart/all（列表）、GET /api/product/prodetail（详情）、GET /cart/get_product_config（配置）
 *   - 建单开通：GET /user_info → POST /cart/clear → POST /cart/add_to_shop → POST /cart/settle → POST /apply_credit
 *   - 主机操作：POST /provision/default（suspend/unsuspend/on/off/reboot）、POST /host/cancel（删除）、POST /host/renew（续费）
 *   - 主机信息：GET /host/header
 *   - 回传接收：POST /api/host/sync（签名 = strtoupper(md5(json_encode(ksort({id,token,rand_str},SORT_STRING))))）
 *
 * 协议依据：魔方财务 3.7.6 源码（app/zjmf.php 的 zjmfCurl/createSign/pushHostInfo、
 * app/common/logic/Host.php 的 create/suspend/unsuspend/terminate/renew、app/api/controller/HostController.php）
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

/* ================= 数据库结构（幂等，兼容已安装系统） ================= */

function zjmf_ensure_schema() {
    // 上游接口配置表
    db()->exec('CREATE TABLE IF NOT EXISTS ' . t('zjmf_apis') . ' (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        hostname VARCHAR(190) NOT NULL COMMENT \'上游魔方财务地址(带协议)\',
        username VARCHAR(190) NOT NULL COMMENT \'上游注册账号(手机/邮箱)\',
        password TEXT NOT NULL COMMENT \'AES加密后的API密钥\',
        status TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // plans：绑定上游商品
    $planCols = array(
        'zjmf_api_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'魔方上游接口ID(0=本地面板)\'',
        'upstream_pid INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'上游商品ID\'',
        'upstream_name VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'上游商品名\'',
    );
    foreach ($planCols as $sql) { zjmf_add_col('plans', $sql); }

    // products：上游主机映射
    $prodCols = array(
        'upstream_hostid INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'上游主机ID\'',
        'upstream_token VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'上游回传验签token\'',
        'upstream_status VARCHAR(20) NOT NULL DEFAULT \'\' COMMENT \'上游主机状态\'',
    );
    foreach ($prodCols as $sql) { zjmf_add_col('products', $sql); }
}

function zjmf_add_col($table, $colSql) {
    preg_match('/^([a-zA-Z_]+)/', $colSql, $m);
    if (empty($m[1])) { return; }
    $col = $m[1];
    $st = db()->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute(array(t($table), $col));
    if ((int)$st->fetch()['c'] === 0) {
        db()->exec('ALTER TABLE ' . t($table) . ' ADD COLUMN ' . $colSql);
    }
}

/* ================= 基础 HTTP ================= */

function zjmf_http($url, $data = array(), $timeout = 30, $method = 'POST', $headers = array()) {
    $ch = curl_init();
    $method = strtoupper($method);
    if ($method === 'GET') {
        $qs = http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url . ($qs !== '' ? '?' . $qs : ''));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string)$data);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; xnzj-zjmf/1.0)');
    if (!empty($headers)) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $content = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') { return array('status' => 400, 'msg' => '请求上游失败：' . $err); }
    $json = json_decode($content, true);
    if (!is_array($json)) {
        // 带上响应片段便于排查（如被 CDN/WAF 拦截返回 HTML、登录页跳转等）
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
        if ($snippet === '') { $snippet = $content; }
        return array('status' => 400, 'msg' => '上游返回异常（HTTP ' . $code . '），非JSON响应：' . utf8_cut($snippet, 160));
    }
    return $json;
}

/* ================= 认证（JWT） ================= */

function zjmf_api_row($id) {
    $st = db()->prepare('SELECT * FROM ' . t('zjmf_apis') . ' WHERE id = ?');
    $st->execute(array((int)$id));
    return $st->fetch();
}

function zjmf_normalize_host($host) {
    $host = trim($host);
    if ($host === '') { return ''; }
    if (!preg_match('#^https?://#i', $host)) { $host = 'https://' . $host; }
    return rtrim($host, '/');
}

function zjmf_login($api, $force = false) {
    $key = 'zjmf_jwt_' . (int)$api['id'];
    $cached = setting($key, '');
    if (!$force && $cached !== '') {
        $parts = explode('|', $cached, 2);
        if (count($parts) === 2 && (int)$parts[0] > time()) { return array('status' => 200, 'jwt' => $parts[1]); }
    }
    $url = rtrim($api['hostname'], '/') . '/zjmf_api_login';
    $res = zjmf_http($url, array('username' => $api['username'], 'password' => dec($api['password'])), 30, 'POST');
    if (isset($res['status']) && (int)$res['status'] === 200) {
        $jwt = isset($res['jwt']) ? $res['jwt'] : (isset($res['data']['jwt']) ? $res['data']['jwt'] : '');
        if ($jwt !== '') {
            set_setting($key, (time() + 3600) . '|' . $jwt);
            return array('status' => 200, 'jwt' => $jwt);
        }
    }
    return array('status' => isset($res['status']) ? (int)$res['status'] : 400, 'msg' => isset($res['msg']) ? $res['msg'] : '登录失败');
}

/** 核心请求：带 JWT，405 时重登重试一次 */
function zjmf_curl($api, $path, $data = array(), $timeout = 30, $method = 'POST') {
    if (empty($api['hostname'])) { return array('status' => 400, 'msg' => '上游接口地址未配置'); }
    $login = zjmf_login($api);
    if ($login['status'] !== 200) { return $login; }
    $url = rtrim($api['hostname'], '/') . '/' . ltrim($path, '/');
    $headers = array('Authorization: Bearer ' . $login['jwt']);
    $res = zjmf_http($url, $data, $timeout, $method, $headers);
    if (isset($res['status']) && (int)$res['status'] === 405) {
        $login = zjmf_login($api, true);
        if ($login['status'] !== 200) { return $login; }
        $headers = array('Authorization: Bearer ' . $login['jwt']);
        $res = zjmf_http($url, $data, $timeout, $method, $headers);
        if (isset($res['status']) && (int)$res['status'] === 405) {
            return array('status' => 400, 'msg' => '上游 API 账号或密钥错误');
        }
    }
    return $res;
}

/* ================= 上游业务接口 ================= */

/** 测试连接：登录并读取用户信息（余额） */
function zjmf_test($api) {
    $login = zjmf_login($api, true);
    if ($login['status'] !== 200) {
        return array('ok' => false, 'msg' => isset($login['msg']) ? $login['msg'] : '登录失败');
    }
    $info = zjmf_curl($api, '/user_info', array(), 15, 'GET');
    if (isset($info['status']) && (int)$info['status'] === 200) {
        // 真实魔方财务：user 在顶层（home/user/index），余额在 user.credit
        $user = isset($info['user']) && is_array($info['user']) ? $info['user'] : (isset($info['data']['user']) ? $info['data']['user'] : array());
        $credit = isset($user['credit']) ? $user['credit'] : (isset($info['credit']) ? $info['credit'] : '');
        return array('ok' => true, 'msg' => '连接成功，上游余额：' . $credit, 'credit' => $credit);
    }
    return array('ok' => false, 'msg' => isset($info['msg']) ? '连接失败：' . $info['msg'] : '连接失败');
}

/** 上游余额 */
function zjmf_credit($api) {
    $info = zjmf_curl($api, '/user_info', array(), 15, 'GET');
    if (isset($info['status']) && (int)$info['status'] === 200) {
        $user = isset($info['user']) && is_array($info['user']) ? $info['user'] : (isset($info['data']['user']) ? $info['data']['user'] : array());
        return array(
            'credit' => isset($user['credit']) ? $user['credit'] : (isset($info['credit']) ? $info['credit'] : '0'),
            'currency' => isset($user['currency']) ? $user['currency'] : (isset($info['data']['currency']) ? $info['data']['currency'] : ''),
        );
    }
    return array('credit' => '', 'currency' => '', 'msg' => isset($info['msg']) ? $info['msg'] : '');
}

/** 上游商品列表：优先 cart/all（官方导入路径，按分组嵌套），失败/为空时兜底公开的 /api/product/list */
function zjmf_products($api) {
    $res = zjmf_curl($api, '/cart/all', array(), 30, 'GET');
    $list = array();
    if (isset($res['status']) && (int)$res['status'] === 200) {
        $data = isset($res['data']) ? $res['data'] : array();
        // cart/all 真实结构：data.products = [{id,name,products:[{id,type,name,description}]}]
        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $group) {
                if (!is_array($group)) { continue; }
                $subs = isset($group['products']) && is_array($group['products']) ? $group['products'] : array();
                foreach ($subs as $item) {
                    if (is_array($item) && !empty($item['id'])) { $list[] = $item; }
                }
            }
        } elseif (isset($data['list']) && is_array($data['list'])) {
            $list = $data['list'];
        } elseif (is_array($data)) {
            $list = $data;
        }
    }
    if (empty($list)) {
        // 兜底：/api/product/list（公开接口，返回 data.list + currency_code）
        $res2 = zjmf_http(rtrim($api['hostname'], '/') . '/api/product/list', array(), 15, 'GET');
        if (isset($res2['status']) && (int)$res2['status'] === 200 && isset($res2['data']['list']) && is_array($res2['data']['list'])) {
            $list = $res2['data']['list'];
        } elseif (!empty($res['msg']) && empty($list)) {
            return array('ok' => false, 'msg' => $res['msg']);
        }
    }
    return array('ok' => true, 'data' => $list);
}

/** 上游商品详情（api/product/prodetail，公开接口） */
function zjmf_products_detail($api, $pids) {
    $data = array();
    foreach ((array)$pids as $i => $pid) { $data['pids[' . $i . ']'] = $pid; }
    $res = zjmf_http(rtrim($api['hostname'], '/') . '/api/product/prodetail', $data, 15, 'GET');
    if (isset($res['status']) && (int)$res['status'] === 200) {
        return array('ok' => true, 'data' => isset($res['data']['detail']) ? $res['data']['detail'] : array());
    }
    return array('ok' => false, 'msg' => isset($res['msg']) ? $res['msg'] : '获取商品详情失败');
}

/** 上游商品配置（cart/get_product_config） */
function zjmf_product_config($api, $pid) {
    return zjmf_curl($api, '/cart/get_product_config', array('pid' => (int)$pid), 20, 'GET');
}

/** 上游主机详情（host/header） */
function zjmf_host_header($api, $hostid) {
    $res = zjmf_curl($api, '/host/header', array('host_id' => (int)$hostid, 'source' => 'API'), 20, 'GET');
    if (isset($res['status']) && (int)$res['status'] === 200) {
        return array('ok' => true, 'data' => isset($res['data']['host_data']) ? $res['data']['host_data'] : $res['data']);
    }
    return array('ok' => false, 'msg' => isset($res['msg']) ? $res['msg'] : '获取主机信息失败');
}

/** 上游主机完整信息（含模块客户区 module_client_area / 面板链接） */
function zjmf_host_detail($api, $hostid) {
    $res = zjmf_curl($api, '/host/header', array('host_id' => (int)$hostid, 'source' => 'API'), 20, 'GET');
    if (isset($res['status']) && (int)$res['status'] === 200) {
        return array('ok' => true, 'data' => isset($res['data']) ? $res['data'] : array());
    }
    return array('ok' => false, 'msg' => isset($res['msg']) ? $res['msg'] : '获取主机信息失败');
}

/** 组装上游产品「登录面板」数据：从 module_client_area 提取面板链接，host_data 提供账号密码 */
function zjmf_panel_info($api, $hostid) {
    $d = zjmf_host_detail($api, $hostid);
    if (!$d['ok']) { return null; }
    $items = array();
    $mca = isset($d['data']['module_client_area']) ? $d['data']['module_client_area'] : array();
    foreach ((array)$mca as $it) {
        if (is_array($it) && !empty($it['url'])) {
            $items[] = array(
                'key' => isset($it['key']) ? $it['key'] : '',
                'title' => isset($it['title']) ? $it['title'] : (isset($it['name']) ? $it['name'] : '控制面板'),
                'url' => $it['url'],
            );
        }
    }
    $hd = isset($d['data']['host_data']) && is_array($d['data']['host_data']) ? $d['data']['host_data'] : array();
    return array(
        'url' => !empty($items) ? $items[0]['url'] : '',
        'items' => $items,
        'username' => isset($hd['username']) ? $hd['username'] : '',
        'password' => isset($hd['password']) ? $hd['password'] : '',
        'host' => isset($hd['dedicatedip']) ? $hd['dedicatedip'] : '',
    );
}

/* ================= 建单开通（上游下单并余额支付） ================= */

/** xnzj 周期 → 魔方财务周期 */
function zjmf_cycle($cycle) {
    $map = array('month' => 'monthly', 'quarter' => 'quarterly', 'halfyear' => 'semiannually', 'year' => 'annually');
    return isset($map[$cycle]) ? $map[$cycle] : 'monthly';
}

/**
 * 上游下单开通（对应魔方财务 Host::create 的 zjmf_api 分支）
 * @param array $api  上游接口配置
 * @param array $opts pid(上游商品ID) / billingcycle / host / password / currencyid / qty / downstream_url / downstream_token / downstream_id
 * @return array ok + upstream_hostid + invoiceid + msg
 */
function zjmf_create_order($api, $opts) {
    // 1. 用户信息（拿默认货币；真实结构 user 在顶层）
    $info = zjmf_curl($api, '/user_info', array(), 20, 'GET');
    if (!isset($info['status']) || (int)$info['status'] !== 200) {
        return array('ok' => false, 'msg' => isset($info['msg']) ? $info['msg'] : '获取上游用户信息失败');
    }
    $user = isset($info['user']) && is_array($info['user']) ? $info['user'] : (isset($info['data']['user']) ? $info['data']['user'] : array());
    $currencyid = 1;
    if (isset($user['currency'])) { $currencyid = (int)$user['currency']; }
    elseif (isset($info['data']['currency_id'])) { $currencyid = (int)$info['data']['currency_id']; }

    $base = array(
        'downstream_url' => isset($opts['downstream_url']) ? $opts['downstream_url'] : '',
        'downstream_token' => isset($opts['downstream_token']) ? $opts['downstream_token'] : '',
        'downstream_id' => isset($opts['downstream_id']) ? (int)$opts['downstream_id'] : 0,
    );

    // 2. 清空上游购物车
    $clear = zjmf_curl($api, '/cart/clear', $base, 20, 'POST');
    if (!isset($clear['status'])) { return array('ok' => false, 'msg' => '清空购物车失败：上游无响应'); }
    if ((int)$clear['status'] === 400 && !empty($clear['hostid'])) {
        // 幂等：上游已存在该下游订单（clearCart 对已开通订单返回 400+hostid+domainstatus）
        $hostid = (int)$clear['hostid'];
        if (isset($clear['domainstatus']) && $clear['domainstatus'] === 'Active') {
            return array('ok' => true, 'upstream_hostid' => $hostid, 'invoiceid' => 0, 'msg' => '上游订单已开通');
        }
        return array('ok' => false, 'upstream_hostid' => $hostid, 'invoiceid' => 0, 'msg' => isset($clear['msg']) ? $clear['msg'] : '上游订单处理中');
    }
    if ((int)$clear['status'] !== 200) {
        return array('ok' => false, 'msg' => isset($clear['msg']) ? '清空购物车失败：' . $clear['msg'] : '清空购物车失败');
    }

    // 幂等分支：上游已存在待支付订单（上次支付失败/超时重试），直接续付
    if (!empty($clear['hostid']) && !empty($clear['invoiceid'])) {
        $hostid = (int)$clear['hostid'];
        $invoiceid = (int)$clear['invoiceid'];
        $pay = array(
            'invoiceid' => $invoiceid,
            'use_credit' => 1,
            'enough' => 1,
            'downstream_url' => $base['downstream_url'],
            'downstream_token' => $base['downstream_token'],
            'downstream_id' => $base['downstream_id'],
        );
        $paid = zjmf_curl($api, '/apply_credit', $pay, 30, 'POST');
        if (isset($paid['status']) && (int)$paid['status'] === 1001) {
            if ($hostid === 0 && isset($paid['data']['hostid'][0])) { $hostid = (int)$paid['data']['hostid'][0]; }
            return array('ok' => true, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => '上游订单已支付');
        }
        $limit = array('invoiceid' => $invoiceid, 'use_credit_limit' => 1, 'enough' => 1);
        $paid2 = zjmf_curl($api, '/apply_credit_limit', $limit, 30, 'POST');
        if (isset($paid2['status']) && (int)$paid2['status'] === 1001) {
            return array('ok' => true, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => '上游订单已支付（信用额度）');
        }
        return array('ok' => false, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => isset($paid['msg']) && $paid['msg'] !== '' ? $paid['msg'] : '上游余额不足');
    }

    // 3. 加入购物车
    $cartData = array(
        'pid' => (int)$opts['pid'],
        'billingcycle' => $opts['billingcycle'],
        'host' => isset($opts['host']) ? $opts['host'] : '',
        'password' => isset($opts['password']) ? $opts['password'] : '',
        'currencyid' => $currencyid,
        'qty' => 1,
        'configoptions' => array(),
        'customfield' => array(),
    );
    $add = zjmf_curl($api, '/cart/add_to_shop', $cartData, 30, 'POST');
    if (!isset($add['status']) || (int)$add['status'] !== 200) {
        return array('ok' => false, 'msg' => isset($add['msg']) ? '上游加购失败：' . $add['msg'] : '上游加购失败');
    }

    // 4. 结算下单
    $settleData = $base;
    $settleData['cart_data'] = $cartData;
    $settle = zjmf_curl($api, '/cart/settle', $settleData, 60, 'POST');
    if (isset($settle['status']) && (int)$settle['status'] === 200) {
        $hostid = isset($settle['data']['hostid'][0]) ? (int)$settle['data']['hostid'][0] : 0;
        $invoiceid = isset($settle['data']['invoiceid']) ? $settle['data']['invoiceid'] : 0;

        // 5. 余额支付
        $pay = array(
            'invoiceid' => $invoiceid,
            'use_credit' => 1,
            'enough' => 0,
            'downstream_url' => $base['downstream_url'],
            'downstream_token' => $base['downstream_token'],
            'downstream_id' => $base['downstream_id'],
        );
        $paid = zjmf_curl($api, '/apply_credit', $pay, 30, 'POST');
        if (isset($paid['status']) && (int)$paid['status'] === 1001) {
            if ($hostid === 0 && isset($paid['data']['hostid'][0])) { $hostid = (int)$paid['data']['hostid'][0]; }
            return array('ok' => true, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => '上游订单已创建并支付');
        }
        if (isset($paid['status']) && (int)$paid['status'] === 200) {
            // 余额不足：尝试信用额度支付
            $limit = array(
                'invoiceid' => $invoiceid,
                'use_credit_limit' => 1,
                'enough' => 0,
                'downstream_url' => $base['downstream_url'],
                'downstream_token' => $base['downstream_token'],
                'downstream_id' => $base['downstream_id'],
            );
            $paid2 = zjmf_curl($api, '/apply_credit_limit', $limit, 30, 'POST');
            if (isset($paid2['status']) && (int)$paid2['status'] === 1001) {
                if ($hostid === 0 && isset($paid2['data']['hostid'][0])) { $hostid = (int)$paid2['data']['hostid'][0]; }
                return array('ok' => true, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => '上游订单已创建（信用额度支付）');
            }
            // 回滚（取消余额支付）
            zjmf_curl($api, '/apply_credit', array('invoiceid' => $invoiceid, 'use_credit' => 0), 20, 'POST');
            $msg = isset($paid['msg']) && $paid['msg'] !== '' ? $paid['msg'] : '上游余额不足';
            return array('ok' => false, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => $msg . '（请到上游充值）');
        }
        return array('ok' => false, 'upstream_hostid' => $hostid, 'invoiceid' => $invoiceid, 'msg' => isset($paid['msg']) ? $paid['msg'] : '上游支付失败');
    }
    return array('ok' => false, 'msg' => isset($settle['msg']) ? '上游结算失败：' . $settle['msg'] : '上游结算失败');
}

/* ================= 主机操作 ================= */

/** 暂停/恢复/开机/关机/重启等：POST /provision/default */
function zjmf_provision_default($api, $hostid, $func, $extra = array()) {
    $data = array('id' => (int)$hostid, 'func' => $func, 'is_api' => 1);
    $data = array_merge($data, $extra);
    return zjmf_curl($api, '/provision/default', $data, 60, 'POST');
}

/** 删除上游主机：POST /host/cancel */
function zjmf_host_cancel($api, $hostid) {
    return zjmf_curl($api, '/host/cancel', array('id' => (int)$hostid), 60, 'POST');
}

/** 续费：POST /host/renew → /apply_credit（1001=成功） */
function zjmf_host_renew($api, $hostid, $billingcycle) {
    $res = zjmf_curl($api, '/host/renew', array('hostid' => (int)$hostid, 'billingcycles' => $billingcycle), 60, 'POST');
    if (!isset($res['status']) || (int)$res['status'] !== 200) {
        return array('ok' => false, 'msg' => isset($res['msg']) ? $res['msg'] : '上游续费失败');
    }
    $invoiceid = isset($res['data']['invoiceid']) ? $res['data']['invoiceid'] : 0;
    $paid = zjmf_curl($api, '/apply_credit', array('invoiceid' => $invoiceid, 'use_credit' => 1), 30, 'POST');
    if (isset($paid['status']) && (int)$paid['status'] === 1001) {
        return array('ok' => true, 'invoiceid' => $invoiceid, 'msg' => '续费成功');
    }
    $msg = isset($paid['msg']) && $paid['msg'] !== '' ? $paid['msg'] : '上游余额不足';
    return array('ok' => false, 'invoiceid' => $invoiceid, 'msg' => $msg);
}

/* ================= 回传签名（上游推主机信息用） ================= */

function zjmf_rand_str($n = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $n; $i++) { $s .= $chars[random_int(0, $max)]; }
    return $s;
}

/** 生成签名：strtoupper(md5(json_encode(ksort({id,token,rand_str}, SORT_STRING)))) */
function zjmf_create_sign($params, $token) {
    $rand = zjmf_rand_str(6);
    $params['token'] = $token;
    $params['rand_str'] = $rand;
    ksort($params, SORT_STRING);
    $sign = strtoupper(md5(json_encode($params)));
    return array('signature' => $sign, 'rand_str' => $rand);
}

function zjmf_verify_sign($id, $token, $randStr, $signature) {
    if ($token === '' || $randStr === '' || $signature === '') { return false; }
    $data = array('id' => (int)$id, 'token' => $token, 'rand_str' => $randStr);
    ksort($data, SORT_STRING);
    return strtoupper(md5(json_encode($data))) === strtoupper($signature);
}

/* ================= 接收上游回传（下游端） ================= */

/**
 * 接收上游推送的主机信息：POST /api/host/sync
 * 入参：id(本地产品ID=downstream_id)、host_id(上游主机ID)、domain/username/password/dedicatedip/assignedips/port/os/nextduedate/domainstatus/suspendreason/type、signature/rand_str
 */
function zjmf_handle_host_sync($params) {
    $id = isset($params['id']) ? (int)$params['id'] : 0;
    $signature = isset($params['signature']) ? $params['signature'] : '';
    $randStr = isset($params['rand_str']) ? $params['rand_str'] : '';
    if ($id < 1 || $signature === '') {
        return array('status' => 400, 'msg' => '参数错误');
    }
    $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE id = ?');
    $st->execute(array($id));
    $prod = $st->fetch();
    if (!$prod) { return array('status' => 400, 'msg' => '产品不存在'); }
    if (!zjmf_verify_sign($id, $prod['upstream_token'], $randStr, $signature)) {
        return array('status' => 400, 'msg' => '签名验证失败');
    }

    $update = array();
    $statusMap = array('Active' => 1, 'Suspended' => 2, 'Terminated' => 3, 'Deleted' => 3, 'Cancelled' => 3, 'Fraud' => 0, 'Pending' => 0);
    if (isset($params['domain'])) { $update['domain'] = substr($params['domain'], 0, 190); }
    if (isset($params['username'])) { $update['username'] = substr($params['username'], 0, 100); }
    if (isset($params['password']) && $params['password'] !== '') { $update['password'] = enc($params['password']); }
    if (isset($params['host_id'])) { $update['upstream_hostid'] = (int)$params['host_id']; }
    if (isset($params['domainstatus']) && isset($statusMap[$params['domainstatus']])) {
        $update['status'] = $statusMap[$params['domainstatus']];
        $update['upstream_status'] = substr($params['domainstatus'], 0, 20);
    }
    if (isset($params['nextduedate']) && (int)$params['nextduedate'] > 0) {
        $update['expires_at'] = date('Y-m-d H:i:s', (int)$params['nextduedate']);
    }
    if (!empty($update)) {
        $st = db()->prepare('UPDATE ' . t('products') . ' SET ' . implode(',', array_map(function ($k) { return $k . ' = ?'; }, array_keys($update))) . ' WHERE id = ?');
        $args = array_values($update);
        $args[] = $id;
        $st->execute($args);
    }
    return array('status' => 200, 'msg' => '更新成功');
}

/** 接收上游工单回复回传（xnzj 工单独立管理，仅记录日志返回成功，避免上游重推） */
function zjmf_handle_ticket_sync($params) {
    $id = isset($params['id']) ? (int)$params['id'] : 0;
    if ($id < 1 || empty($params['signature'])) { return array('status' => 400, 'msg' => '参数错误'); }
    $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE id = ?');
    $st->execute(array($id));
    $prod = $st->fetch();
    if (!$prod) { return array('status' => 400, 'msg' => '产品不存在'); }
    if (!zjmf_verify_sign($id, $prod['upstream_token'], isset($params['rand_str']) ? $params['rand_str'] : '', $params['signature'])) {
        return array('status' => 400, 'msg' => '签名验证失败');
    }
    // 工单回复内容追加到产品备注所在订单（简化：不做跨系统同步，仅确认接收）
    return array('status' => 200, 'msg' => 'ok');
}

/* ================= 开通履约（支付成功后调用） ================= */

/**
 * 上游方案开通：创建本地产品记录 → 上游下单支付 → 更新上游主机ID
 * @return array ok/msg/product_id/upstream_hostid
 */
function zjmf_provision_order($order, $plan) {
    $orderId = (int)$order['id'];

    // 已有产品记录：按状态处理
    $st = db()->prepare('SELECT * FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
    $st->execute(array($orderId));
    $prod = $st->fetch();
    $now = date('Y-m-d H:i:s');

    if ($prod) {
        if ((int)$prod['status'] === 1) { return array('ok' => true, 'product_id' => (int)$prod['id'], 'msg' => '已开通'); }
        if ((int)$prod['status'] === 3) { return array('ok' => false, 'msg' => '产品已删除，无法重开'); }
        if ((int)$prod['upstream_hostid'] > 0) {
            // 上游已建单：可能只是支付未完成/未收到回传，查询上游状态
            $api = zjmf_api_row((int)$plan['zjmf_api_id']);
            if ($api && (int)$api['status'] === 1) {
                $h = zjmf_host_header($api, (int)$prod['upstream_hostid']);
                if ($h['ok'] && isset($h['data']['domainstatus'])) {
                    $map = array('Active' => 1, 'Suspended' => 2, 'Terminated' => 3, 'Deleted' => 3, 'Cancelled' => 3, 'Pending' => 0);
                    $ns = isset($map[$h['data']['domainstatus']]) ? $map[$h['data']['domainstatus']] : (int)$prod['status'];
                    $upd = array('upstream_status' => substr($h['data']['domainstatus'], 0, 20));
                    if ($ns === 1) { $upd['status'] = 1; $upd['activated_at'] = $now; }
                    else { $upd['status'] = $ns; }
                    if (!empty($h['data']['dedicatedip'])) { $upd['domain'] = $h['data']['dedicatedip']; }
                    $st = db()->prepare('UPDATE ' . t('products') . ' SET ' . implode(',', array_map(function ($k) { return $k . ' = ?'; }, array_keys($upd))) . ' WHERE id = ?');
                    $args = array_values($upd); $args[] = (int)$prod['id'];
                    $st->execute($args);
                    if ($ns === 1) { return array('ok' => true, 'product_id' => (int)$prod['id'], 'msg' => '上游主机已开通'); }
                }
            }
            return array('ok' => false, 'msg' => '上游订单已存在（ID ' . (int)$prod['upstream_hostid'] . '），等待开通或联系管理员处理', 'product_id' => (int)$prod['id']);
        }
        // 上次开通失败且未建单：重新走完整流程
        $hostname = $prod['domain'] !== '' ? $prod['domain'] : gen_hostname();
        $pwd = dec($prod['password']) !== '' ? dec($prod['password']) : random_pwd(10);
        $token = $prod['upstream_token'] !== '' ? $prod['upstream_token'] : md5(random_pwd(16) . time() . $orderId);
        if ($prod['upstream_token'] === '') {
            $st = db()->prepare('UPDATE ' . t('products') . ' SET upstream_token = ? WHERE id = ?');
            $st->execute(array($token, (int)$prod['id']));
        }
        $r = zjmf_do_create($plan, $order, $hostname, $pwd, $token, (int)$prod['id']);
        if (!$r['ok']) { return array('ok' => false, 'msg' => $r['msg'], 'product_id' => (int)$prod['id']); }
        $st = db()->prepare('UPDATE ' . t('products') . ' SET status = 1, upstream_hostid = ?, activated_at = ?, expires_at = ? WHERE id = ?');
        $st->execute(array($r['upstream_hostid'], $now, add_months($now, cycle_months($order['cycle'])), (int)$prod['id']));
        return array('ok' => true, 'product_id' => (int)$prod['id'], 'upstream_hostid' => $r['upstream_hostid'], 'msg' => '上游开通成功');
    }

    // 新订单：先建产品记录（status=0 开通中），再走上游建单
    $hostname = $order['domain'] !== '' ? $order['domain'] : gen_hostname();
    $pwd = random_pwd(10);
    $token = md5(random_pwd(16) . time() . $orderId);
    $st = db()->prepare('INSERT INTO ' . t('products')
        . ' (order_id, user_id, plan_id, server_id, plan_name, domain, username, password, status, upstream_token, created_at, activated_at, expires_at)'
        . ' VALUES (?,?,?,0,?,?,?,?,0,?,?,NULL,NULL)');
    $st->execute(array($orderId, (int)$order['user_id'], (int)$plan['id'], $order['plan_name'], $order['domain'], $hostname, enc($pwd), $token, $now));
    $prodId = (int)db()->lastInsertId();

    $r = zjmf_do_create($plan, $order, $hostname, $pwd, $token, $prodId);
    if (!$r['ok']) {
        return array('ok' => false, 'msg' => $r['msg'], 'product_id' => $prodId);
    }
    $st = db()->prepare('UPDATE ' . t('products') . ' SET status = 1, upstream_hostid = ?, activated_at = ?, expires_at = ? WHERE id = ?');
    $st->execute(array($r['upstream_hostid'], $now, add_months($now, cycle_months($order['cycle'])), $prodId));
    return array('ok' => true, 'product_id' => $prodId, 'upstream_hostid' => $r['upstream_hostid'], 'msg' => '上游开通成功');
}

/** 实际执行上游建单支付 */
function zjmf_do_create($plan, $order, $hostname, $pwd, $token, $downstreamId) {
    $api = zjmf_api_row((int)$plan['zjmf_api_id']);
    if (!$api || (int)$api['status'] !== 1) { return array('ok' => false, 'msg' => '上游接口未配置或已停用'); }
    $opts = array(
        'pid' => (int)$plan['upstream_pid'],
        'billingcycle' => zjmf_cycle($order['cycle']),
        'host' => $hostname,
        'password' => $pwd,
        'currencyid' => 1,
        'qty' => 1,
        'downstream_url' => root_url(),
        'downstream_token' => $token,
        'downstream_id' => $downstreamId,
    );
    $r = zjmf_create_order($api, $opts);
    return $r;
}
