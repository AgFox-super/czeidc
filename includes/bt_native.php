<?php
/**
 * 宝塔面板原生 API 客户端（无需第三方插件）
 * 启用：面板设置 → 安全 → API接口 → 开启并生成密钥
 * 签名：request_token = md5(md5(面板用户名) + md5(API密钥) + request_time)
 * 请求头：X-Requested-With: XMLHttpRequest（必须）
 * 说明：原生 API 创建的是「网站」（域名+PHP+FTP+可选数据库），无配额概念，
 *      空间/流量等限制字段仅作展示，不在面板侧强制。
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

/** 清洗面板地址：去掉协议头（host 可能被填成 http://ip:8888 等） */
function bt_host_clean($host) {
    $host = trim((string)$host);
    return preg_replace('/^https?:\/\//i', '', $host);
}

function bt_native_base($server) {
    $host = bt_host_clean($server['host']);
    $scheme = ($server['https'] ? 'https://' : 'http://');
    if (preg_match('/:\d+$/', $host)) { return $scheme . $host; } // host 自带端口
    $port = isset($server['port']) ? (int)$server['port'] : 8888;
    return $scheme . $host . ($port > 0 ? ':' . $port : '');
}

/** 发起原生 API 请求（自动签名），返回解析后的 JSON 数组 */
function bt_native_request($server, $path, $data = array()) {
    $time = time();
    // 新版宝塔（8.x）：request_token = md5(时间戳 + API密钥)；旧版：md5(md5(用户名)+md5(密钥)+时间戳)
    $data['request_time'] = $time;
    $data['request_token'] = md5($time . $server['secret']);
    $r = bt_native_http($server, $path, $data);
    // 旧版面板兼容：密钥校验失败时用旧算法重试一次
    if (is_array($r) && isset($r['msg']) && strpos($r['msg'], '密钥校验失败') !== false) {
        $data['request_token'] = md5(md5($server['username']) . md5($server['secret']) . $time);
        $r = bt_native_http($server, $path, $data);
    }
    return $r;
}

/** 原生 API HTTP 请求（带签名参数） */
function bt_native_http($server, $path, $data) {
    if (!function_exists('curl_init')) {
        return array('status' => false, 'msg' => '服务器PHP缺少 curl 扩展，请安装后重试');
    }
    $ch = curl_init(bt_native_base($server) . $path);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest'));
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) { return array('status' => false, 'msg' => '请求失败: ' . $err); }
    $j = json_decode($resp, true);
    if (!is_array($j)) {
        $hint = (stripos($resp, '<html') !== false || stripos($resp, '404') !== false || stripos($resp, 'not found') !== false)
            ? '（请确认已开启面板API接口，且地址/端口/HTTPS正确）' : '';
        return array('status' => false, 'msg' => '接口返回异常: ' . utf8_cut($resp, 100) . $hint);
    }
    // 站点列表等接口直接返回数组，包一层统一结构
    if (count($j) === 0 || isset($j[0])) {
        return array('status' => true, 'list' => $j);
    }
    return $j;
}

function bt_native_ok($r) {
    // 部分接口（AddSite）成功返回 siteStatus 而非 status
    return is_array($r) && ((isset($r['status']) && $r['status'] === true) || (isset($r['siteStatus']) && $r['siteStatus'] === true));
}

/** 测试连接（获取网络信息；部分新版面板不支持 GetConfig，GetNetWork 通用可用） */
function bt_native_test($server) {
    $r = bt_native_request($server, '/system?action=GetNetWork');
    // GetNetWork 成功时返回 {"network": {...}} 而非 {"status": true}，这里统一格式
    if (is_array($r) && isset($r['network'])) {
        return array('status' => true, 'msg' => '连接成功');
    }
    return $r;
}

/** 查询面板已安装的 PHP 版本列表 */
function bt_native_php_versions($server) {
    $r = bt_native_request($server, '/site?action=GetPHPVersion');
    $list = array();
    if (is_array($r) && isset($r['list']) && is_array($r['list'])) {
        foreach ($r['list'] as $v) {
            if (isset($v['version']) && $v['version'] !== '') { $list[] = (string)$v['version']; }
        }
    }
    return $list;
}

/** 选择 PHP 版本：优先配置的版本，其次面板已装最高版本（排除 00=纯静态） */
function bt_native_pick_php($server) {
    $configured = (isset($server['ep_module']) && $server['ep_module'] !== '') ? preg_replace('/\D/', '', $server['ep_module']) : '';
    $versions = bt_native_php_versions($server);
    if ($configured !== '' && in_array($configured, $versions, true)) { return $configured; }
    $best = '';
    foreach ($versions as $v) {
        if ($v === '00' || $v === 'other') { continue; }
        if ($best === '' || (int)$v > (int)$best) { $best = $v; }
    }
    return $best !== '' ? $best : ($configured !== '' ? $configured : '72');
}

/** 创建网站（域名+PHP+FTP+可选MySQL） */
function bt_native_create($server, $hostname, $pwd, $cfg) {
    $domain = (isset($cfg['hostdomain']) && $cfg['hostdomain'] !== '') ? $cfg['hostdomain'] : $hostname;
    $webname = json_encode(array(
        'domain' => $domain,
        'domainlist' => array(),
        'Index' => 'index.html',
        'other' => array(),
    ), JSON_UNESCAPED_UNICODE);
    $ports = (isset($cfg['a7']) && $cfg['a7'] !== '') ? $cfg['a7'] : '80';
    preg_match('/\d+/', $ports, $m);
    $port = isset($m[0]) ? (int)$m[0] : 80;
    $params = array(
        'webname' => $webname,
        'type' => 'PHP',
        'version' => bt_native_pick_php($server),
        'port' => $port,
        'path' => '/www/wwwroot/' . $domain,
        'ps' => isset($cfg['plan_name']) ? $cfg['plan_name'] : '',
        'codeing' => 'utf8',
        'ftp' => 'true',
        'ftp_username' => $hostname,
        'ftp_password' => $pwd,
    );
    // 方案含数据库时创建 MySQL 库（注意：面板要求字符串 'true'，且需 datauser/datapassword）
    if ((int)$cfg['a2'] > 0) {
        $params['sql'] = 'true';
        $params['datauser'] = $domain;
        $params['datapassword'] = $pwd;
    } else {
        $params['sql'] = 'false';
    }
    $r = bt_native_request($server, '/site?action=AddSite', $params);
    // AddSite 成功返回 siteStatus=true（siteId 等）；FTP 未装时 ftpStatus 可能为 false，不影响建站成功
    if (is_array($r) && isset($r['siteStatus']) && $r['siteStatus'] === true) {
        return array('status' => true, 'msg' => '创建成功', 'site_id' => isset($r['siteId']) ? $r['siteId'] : 0);
    }
    return $r;
}

/** 按网站名查找站点记录（返回完整记录或 null） */
function bt_native_site($server, $hostname) {
    $r = bt_native_request($server, '/data?action=getData&table=sites', array('page' => 1, 'limit' => 200));
    // 新版面板 getData 返回 {"data": [...]} 结构
    if (!is_array($r) || !isset($r['data']) || !is_array($r['data'])) { return null; }
    foreach ($r['data'] as $site) {
        if (isset($site['name']) && $site['name'] === $hostname) { return $site; }
    }
    return null;
}

/** 暂停 / 恢复（status: 1暂停 0恢复） */
function bt_native_set_status($server, $hostname, $status) {
    $site = bt_native_site($server, $hostname);
    if (!$site) { return array('status' => false, 'msg' => '未找到网站 ' . $hostname); }
    $action = $status == 1 ? 'SiteStop' : 'SiteStart';
    return bt_native_request($server, '/site?action=' . $action, array(
        'id' => $site['id'],
        'webname' => json_encode($site, JSON_UNESCAPED_UNICODE),
    ));
}

/** 删除网站（连同数据库） */
function bt_native_del($server, $hostname) {
    $site = bt_native_site($server, $hostname);
    if (!$site) { return array('status' => false, 'msg' => '未找到网站 ' . $hostname); }
    return bt_native_request($server, '/site?action=DeleteSite', array(
        'id' => $site['id'],
        'webname' => json_encode($site, JSON_UNESCAPED_UNICODE),
        'database' => 1,
    ));
}

/** 修改 FTP 密码 */
function bt_native_chg_pwd($server, $hostname, $pwd) {
    return bt_native_request($server, '/ftp?action=SetPassword', array(
        'username' => $hostname,
        'password' => $pwd,
    ));
}

/** 面板地址 */
function bt_native_panel_url($server) {
    return bt_native_base($server) . '/';
}
