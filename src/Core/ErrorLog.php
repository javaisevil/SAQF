<?php
declare(strict_types=1);

namespace Saqf\Core;

use Throwable;

/**
 * Captures unhandled errors into system_errors with a short reference code the
 * user can quote to IT support. Stack traces are never shown to end users in production.
 */
final class ErrorLog
{
    public static function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function record(Throwable $e, string $level = 'error'): string
    {
        $ref = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        try {
            Db::insert('system_errors', [
                'ref' => $ref,
                'occurred_at' => Clock::stamp(),
                'level' => $level,
                'message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000),
                'location' => mb_substr(str_replace(SAQF_ROOT, '', $e->getFile()) . ':' . $e->getLine(), 0, 300),
                'trace' => mb_substr(str_replace(SAQF_ROOT, '', $e->getTraceAsString()), 0, 8000),
                'url' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? 'cli'), 0, 400),
                'user_id' => $_SESSION['uid'] ?? null,
                'request_id' => Request::id(),
            ]);
        } catch (Throwable $ignored) {
            error_log('SAQF could not record error ' . $ref . ': ' . $e->getMessage());
        }
        error_log('SAQF error ' . $ref . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $ref;
    }

    public static function handle(Throwable $e): void
    {
        $ref = self::record($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Error [$ref]: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        $isApi = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Something went wrong. Reference ' . $ref]);
            return;
        }
        $detail = Config::debug() ? '<pre style="white-space:pre-wrap;font-size:12px">' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>' : '';
        echo '<!doctype html><meta charset="utf-8"><title>SAQF — error</title>'
            . '<div style="font-family:system-ui;max-width:560px;margin:80px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px">'
            . '<h2 style="margin:0 0 8px">Something went wrong</h2>'
            . '<p>The error was recorded for the system administrator. If you contact IT support, quote reference <strong>' . $ref . '</strong>.</p>'
            . $detail . '<p><a href="' . htmlspecialchars(Request::url('index.php')) . '">Back to SAQF</a></p></div>';
    }
}
