<?php
/**
 * 易支付 同步跳转（return_url）
 * 注意：异步通知（notify.php）才是支付确认的权威来源，这里只做用户体验兜底：
 *   1. 订单已支付 → 直接跳产品详情（幂等，避免重复开通/长时间等待）
 *   2. 待支付 → 宽松验签（只验 RSA 签名、不校验时间戳，兼容扫码支付超 5 分钟）并履约
 *   3. 验签失败 → 主动向平台查单兜底，已付则补履约
 *   全程 try/catch，异常一律跳回前台而不是白屏
 */
define('XNZJ_BOOT', true);
define('XNZJ_RETURN', true);
require __DIR__ . '/includes/boot.php';

// 致命错误时跳回前台，避免白屏
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        if (!headers_sent()) { header('Location: index.php#/?payfail=1'); }
    }
});

function redirect_to($hash) {
    header('Location: index.php#' . $hash);
    exit;
}

function find_product_by_order($orderId) {
    $st = db()->prepare('SELECT id FROM ' . t('products') . ' WHERE order_id = ? LIMIT 1');
    $st->execute(array((int)$orderId));
    $p = $st->fetch();
    return $p ? (int)$p['id'] : 0;
}

/** 跳转产品页/产品列表，开通异步执行（先发跳转响应再开通，避免同步等待） */
function redirect_with_provision($orderId) {
    $productId = find_product_by_order($orderId);
    if ($productId > 0) {
        header('Location: index.php#/product/' . $productId . '?paid=1');
    } else {
        header('Location: index.php#/products');
    }
    queue_provision((int)$orderId);
    exit;
}

try {
    $params = $_REQUEST;

    // 1. 按商户订单号找订单
    $order = null;
    if (!empty($params['out_trade_no']) && is_string($params['out_trade_no'])) {
        $st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE order_no = ?');
        $st->execute(array($params['out_trade_no']));
        $order = $st->fetch();
    }
    if (!$order) { redirect_to('/?payfail=1'); }

    // 2. 已支付（异步通知通常已处理）：直接跳产品页（产品尚未建则异步开通）
    if ($order['status'] === 'paid') {
        $productId = find_product_by_order((int)$order['id']);
        if ($productId > 0) { redirect_to('/product/' . $productId . '?paid=1'); }
        // 已支付却无产品记录：异步开通后跳产品列表
        header('Location: index.php#/products');
        queue_provision((int)$order['id']);
        exit;
    }

    // 3. 待支付：宽松验签（不校验时间戳，兼容支付耗时超过 5 分钟的场景）
    $verified = yipay_configured()
        && yipay_verify($params, setting('pay_public_key'), false)
        && isset($params['trade_status']) && $params['trade_status'] === 'TRADE_SUCCESS';
    if ($verified) {
        $tradeNo = isset($params['trade_no']) && is_string($params['trade_no']) ? $params['trade_no'] : '';
        mark_order_paid((int)$order['id'], $tradeNo);
        redirect_with_provision((int)$order['id']);
    }

    // 4. 验签失败：主动查单兜底（时间戳过期/参数差异等）
    $q = yipay_query($order['order_no']);
    if ($q !== null && $q['ok'] && (int)$q['status'] === 1) {
        $tradeNo = (isset($q['data']['trade_no']) && is_string($q['data']['trade_no'])) ? $q['data']['trade_no'] : '';
        mark_order_paid((int)$order['id'], $tradeNo);
        redirect_with_provision((int)$order['id']);
    }

    redirect_to('/?payfail=1');
} catch (Exception $e) {
    // 任何异常都跳回前台，不抛白屏
    redirect_to('/?payfail=1');
}
