<?php
/**
 * EP面板（Easypanel，Kangle 配套虚拟主机面板）API 客户端
 * 文档参考：easypanel api 文档 + WHMCS EasyPanel 模块（InteractivePlus/EasyPanelModule-For-Whmcs）
 * 接口：http://{host}:3312/api/?c=whm&a={动作}&r={随机数}&s={md5(动作+安全码+随机数)}
 *       业务参数走 POST body；返回 XML：<result status='200'>
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

/** 清洗面板地址：去掉协议与端口（用户可能填 ip:3312 或 http://ip:3312 等） */
function ep_host_clean($host) {
    $host = trim((string)$host);
    $host = preg_replace('/^https?:\/\//i', '', $host);
    $host = preg_replace('/:\d+$/', '', $host);
    return $host;
}

function ep_api_url($server) {
    return ($server['https'] ? 'https://' : 'http://') . ep_host_clean($server['host']) . ':3312/api/';
}

function ep_http_post($url, $data) {
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) { return false; }
    return $resp;
}

/** 发起 EP 调用，返回 array('status'=>..., 'info'=>...) */
function ep_call($server, $action, $params) {
    $r = random_int(1000, 99999);
    $s = md5($action . $server['secret'] . $r);
    $url = ep_api_url($server) . '?c=whm&a=' . rawurlencode($action) . '&r=' . $r . '&s=' . $s;
    $resp = ep_http_post($url, $params);
    if ($resp === false) {
        return array('status' => 0, 'info' => '请求EP面板失败');
    }
    // 解析 <result status='200'>；优先 SimpleXML，缺失（精简 PHP）时用正则兜底
    $st = '';
    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($resp);
        if ($xml !== false && isset($xml->result) && isset($xml->result['status'])) {
            $st = (string)$xml->result['status'];
        }
    }
    if ($st === '' && preg_match('/<result[^>]*status=["\']?([0-9]+)/i', $resp, $m)) {
        $st = $m[1];
    }
    if ($st === '') {
        return array('status' => 0, 'info' => 'EP面板返回异常: ' . utf8_cut($resp, 120));
    }
    if ($st === '200') {
        return array('status' => '200', 'info' => '成功');
    }
    if ($st === '403') {
        return array('status' => '403', 'info' => '签名错误，请检查通信安全码');
    }
    return array('status' => $st, 'info' => $st === '500' ? '面板处理失败(500)' : '面板返回状态 ' . $st);
}

function ep_ok($r) {
    return is_array($r) && isset($r['status']) && (string)$r['status'] === '200';
}

/**
 * 创建空间（add_vh）
 * $cfg: a1..a10 + hostdomain，字段含义与宝塔方案一致
 */
function ep_create($server, $hostname, $pwd, $cfg) {
    $params = array(
        'name' => $hostname,
        'passwd' => $pwd,
        'init' => 1,
        'module' => (isset($server['ep_module']) && $server['ep_module'] !== '') ? $server['ep_module'] : 'php',
        'web_quota' => isset($cfg['a1']) ? $cfg['a1'] : 0,
        'db_quota' => isset($cfg['a2']) ? $cfg['a2'] : 0,
        'db_type' => 'mysql',
        'ftp' => 1,
        'domain' => isset($cfg['hostdomain']) ? $cfg['hostdomain'] : '',
        'vhost_domains' => isset($cfg['hostdomain']) ? $cfg['hostdomain'] : '',
        'max_subdir' => isset($cfg['a4']) ? $cfg['a4'] : 0,
        'subdir_flag' => isset($cfg['a10']) ? $cfg['a10'] : 1,
        'flow_limit' => isset($cfg['a5']) ? $cfg['a5'] : 0,
        'cdn' => isset($cfg['a6']) ? $cfg['a6'] : 0,
        'port' => isset($cfg['a7']) ? $cfg['a7'] : '80,443s',
        'htaccess' => 1,
        'access' => 1,
    );
    return ep_call($server, 'add_vh', $params);
}

/** 暂停 / 恢复（status: 1暂停 0恢复） */
function ep_set_status($server, $hostname, $status) {
    return ep_call($server, 'update_vh', array('name' => $hostname, 'status' => $status));
}

/** 删除空间 */
function ep_del($server, $hostname) {
    return ep_call($server, 'del_vh', array('name' => $hostname));
}

/** 修改密码 */
function ep_chg_pwd($server, $hostname, $pwd) {
    return ep_call($server, 'change_password', array('name' => $hostname, 'passwd' => $pwd));
}

/** 测试连接：签名探测（随机名防误删；200/500 均代表签名与网络正常） */
function ep_test($server) {
    $r = ep_call($server, 'del_vh', array('name' => '__xnzj_test_' . random_int(10000, 99999) . '__'));
    if ((string)$r['status'] === '500') {
        // 500 = 签名正确但空间不存在，同样证明连接与安全码正常
        return array('status' => '200', 'info' => '连接成功');
    }
    return $r;
}

/** 客户面板地址（带用户名直达登录页，参考 WHMCS 模块 LoginLink） */
function ep_panel_url($server, $username = '') {
    $url = ($server['https'] ? 'https://' : 'http://') . ep_host_clean($server['host']) . ':3312/';
    if ($username !== '') {
        $url .= 'vhost/?username=' . rawurlencode($username);
    }
    return $url;
}
