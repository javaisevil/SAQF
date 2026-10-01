<?php
// Minimal SMTP server for tests: php tests/mock/smtp.php <port> <capture-file>
// Accepts connections one at a time (EHLO, AUTH PLAIN/LOGIN, MAIL, RCPT, DATA, RSET, QUIT)
// and appends each received message to the capture file as one JSON line.
[$port, $file] = [(int) ($argv[1] ?? 2525), (string) ($argv[2] ?? 'php://stdout')];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
while ($conn = @stream_socket_accept($server, -1)) {
    $say = static fn(string $l) => fwrite($conn, $l . "\r\n");
    $say('220 mock.smtp ESMTP ready');
    $msg = ['auth' => null, 'from' => null, 'to' => [], 'data' => ''];
    $authLogin = null;
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        $cmd = strtoupper(substr($line, 0, 4));
        if ($authLogin === 'user') {
            $msg['auth'] = base64_decode($line);
            $authLogin = 'pass';
            $say('334 UGFzc3dvcmQ6');
            continue;
        }
        if ($authLogin === 'pass') {
            $msg['auth'] .= ':' . base64_decode($line);
            $authLogin = null;
            $say('235 2.7.0 Authenticated');
            continue;
        }
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($conn, "250-mock.smtp\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
        } elseif (str_starts_with(strtoupper($line), 'AUTH PLAIN ')) {
            $msg['auth'] = base64_decode(substr($line, 11));
            $say('235 2.7.0 Authenticated');
        } elseif (strtoupper($line) === 'AUTH LOGIN') {
            $authLogin = 'user';
            $say('334 VXNlcm5hbWU6');
        } elseif ($cmd === 'MAIL') {
            $msg['from'] = $line;
            $say('250 OK');
        } elseif ($cmd === 'RCPT') {
            $msg['to'][] = $line;
            $say('250 OK');
        } elseif ($cmd === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            while (($d = fgets($conn)) !== false && rtrim($d, "\r\n") !== '.') {
                $msg['data'] .= $d;
            }
            file_put_contents($file, json_encode($msg) . "\n", FILE_APPEND | LOCK_EX);
            $msg = ['auth' => $msg['auth'], 'from' => null, 'to' => [], 'data' => ''];
            $say('250 OK queued');
        } elseif ($cmd === 'QUIT') {
            $say('221 Bye');
            break;
        } else {
            $say('250 OK');
        }
    }
    fclose($conn);
}
