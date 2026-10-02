<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\Evidence;
use Saqf\Security\Authz;

// Evidence downloads: same scope as the course workspace, every download audited.
$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$e = Db::one('SELECT * FROM evidence_files WHERE id = ? AND deleted_at IS NULL', [(int) ($_GET['id'] ?? 0)]);
if (!$e) {
    Authz::deny('evidence file', 404);
}
Authz::offering($user, (int) $e['offering_id']);
Evidence::send($e);
