<?php
declare(strict_types=1);

// Stand-in Teams/Slack incoming webhook (php -S router): stores each JSON body for the test to read.
$dir = sys_get_temp_dir() . '/saqf-mock-webhook-' . $_SERVER['SERVER_PORT'];
@mkdir($dir, 0700, true);
file_put_contents($dir . '/' . microtime(true) . '.json', (string) file_get_contents('php://input'));
header('Content-Type: text/plain');
echo '1';
