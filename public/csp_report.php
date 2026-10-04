<?php
declare(strict_types=1);

// Receives Content-Security-Policy violation reports from browsers (the policy names this address in report-uri).
// A report means a browser refused to run or load something the page asked for: injected markup, a stray inline
// script, a blocked address. No session, no authentication (browsers send reports on their own), nothing is trusted:
// the body is small, parsed strictly, reduced to a few harmless fields (never full addresses with query strings),
// rate limited per network and overall, and recorded once per distinct violation per day in the audit log.
// The answer is always 204 so a sender learns nothing about limits.
define('SAQF_STATELESS', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Core\Audit;
use Saqf\Core\Request;
use Saqf\Core\Throttle;

header('Cache-Control: no-store');
http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    exit;
}
$body = (string) file_get_contents('php://input', false, null, 0, 8193);
$data = json_decode($body, true);
$r = is_array($data) && is_array($data['csp-report'] ?? null) ? $data['csp-report'] : null;
if ($r === null) {
    exit;
}
/** Keeps only the kind of address (inline/eval/data) or scheme + host: never a path, query or fragment. */
$where = static function ($v): string {
    $v = is_string($v) ? trim($v) : '';
    if ($v === '' || in_array($v, ['inline', 'eval', 'data', 'blob', 'self', 'about'], true)) {
        return $v === '' ? 'unknown' : $v;
    }
    $p = parse_url(mb_substr($v, 0, 300));
    return is_array($p) && isset($p['host']) ? preg_replace('/[^A-Za-z0-9.\-:]/', '', ($p['scheme'] ?? '?') . '://' . $p['host']) : 'unknown';
};
$directive = preg_replace('/[^a-z\-]/', '', strtolower(mb_substr((string) ($r['effective-directive'] ?? $r['violated-directive'] ?? ''), 0, 40)));
$blocked = $where($r['blocked-uri'] ?? '');
$doc = parse_url((string) ($r['document-uri'] ?? ''), PHP_URL_PATH);
$page = is_string($doc) ? preg_replace('/[^A-Za-z0-9_.\-\/]/', '', mb_substr($doc, 0, 80)) : 'unknown';
if ($directive === '' || !Throttle::hit('csp:ip:' . Request::ip(), 20, 3600) || !Throttle::hit('csp:all', 60, 3600)) {
    exit;
}
// One entry per distinct violation per day: a page that breaks the policy on every load cannot flood the log.
if (!Throttle::hit('cspd:' . substr(sha1($directive . '|' . $blocked . '|' . $page), 0, 40), 1, 86400)) {
    exit;
}
Audit::asSystem(static function () use ($directive, $blocked, $page) {
    Audit::record('security.csp_violation', 'access', null, "A browser blocked content on $page under the page policy ($directive: $blocked)", null, ['directive' => $directive, 'blocked' => $blocked, 'page' => $page]);
}, 'system', 'Browser report');
