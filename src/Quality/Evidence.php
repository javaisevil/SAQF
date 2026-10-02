<?php
declare(strict_types=1);

namespace Saqf\Quality;

use InvalidArgumentException;
use RuntimeException;
use Saqf\Core\Alerts;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Ledger;
use Saqf\Core\Policy;

/**
 * Assessment evidence for the course file: the assessment itself (paper or brief), its marking
 * rubric and samples of marked student work. Files are kept outside the web root under random
 * names, checked by content (not just extension), optionally virus-scanned (ClamAV), and every
 * upload, download and removal is audited. Uploading evidence clears the EVIDENCE_REQUESTED advisory.
 *
 *   SAQF_STORAGE_DIR   where files are kept (default storage/evidence; a Docker volume)
 *   SAQF_CLAMAV_HOST   host:port of a clamd service; when set, every upload is scanned and refused
 *                      if the scanner is unreachable (fail closed)
 */
final class Evidence
{
    public const KINDS = ['assessment' => 'Assessment paper / brief', 'rubric' => 'Marking rubric', 'student_work' => 'Sample of marked student work', 'other' => 'Other evidence'];

    /** extension => mime type */
    public const TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
    ];

    public static function dir(): string
    {
        return rtrim((string) (Config::get('SAQF_STORAGE_DIR') ?: SAQF_ROOT . '/storage/evidence'), '/');
    }

    /** @return list<array> evidence of an offering (newest first), with uploader and assessment names */
    public static function forOffering(int $offeringId): array
    {
        return Db::all(
            'SELECT e.*, u.full_name AS uploader, a.name AS assessment_name FROM evidence_files e LEFT JOIN users u ON u.id = e.uploaded_by
             LEFT JOIN assessments a ON a.id = e.assessment_id WHERE e.offering_id = ? AND e.deleted_at IS NULL ORDER BY e.uploaded_at DESC, e.id DESC',
            [$offeringId]
        );
    }

    /**
     * Stores an uploaded file. $file is one entry of $_FILES (or an equivalent array with tmp_name/name/size/error).
     * @return int evidence id
     */
    public static function store(array $offering, array $file, string $kind, string $title, ?int $assessmentId, ?string $section, ?array $user, bool $moveUploaded = true): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The file is larger than the server allows.' : 'Choose a file to upload.');
        }
        $max = Policy::get('evidence.max_mb') * 1024 * 1024;
        $size = (int) filesize($file['tmp_name']);
        if ($size <= 0 || $size > $max) {
            throw new InvalidArgumentException('Evidence files must be smaller than ' . Policy::get('evidence.max_mb') . ' MB.');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Choose what kind of evidence this is.');
        }
        $original = self::cleanName((string) ($file['name'] ?? 'evidence'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new InvalidArgumentException('Upload a PDF, Word, Excel, PowerPoint, PNG, JPEG or text file (macro-enabled Office files are not accepted).');
        }
        self::checkContent($file['tmp_name'], $ext);
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 200) : mb_substr(pathinfo($original, PATHINFO_FILENAME), 0, 200);
        if ($assessmentId !== null && !Db::val('SELECT 1 FROM assessments WHERE id = ? AND spec_version_id = ?', [$assessmentId, $offering['spec_version_id']])) {
            throw new InvalidArgumentException('That assessment is not part of this course\'s specification.');
        }
        $scan = self::scan($file['tmp_name'], $offering, $original, $user);

        $stored = bin2hex(random_bytes(20));
        $target = self::path($stored);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('The evidence store is not writable (' . self::dir() . ').');
        }
        $ok = $moveUploaded ? @move_uploaded_file($file['tmp_name'], $target) : @copy($file['tmp_name'], $target);
        if (!$ok) {
            throw new RuntimeException('The evidence store is not writable (' . self::dir() . ').');
        }
        @chmod($target, 0640);
        self::giveToWebServer($target);
        $id = Db::insert('evidence_files', [
            'offering_id' => (int) $offering['id'], 'assessment_id' => $assessmentId, 'section_code' => Sections::code($section),
            'kind' => $kind, 'title' => $title, 'original_name' => $original, 'stored_name' => $stored, 'mime' => self::TYPES[$ext],
            'size_bytes' => $size, 'sha256' => hash_file('sha256', $target), 'scan_status' => $scan,
            'uploaded_by' => $user['id'] ?? null, 'uploaded_at' => Clock::stamp(),
        ]);
        Audit::record('evidence.uploaded', 'offering', (int) $offering['id'], "Evidence \"$title\" (" . self::KINDS[$kind] . ', ' . self::size($size) . ") added to {$offering['course_code']}", null, ['evidence' => $id, 'sha256' => substr(hash_file('sha256', $target), 0, 16), 'scan' => $scan]);
        Ledger::add('evidence_linked', 1, (int) $offering['id'], (int) $offering['course_id'], 'Assessment evidence filed');
        Engine::evaluateOffering((int) $offering['id']);
        return $id;
    }

    /**
     * A file filed from the command line as root (demo reset inside the container) is handed to the
     * web server's user, so it can still be downloaded and its folder still takes new uploads.
     */
    private static function giveToWebServer(string $target): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('posix_getpwnam') || !posix_getpwnam('www-data')) {
            return;
        }
        foreach ([dirname($target), $target] as $p) {
            @chown($p, 'www-data');
            @chgrp($p, 'www-data');
        }
    }

    /** Streams a file to the browser (the caller has authorised the offering). */
    public static function send(array $e): void
    {
        $path = self::path($e['stored_name']);
        if (!is_file($path)) {
            throw new RuntimeException('The evidence file is missing from the store.');
        }
        Audit::record('evidence.downloaded', 'offering', (int) $e['offering_id'], "Evidence \"{$e['title']}\" downloaded", null, ['evidence' => (int) $e['id']]);
        header('Content-Type: ' . $e['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $e['original_name']) . '"; filename*=UTF-8\'\'' . rawurlencode($e['original_name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($path);
    }

    /** Removes evidence from the course file (kept on disk for the audit trail; a reason is required). */
    public static function remove(int $id, array $user, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('Say why this evidence is being removed.');
        }
        $e = Db::one('SELECT * FROM evidence_files WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$e) {
            throw new InvalidArgumentException('Evidence not found.');
        }
        Db::update('evidence_files', ['deleted_at' => Clock::stamp(), 'deleted_by' => $user['id'], 'delete_reason' => mb_substr(trim($reason), 0, 300)], 'id = ?', [$id]);
        Audit::record('evidence.removed', 'offering', (int) $e['offering_id'], "Evidence \"{$e['title']}\" removed from the course file", null, ['evidence' => $id], $reason);
        Engine::evaluateOffering((int) $e['offering_id']);
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private static function path(string $stored): string
    {
        return self::dir() . '/' . substr($stored, 0, 2) . '/' . $stored;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*\/]/u', '', $name);
        return mb_substr(trim($name) !== '' ? trim($name) : 'evidence', 0, 200);
    }

    /** The content must match the extension; Office files must not carry macros. */
    private static function checkContent(string $path, string $ext): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $bad = static fn() => throw new InvalidArgumentException('The file content does not match its type (.' . $ext . ').');
        switch ($ext) {
            case 'pdf':
                str_starts_with($head, '%PDF-') || $bad();
                break;
            case 'png':
                $head === "\x89PNG\r\n\x1a\n" || $bad();
                break;
            case 'jpg':
            case 'jpeg':
                str_starts_with($head, "\xFF\xD8\xFF") || $bad();
                break;
            case 'txt':
                $text = (string) file_get_contents($path);
                (mb_check_encoding($text, 'UTF-8') && !str_contains($text, "\0")) || $bad();
                break;
            default: // Office Open XML: a ZIP package with the matching part, without a VBA project
                str_starts_with($head, "PK\x03\x04") || $bad();
                $body = (string) file_get_contents($path);
                $part = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
                (str_contains($body, '[Content_Types].xml') && str_contains($body, $part)) || $bad();
                if (stripos($body, 'vbaProject.bin') !== false) {
                    throw new InvalidArgumentException('Office files with macros are not accepted. Save it as a normal .' . $ext . ' or PDF.');
                }
        }
    }

    /** ClamAV INSTREAM scan when SAQF_CLAMAV_HOST is set. @return string clean | not_scanned */
    private static function scan(string $path, array $offering, string $name, ?array $user): string
    {
        $host = trim((string) Config::get('SAQF_CLAMAV_HOST', ''));
        if ($host === '') {
            return 'not_scanned';
        }
        [$h, $p] = array_pad(explode(':', $host, 2), 2, '3310');
        $sock = @stream_socket_client('tcp://' . $h . ':' . (int) $p, $errno, $errstr, 5);
        if (!$sock) {
            Alerts::raise('scanner.unreachable', 'warning', 'Virus scanner unreachable', "Evidence uploads are refused until clamd at $host answers ($errstr).");
            throw new RuntimeException('The virus scanner is not available, so uploads are paused. IT has been alerted; please try again later.');
        }
        stream_set_timeout($sock, 30);
        fwrite($sock, "zINSTREAM\0");
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            $chunk = (string) fread($fh, 8192);
            if ($chunk !== '') {
                fwrite($sock, pack('N', strlen($chunk)) . $chunk);
            }
        }
        fclose($fh);
        fwrite($sock, pack('N', 0));
        $reply = trim((string) stream_get_contents($sock), "\0\r\n ");
        fclose($sock);
        Alerts::resolve('scanner.unreachable');
        if (str_ends_with($reply, 'OK')) {
            return 'clean';
        }
        if (str_contains($reply, 'FOUND')) {
            Audit::record('security.malware_blocked', 'offering', (int) $offering['id'], "Upload \"$name\" refused: the virus scanner reported " . mb_substr($reply, 0, 120), null, ['user' => $user['id'] ?? null]);
            Alerts::raise('security.malware', 'critical', 'Malware blocked at upload', "\"$name\" for {$offering['course_code']} was refused: " . mb_substr($reply, 0, 160));
            throw new InvalidArgumentException('The file was refused by the virus scanner. IT security has been notified.');
        }
        throw new RuntimeException('The virus scanner could not check the file (' . mb_substr($reply, 0, 80) . ').');
    }
}
