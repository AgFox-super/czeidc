<?php
/**
 * 易支付 异步通知（notify_url）
 * 验签 → 校验金额/状态 → 标记已支付 → 自动开通虚拟主机（幂等）
 */
define('XNZJ_BOOT', true);
define('XNZJ_NOTIFY', true);
require __DIR__ . '/includes/boot.php';

// 致命错误时返回 fail（平台会重试），避免空响应
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        echo 'fail';
    }
});

function notify_exit($s) { echo $s; exit; }

$params = $_REQUEST;

// 平台公钥验签
if (!yipay_configured()) { notify_exit('fail'); }
if (!yipay_verify($params, setting('pay_public_key'))) { notify_exit('fail'); }
if (!isset($params['trade_status']) || $params['trade_status'] !== 'TRADE_SUCCESS') { notify_exit('fail'); }
if (empty($params['out_trade_no'])) { notify_exit('fail'); }

$st = db()->prepare('SELECT * FROM ' . t('orders') . ' WHERE order_no = ?');
$st->execute(array($params['out_trade_no']));
$order = $st->fetch();
if (!$order) { notify_exit('fail'); }

// 金额核对（防止篡改）
if (isset($params['money']) && sprintf('%.2f', $order['amount']) !== sprintf('%.2f', $params['money'])) { notify_exit('fail'); }

// 标记已支付（幂等）→ 立即返回 success → 异步开通（php-fpm 先发响应再执行开通，回调提速）
$tradeNo = isset($params['trade_no']) && is_string($params['trade_no']) ? $params['trade_no'] : '';
mark_order_paid((int)$order['id'], $tradeNo);
echo 'success';
queue_provision((int)$order['id']);
exit;
