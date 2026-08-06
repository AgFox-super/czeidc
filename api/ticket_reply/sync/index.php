<?php
/**
 * 魔方财务上游工单回复回传接收端：{本站}/api/ticket_reply/sync
 * xnzj 工单独立管理，此处仅做签名校验后确认接收，避免上游重推
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

$r = zjmf_handle_ticket_sync($_POST);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($r, JSON_UNESCAPED_UNICODE);
