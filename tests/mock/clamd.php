<?php
declare(strict_types=1);

/**
 * Stand-in ClamAV daemon for tests/features_test.php: speaks the clamd INSTREAM protocol on the
 * port given as the first argument and reports the EICAR test string as malware.
 *   php tests/mock/clamd.php 3310
 */
$server = stream_socket_server('tcp://127.0.0.1:' . (int) ($argv[1] ?? 3310), $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "clamd mock: $errstr\n");
    exit(1);
}
while ($conn = @stream_socket_accept($server, -1)) {
    $command = '';
    while (!str_ends_with($command, "\0") && !feof($conn)) {
        $command .= (string) fread($conn, 1);
    }
    $data = '';
    while (!feof($conn)) {
        $len = fread($conn, 4);
        if (strlen((string) $len) < 4) {
            break;
        }
        $n = unpack('N', $len)[1];
        if ($n === 0) {
            break;
        }
        $chunk = '';
        while (strlen($chunk) < $n && !feof($conn)) {
            $chunk .= (string) fread($conn, $n - strlen($chunk));
        }
        $data .= $chunk;
    }
    fwrite($conn, str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
    fclose($conn);
}
