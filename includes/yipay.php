<?php
/**
 * 易支付（兮辰易 V2）接口封装
 * 文档：https://pay.xicheny.com/doc/pay_submit.html
 * 签名：RSA / SHA256WithRSA，剔除 sign、sign_type，非空参数按键名 ASCII 升序拼接
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

/** 构造待签名字符串 */
function yipay_sign_str($params) {
    ksort($params);
    $parts = array();
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') { continue; }
        if ($v === '' || $v === null) { continue; }
        $parts[] = $k . '=' . $v;
    }
    return implode('&', $parts);
}

/** RSA-SHA256 签名（商户私钥） */
function yipay_sign($params, $privKeyPem) {
    $str = yipay_sign_str($params);
    $pkey = openssl_pkey_get_private(normalize_privkey($privKeyPem));
    if (!$pkey) { return ''; }
    $sig = '';
    openssl_sign($str, $sig, $pkey, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
}

/**
 * 私钥归一化：平台给的是裸 base64，需包装成 PEM（与官方 SDK 一致）
 * 优先 PKCS#8(BEGIN PRIVATE KEY)，失败回退 PKCS#1(BEGIN RSA PRIVATE KEY)
 */
function normalize_privkey($key) {
    $key = trim((string)$key);
    if ($key === '') { return ''; }
    if (strpos($key, 'BEGIN') !== false) { return $key; } // 已是 PEM
    $b64 = preg_replace('/\s+/', '', $key);
    if ($b64 === '') { return $key; }
    $pkcs8 = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PRIVATE KEY-----";
    if (openssl_pkey_get_private($pkcs8) !== false) { return $pkcs8; }
    $pkcs1 = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END RSA PRIVATE KEY-----";
    if (openssl_pkey_get_private($pkcs1) !== false) { return $pkcs1; }
    return $key; // 原样返回，由调用方报错
}

/** PKCS#1(RSAPublicKey) PEM → SPKI(SubjectPublicKeyInfo) PEM，兼容老版本 PHP */
function normalize_pubkey($pem) {
    $pem = trim((string)$pem);
    if ($pem === '') { return ''; }
    if (strpos($pem, 'BEGIN PUBLIC KEY') !== false) { return $pem; }
    if (strpos($pem, 'BEGIN RSA PUBLIC KEY') !== false) {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        if ($der === false || $der === '') { return $pem; }
        $spki = pkcs1_to_spki($der);
        if ($spki !== '') {
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----";
        }
        return $pem;
    }
    // 裸 base64：先按 SPKI 包装，失败再按 PKCS#1 转换（与官方 SDK 一致）
    $b64 = preg_replace('/\s+/', '', $pem);
    if ($b64 === '') { return $pem; }
    $spki = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PUBLIC KEY-----";
    if (openssl_pkey_get_public($spki) !== false) { return $spki; }
    $der = base64_decode($b64);
    if ($der !== false && $der !== '') {
        $converted = pkcs1_to_spki($der);
        if ($converted !== '') {
            $spki2 = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($converted), 64, "\n") . "-----END PUBLIC KEY-----";
            if (openssl_pkey_get_public($spki2) !== false) { return $spki2; }
        }
    }
    return $pem; // 原样返回，由调用方报错
}

/** PKCS#1 RSAPublicKey DER → SPKI DER */
function pkcs1_to_spki($der) {
    // RSAPublicKey ::= SEQUENCE { INTEGER modulus, INTEGER publicExponent }
    $pos = 0;
    // 先跳过外层 SEQUENCE 头（不能把它的长度计入 pos，否则会跳过内容区）
    if (!isset($der[$pos]) || ord($der[$pos]) !== 0x30) { return ''; }
    $pos++;
    if (!isset($der[$pos])) { return ''; }
    $len = ord($der[$pos]);
    $pos++;
    if ($len & 0x80) {
        $n = $len & 0x7F;
        $pos += $n; // 长格式：跳过长度字节
    }
    $ints = array();
    while ($pos < strlen($der) && count($ints) < 2) {
        if (!isset($der[$pos])) { return ''; }
        $tag = ord($der[$pos]);
        $pos++;
        if (!isset($der[$pos])) { return ''; }
        $len = ord($der[$pos]);
        $pos++;
        if ($len & 0x80) {
            $n = $len & 0x7F;
            $len = 0;
            for ($i = 0; $i < $n; $i++) { $len = ($len << 8) | ord($der[$pos + $i]); }
            $pos += $n;
        }
        if ($tag === 0x02) {
            $ints[] = substr($der, $pos, $len);
        }
        $pos += $len;
    }
    if (count($ints) !== 2) { return ''; }
    $seq = "\x02" . der_len(strlen($ints[0])) . $ints[0] . "\x02" . der_len(strlen($ints[1])) . $ints[1];
    // 重新包一层 SEQUENCE：BIT STRING 里必须是完整的 RSAPublicKey DER
    $rsaPub = "\x30" . der_len(strlen($seq)) . $seq;
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption + NULL
    $bit = "\x03" . der_len(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
    return "\x30" . der_len(strlen($alg) + strlen($bit)) . $alg . $bit;
}

function der_len($len) {
    if ($len < 0x80) { return chr($len); }
    $b = '';
    while ($len > 0) { $b = chr($len & 0xFF) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}

/** 验签（平台公钥）；$checkTime=false 时不校验时间戳（用于同步跳转等场景） */
function yipay_verify($params, $pubKeyPem, $checkTime = true) {
    if (empty($params['sign']) || !is_string($params['sign'])) { return false; }
    // 与官方 SDK 一致：回调/响应需带 timestamp 且与当前时间差不超过 300 秒（防重放）
    if ($checkTime && (empty($params['timestamp']) || abs(time() - (int)$params['timestamp']) > 300)) { return false; }
    $str = yipay_sign_str($params);
    $pkey = openssl_pkey_get_public(normalize_pubkey($pubKeyPem));
    if (!$pkey) { return false; }
    $sig = base64_decode($params['sign']);
    if ($sig === false) { return false; }
    return openssl_verify($str, $sig, $pkey, OPENSSL_ALGO_SHA256) === 1;
}

/** 支付是否已配置 */
function yipay_configured() {
    return setting('pay_api') !== '' && setting('pay_pid') !== '' && setting('pay_private_key') !== '' && setting('pay_public_key') !== '';
}

/** 组装下单参数（含签名）——与官方 SDK 参数集一致 */
function yipay_build_params($order, $type) {
    $params = array(
        'pid' => setting('pay_pid'),
        'type' => $type,
        'out_trade_no' => $order['order_no'],
        'notify_url' => root_url() . '/notify.php',
        'return_url' => root_url() . '/return.php',
        'name' => utf8_cut($order['plan_name'] . ' ' . $order['cycle_name'], 120),
        'money' => sprintf('%.2f', $order['amount']),
        'timestamp' => (string)time(),
    );
    $sign = yipay_sign($params, setting('pay_private_key'));
    if ($sign === '') { return null; }
    $params['sign'] = $sign;
    $params['sign_type'] = 'RSA';
    return $params;
}

/** 生成自动提交的支付表单 HTML */
function yipay_pay_form($order, $type) {
    if (!yipay_configured()) { return null; }
    $params = yipay_build_params($order, $type);
    if ($params === null) { return null; }
    $api = rtrim(setting('pay_api'), '/') . '/api/pay/submit';
    $html = '<form id="yipayForm" action="' . h($api) . '" method="post" accept-charset="UTF-8">';
    foreach ($params as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    $html .= '</form><script>document.getElementById("yipayForm").submit();</script>';
    return $html;
}

/** 主动查单（同步订单状态） */
function yipay_query($outTradeNo) {
    if (!yipay_configured()) { return null; }
    $params = array(
        'pid' => setting('pay_pid'),
        'out_trade_no' => $outTradeNo,
        'timestamp' => (string)time(),
    );
    $params['sign'] = yipay_sign($params, setting('pay_private_key'));
    $params['sign_type'] = 'RSA';
    $url = rtrim(setting('pay_api'), '/') . '/api/pay/query';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) { return array('ok' => false, 'msg' => '查询失败: ' . $err); }
    $j = json_decode($resp, true);
    if (!is_array($j)) { return array('ok' => false, 'msg' => '查询返回异常'); }
    // 响应含签名，用平台公钥验签（验签失败不阻断，以 trade_status 为准由异步通知兜底）
    $verified = isset($j['sign']) ? yipay_verify($j, setting('pay_public_key')) : false;
    return array('ok' => true, 'verified' => $verified, 'status' => isset($j['status']) ? (int)$j['status'] : 0, 'data' => $j);
}
