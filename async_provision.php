<?php
/**
 * 异步开通任务（CLI 入口）
 * 由 queue_provision() 在无 fastcgi_finish_request 的环境（CLI/php -S）下通过 popen 子进程调用；
 * php-fpm 环境走 fastcgi_finish_request，不会执行本文件。
 * 用法：php async_provision.php {订单ID} {站点URL}
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

// 仅允许 CLI 执行：防止通过浏览器直接访问本文件触发开通
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$orderId = isset($argv[1]) ? (int)$argv[1] : 0;
$siteUrl = isset($argv[2]) ? $argv[2] : '';
if ($orderId < 1) { exit(1); }

// CLI 下构造最小 HTTP 环境，让 root_url()/会话等正常工作
if ($siteUrl !== '') {
    $p = parse_url($siteUrl);
    $_SERVER['HTTP_HOST'] = (isset($p['host']) ? $p['host'] : 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');
    $_SERVER['HTTPS'] = (isset($p['scheme']) && $p['scheme'] === 'https') ? 'on' : 'off';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = isset($p['scheme']) ? $p['scheme'] : 'http';
}
$_SERVER['SCRIPT_NAME'] = '/async_provision.php';
$_SERVER['REQUEST_URI'] = '/async_provision.php';

define('XNZJ_BOOT', true);
require __DIR__ . '/includes/boot.php';

// 并发锁：同一订单同时只跑一个开通任务（父进程已释放锁，这里重新获取）
$lockFile = sys_get_temp_dir() . '/xnzj_prov_' . $orderId . '.lock';
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { exit(0); }

$r = provision_order($orderId);
@file_put_contents(__DIR__ . '/async_provision.log', date('Y-m-d H:i:s') . ' order=' . $orderId . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

flock($fp, LOCK_UN);
fclose($fp);
exit(0);
