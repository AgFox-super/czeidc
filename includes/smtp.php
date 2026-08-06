<?php
/**
 * 极简 SMTP 客户端（无第三方依赖，PHP 7.2+）
 */
if (!defined('XNZJ_BOOT')) { http_response_code(403); exit('Forbidden'); }

class SmtpMailer
{
    public $err = '';
    private $host, $port, $user, $pass, $from, $fromName, $secure, $timeout = 15;
    private $conn = null;

    public function __construct($cfg) {
        $this->host = isset($cfg['host']) ? $cfg['host'] : '';
        $this->port = isset($cfg['port']) ? (int)$cfg['port'] : 465;
        $this->user = isset($cfg['user']) ? $cfg['user'] : '';
        $this->pass = isset($cfg['pass']) ? $cfg['pass'] : '';
        $this->from = isset($cfg['from']) ? $cfg['from'] : '';
        $this->fromName = isset($cfg['from_name']) ? $cfg['from_name'] : '';
        $this->secure = isset($cfg['secure']) ? $cfg['secure'] : 'ssl';
    }

    public function send($to, $subject, $htmlBody) {
        if ($this->host === '' || $this->from === '') { $this->err = 'SMTP 配置不完整'; return false; }
        $prefix = ($this->secure === 'ssl') ? 'ssl://' : '';
        $ctx = stream_context_create(array('ssl' => array(
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        )));
        $this->conn = @stream_socket_client($prefix . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->conn) { $this->err = '无法连接SMTP服务器(' . $errstr . ')'; return false; }
        // 保证读超时，避免服务器无响应时永久挂起
        stream_set_timeout($this->conn, $this->timeout);
        if (!$this->expect(220)) { return false; }

        if (!$this->cmd('EHLO ' . gethostname())) { return false; }

        if ($this->secure === 'tls') {
            if (!$this->cmd('STARTTLS')) { return false; }
            $ok = @stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) { $this->err = 'STARTTLS 加密失败'; return false; }
            if (!$this->cmd('EHLO ' . gethostname())) { return false; }
        }

        if ($this->user !== '') {
            // AUTH LOGIN 是挑战-响应流程，不能用 cmd()（它会提前消费 334 响应）
            fwrite($this->conn, "AUTH LOGIN\r\n");
            $r = $this->expect();
            if ($r === 334) {
                fwrite($this->conn, base64_encode($this->user) . "\r\n");
                if (!$this->expect(334)) { return false; }
                fwrite($this->conn, base64_encode($this->pass) . "\r\n");
                if (!$this->expect(235)) { $this->err = 'SMTP 认证失败（请检查账号/授权码）'; return false; }
            } elseif ($r === 504 || $r === 502 || $r === 503) {
                // 部分服务器只支持 AUTH PLAIN，兜底重试
                fwrite($this->conn, 'AUTH PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->pass) . "\r\n");
                if (!$this->expect(235)) { $this->err = 'SMTP 认证失败（请检查账号/授权码）'; return false; }
            } else {
                $this->err = '服务器不支持 LOGIN 认证方式';
                return false;
            }
        }

        if (!$this->cmd('MAIL FROM:<' . $this->from . '>', 250)) { return false; }
        if (!$this->cmd('RCPT TO:<' . $to . '>', 250)) { return false; }
        if (!$this->cmd('DATA', 354)) { return false; }

        fwrite($this->conn, $this->buildMessage($to, $subject, $htmlBody));
        if (!$this->expect(250)) { return false; }

        $this->cmd('QUIT');
        @fclose($this->conn);
        $this->conn = null;
        return true;
    }

    private function cmd($line, $want = null) {
        fwrite($this->conn, $line . "\r\n");
        return $this->expect($want);
    }

    private function expect($want = null) {
        $code = 0;
        $resp = '';
        do {
            $line = fgets($this->conn, 512);
            if ($line === false) { $this->err = 'SMTP 连接中断'; return false; }
            $resp = $line;
            $code = (int)substr($line, 0, 3);
        } while (isset($line[3]) && $line[3] === '-');
        if ($want !== null && $code !== $want) {
            $this->err = 'SMTP 返回 ' . $code . ' ' . trim($resp);
            return false;
        }
        return $code;
    }

    private function buildMessage($to, $subject, $htmlBody) {
        $fromName = $this->fromName !== '' ? $this->fromName : $this->from;
        $headers = '';
        $headers .= 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <" . $this->from . ">\r\n";
        $headers .= 'To: <' . $to . ">\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";
        $body = chunk_split(base64_encode($htmlBody), 76, "\r\n");
        return $headers . "\r\n" . $body . "\r\n.\r\n";
    }
}
