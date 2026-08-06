<?php
/**
 * xnzj 核心引导文件（PHP 7.2+ 兼容）
 * 所有入口文件必须先 define('XNZJ_BOOT', true) 再 require 本文件
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

define('XNZJ_ROOT', dirname(__DIR__));

/* ---------- 安装检测 ---------- */
$__configFile = XNZJ_ROOT . '/config.php';
if (!is_file($__configFile)) {
    $__to = 'install.php';
    if (defined('XNZJ_ADMIN_DIR')) { $__to = '../install.php'; }
    header('Location: ' . $__to);
    exit;
}
$GLOBALS['__cfg'] = require $__configFile;

/* ---------- 后台目录校验（后台文件只能在其对应目录运行） ---------- */
if (defined('XNZJ_ADMIN_DIR')) {
    if (basename(XNZJ_ADMIN_DIR) !== $GLOBALS['__cfg']['admin_path']) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/* ---------- URL 路径前缀（支持部署在子目录） ---------- */
$__sn = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/');
$__dir = rtrim(dirname($__sn), '/');
if (defined('XNZJ_ADMIN_DIR')) { $__dir = rtrim(dirname($__dir), '/'); }
// 根目录部署时为 ''，子目录部署时为 /xnzj 等（避免拼出双斜杠）
$GLOBALS['__root_path'] = ($__dir === '' || $__dir === '.') ? '' : $__dir;

/* ---------- 会话（用户与管理员使用不同会话，互不干扰） ---------- */
if (defined('XNZJ_ADMIN_DIR')) {
    session_name('XNZJADMSESS');
    session_set_cookie_params(0, $GLOBALS['__root_path'] . '/' . $GLOBALS['__cfg']['admin_path'] . '/');
} else {
    session_name('XNZJSESS');
    session_set_cookie_params(0, $GLOBALS['__root_path'] . '/');
}
session_start();

define('APP_KEY', $GLOBALS['__cfg']['app_key']);
define('ADMIN_PATH', $GLOBALS['__cfg']['admin_path']);

/* ---------- 组件加载（缺文件时按入口类型给出可读提示，避免空响应/白屏） ---------- */
foreach (array('functions', 'smtp', 'yipay', 'bt_native', 'ep', 'zjmf') as $__inc) {
    $__f = __DIR__ . '/' . $__inc . '.php';
    if (!is_file($__f)) {
        $__msg = '系统组件缺失：includes/' . $__inc . '.php 未上传，请补全文件后再试';
        if (defined('XNZJ_API')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'msg' => $__msg), JSON_UNESCAPED_UNICODE);
        } elseif (defined('XNZJ_NOTIFY')) {
            echo 'fail';
        } elseif (defined('XNZJ_RETURN')) {
            header('Location: index.php#/?payfail=1');
        } else {
            http_response_code(500);
            echo $__msg;
        }
        exit;
    }
    require $__f;
}
