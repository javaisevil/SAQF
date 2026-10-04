<?php
declare(strict_types=1);

// RFC 9116 security.txt, served at /.well-known/security.txt (Apache rewrite in docker/apache-saqf.conf;
// elsewhere map the path to this file). It names a contact only if the university configured one
// (SAQF_SECURITY_CONTACT=mailto:security@university.edu or an https:// address), so SAQF never invents one.
define('SAQF_STATELESS', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Core\Config;
use Saqf\Core\Mailer;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$contact = trim((string) Config::get('SAQF_SECURITY_CONTACT', ''));
if (!preg_match('#^(mailto:[^\s@]+@[^\s@]+\.[^\s@]+|https://\S+)$#i', $contact)) {
    http_response_code(404);
    echo "No security contact is published for this installation.\n";
    exit;
}
$base = Mailer::baseUrl();
echo 'Contact: ' . $contact . "\n";
echo 'Expires: ' . gmdate('Y-m-d\TH:i:s\Z', time() + 365 * 86400) . "\n";
echo "Preferred-Languages: en, ar\n";
if ($base !== '') {
    echo 'Canonical: ' . $base . "/.well-known/security.txt\n";
}
