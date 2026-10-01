<?php
declare(strict_types=1);

/**
 * Helpers for tests/production_test.php and tests/sso_test.php: assertions and local stand-in
 * servers (php -S routers in tests/mock) started on free ports and stopped when the test ends.
 */

$pass = 0;
$fail = 0;

function ok($cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label\n";
    }
}

function throws(callable $fn, string $label, string $class = Throwable::class): void
{
    try {
        $fn();
        ok(false, $label . ' (no exception)');
    } catch (Throwable $e) {
        ok($e instanceof $class, $label . ' — "' . mb_strimwidth($e->getMessage(), 0, 90, '…') . '"');
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): void
{
    global $pass, $fail;
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) strrchr($name, ':'), 1);
}

/** Starts a background process; it is terminated when the test script exits. */
function spawn(array $cmd, array $env = []): void
{
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', sys_get_temp_dir() . '/saqf-test-servers.log', 'a']], $pipes, null, $env ? $env + getenv() : null);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start ' . implode(' ', $cmd));
    }
    register_shutdown_function(static function () use ($proc) {
        proc_terminate($proc);
        proc_close($proc);
    });
}

function wait_port(int $port): void
{
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("Server on port $port did not start");
}

/** php -S with a router script or document root; returns the base URL. */
function serve(string $routerOrDocroot, array $env = [], ?int $port = null): string
{
    $port = $port ?? free_port();
    $cmd = is_dir($routerOrDocroot)
        ? [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $routerOrDocroot]
        : [PHP_BINARY, '-S', "127.0.0.1:$port", $routerOrDocroot];
    spawn($cmd, $env);
    wait_port($port);
    return "http://127.0.0.1:$port";
}

function tempdir(string $prefix): string
{
    $d = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    return $d;
}

function write_csv(string $path, array $rows): void
{
    @mkdir(dirname($path), 0700, true);
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
}
