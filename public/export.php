<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Db;
use Saqf\Core\Session;
use Saqf\Core\Throttle;
use Saqf\Quality\Closeout;
use Saqf\Quality\NcaaaExport;
use Saqf\Quality\Specs;
use Saqf\Security\Authz;
use Saqf\Web\I18n;

// Word downloads laid out like the NCAAA course specification and course report (same scope as the reports).
$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$doc = in_array($_GET['doc'] ?? '', ['spec', 'report', 'package'], true) ? $_GET['doc'] : 'report';
$id = (int) ($_GET['id'] ?? 0);
$lang = ($_GET['lang'] ?? I18n::lang()) === 'ar' ? 'ar' : 'en';

if ($doc === 'report' || $doc === 'package') {
    $o = Authz::offering($user, $id);
    $label = "{$o['course_code']} course " . ($doc === 'package' ? 'file package' : 'report') . " {$o['term_name']}";
} else {
    $v = Specs::version($id);
    if (!$v || !Authz::canViewCourse($user, (int) $v['course_id'])) {
        Authz::deny('specification export');
    }
    $label = Db::val('SELECT code FROM courses WHERE id = ?', [$v['course_id']]) . ' specification v' . $v['version_no'];
}
try {
    Throttle::check('export:' . $user['id'], 60, 3600, 'Too many downloads in the last hour. Please try again later.');
} catch (RuntimeException $e) {
    Session::flash('error', $e->getMessage());
    saqf_redirect('index.php');
}
if ($doc === 'package') {
    // Course file package (ZIP): report, specification, evidence index, provenance and checksums; the
    // package itself is audited with its checksum by Closeout::package.
    $p = Closeout::package($o, $user);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $p['name'] . '"');
    header('Content-Length: ' . strlen($p['bytes']));
    header('Cache-Control: private, no-store');
    header('X-Content-SHA256: ' . $p['sha256']);
    echo $p['bytes'];
    exit;
}
Audit::record('export.downloaded', $doc === 'report' ? 'offering' : 'spec_version', $id, "Word export: $label" . ($lang === 'ar' ? ' (Arabic headings)' : ''));
$file = preg_replace('/[^A-Za-z0-9\-]+/', '-', $label) . ($lang === 'ar' ? '-ar' : '') . '.docx';
($doc === 'report' ? NcaaaExport::courseReport($id, $lang) : NcaaaExport::specification($id, $lang))->send($file);
