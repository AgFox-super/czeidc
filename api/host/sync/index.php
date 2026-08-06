<?php
/**
 * 魔方财务上游主机信息回传接收端
 * 上游在开通/变更主机后推送信息到：{本站}/api/host/sync
 * （无伪静态环境用 api/host/sync/index.php 承接，上游 curl 会自动跟随目录跳转）
 * 鉴权：签名验证（token 为下单时生成的 upstream_token）
 */
define('XNZJ_BOOT', true);
require dirname(dirname(dirname(__DIR__))) . '/includes/boot.php';

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(array('status' => 400, 'msg' => '系统错误'), JSON_UNESCAPED_UNICODE);
    }
});

$r = zjmf_handle_host_sync($_POST);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($r, JSON_UNESCAPED_UNICODE);
