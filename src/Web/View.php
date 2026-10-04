<?php
declare(strict_types=1);

namespace Saqf\Web;

use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Notify;
use Saqf\Core\Request;
use Saqf\Core\Session;
use Saqf\Security\Auth;

/** Rendering helpers shared by every page (server-rendered, escaped by default). */
final class View
{
    public static function h($v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $path): string
    {
        return Request::url($path);
    }

    /** A percentage for people: whole numbers ("72%"), unless more precision is asked for. */
    public static function pct($v, int $dec = 0): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $s = number_format((float) $v, $dec, '.', '');
        return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s) . '%';
    }

    public const MONTHS_AR = ['Jan' => 'يناير', 'Feb' => 'فبراير', 'Mar' => 'مارس', 'Apr' => 'أبريل', 'May' => 'مايو', 'Jun' => 'يونيو',
        'Jul' => 'يوليو', 'Aug' => 'أغسطس', 'Sep' => 'سبتمبر', 'Oct' => 'أكتوبر', 'Nov' => 'نوفمبر', 'Dec' => 'ديسمبر'];

    public static function date(?string $d, string $fmt = 'j M Y'): string
    {
        if (!$d) {
            return '—';
        }
        $out = date($fmt, strtotime($d));
        return I18n::rtl() ? strtr($out, self::MONTHS_AR) : $out;
    }

    /** "Verified ✓" with the technical fingerprint kept in the tooltip (people never need to read it). */
    public static function verified(?string $sha256, string $label = 'Verified'): string
    {
        if (!$sha256) {
            return '';
        }
        return '<span class="pill pill-green" title="' . self::h('Integrity check: SHA-256 fingerprint ' . $sha256) . '">✓ ' . self::h($label) . '</span>';
    }

    /** A number with a short explanation, e.g. "3 of 4 courses". */
    public static function count(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    public static function ago(?string $d): string
    {
        if (!$d) {
            return '—';
        }
        $s = Clock::now()->getTimestamp() - strtotime($d);
        if ($s < 60) {
            return 'just now';
        }
        if ($s < 3600) {
            return floor($s / 60) . ' min ago';
        }
        if ($s < 86400) {
            return floor($s / 3600) . ' h ago';
        }
        if ($s < 86400 * 30) {
            return floor($s / 86400) . ' days ago';
        }
        return self::date($d);
    }

    public static function pill(string $label, string $tone = 'grey', string $title = ''): string
    {
        return '<span class="pill pill-' . self::h($tone) . '"' . ($title ? ' title="' . self::h($title) . '"' : '') . '>' . self::h($label) . '</span>';
    }

    /** Data provenance chip: where a value came from. */
    public static function source(string $kind, string $title = ''): string
    {
        $labels = [
            'institution' => ['University records', 'From the Registrar\'s catalogue — nobody typed it'],
            'sis' => ['Timetable', 'From the university\'s student information system (teaching assignments)'],
            'lms' => ['Gradebook', 'Grades received from the learning management system'],
            'inherited' => ['Carried over', 'Copied from the approved course specification — nothing to re-enter'],
            'calculated' => ['Calculated', 'Worked out by SAQF from the grades and the course plan'],
            'derived' => ['Worked out', 'Worked out from other records — nobody typed it'],
            'policy' => ['University default', 'The university\'s standard value (set by Quality); the course can set its own'],
            'faculty' => ['Instructor', 'Written by the instructor (academic judgement)'],
            'suggested' => ['Suggestion', 'A suggestion from SAQF — applied only if a person accepts it'],
            'overridden' => ['Exception approved', 'Rule set aside by an authorised person, with the reason recorded'],
            'seed' => ['Sample data', 'Sample data — replace from the real source'],
        ];
        [$label, $default] = $labels[$kind] ?? [$kind, ''];
        return '<span class="src src-' . self::h($kind) . '" title="' . self::h($title ?: $default) . '">' . self::h($label) . '</span>';
    }

    public static function severity(string $sev): string
    {
        $map = ['blocker' => ['Must fix', 'red'], 'warning' => ['Needs attention', 'amber'], 'info' => ['For information', 'grey']];
        [$l, $t] = $map[$sev] ?? [$sev, 'grey'];
        return self::pill($l, $t);
    }

    public static function category(string $cat): string
    {
        $map = ['validation' => 'To fix', 'data' => 'Data problem', 'academic' => 'Academic decision', 'policy' => 'Exception to policy', 'evidence' => 'Evidence', 'workflow' => 'Next step', 'quality_risk' => 'Risk'];
        return '<span class="cat cat-' . self::h($cat) . '">' . self::h($map[$cat] ?? $cat) . '</span>';
    }

    public static function specStatus(?string $status): string
    {
        $map = ['draft' => ['Draft', 'grey'], 'pending_hod' => ['With the Head of Department', 'blue'], 'pending_qa' => ['With Quality', 'amber'], 'approved' => ['Approved', 'green'], 'superseded' => ['Older version', 'grey']];
        [$l, $t] = $map[(string) $status] ?? [(string) $status, 'grey'];
        return self::pill($l, $t);
    }

    /** Who a role is, in plain words (for "who handles this"). */
    public static function who(?string $role): string
    {
        $map = ['faculty' => 'Instructor', 'hod' => 'Head of Department', 'qa' => 'Quality', 'dean' => 'Dean', 'leadership' => 'University leadership', 'admin' => 'IT'];
        return $map[(string) $role] ?? ucfirst((string) $role);
    }

    /** The state of an issue, in plain words. */
    public static function issueStatus(?string $status): string
    {
        $map = ['open' => 'Still open', 'overridden' => 'Rule set aside', 'resolved' => 'Sorted out', 'auto_resolved' => 'Cleared by itself'];
        return $map[(string) $status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    /** How a specification version was decided, in plain words. */
    public static function route(?string $route): string
    {
        $map = [
            'auto_minor' => 'Approved automatically (no academic change)',
            'auto_green' => 'Approved by the Head of Department',
            'hod' => 'Approved by the Head of Department',
            'qa' => 'Approved by Quality',
            'seed' => 'Approved starting version',
            'import' => 'Imported as the approved version',
            'returned_hod' => 'Returned by the Head of Department',
            'returned_qa' => 'Returned by Quality',
            'no_change' => 'No change',
        ];
        return $map[(string) $route] ?? ucfirst(str_replace('_', ' ', (string) $route));
    }

    public static function flash(): string
    {
        $out = '';
        foreach (Session::takeFlash() as $f) {
            $out .= '<div class="alert alert-' . self::h($f['type']) . '" role="status">' . self::h($f['message']) . '</div>';
        }
        return $out;
    }

    public static function empty(string $title, string $text = ''): string
    {
        return '<div class="empty"><div class="empty-title">' . self::h($title) . '</div>' . ($text ? '<div class="empty-text">' . self::h($text) . '</div>' : '') . '</div>';
    }

    public static function nav(array $user): array
    {
        $common = [];
        switch ($user['role']) {
            case 'faculty':
                return [['faculty.php', 'My courses', 'home'], ['improvements.php', 'Improvements', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'hod':
                return [['department.php', 'My department', 'home'], ['approvals.php', 'Approvals', 'check'], ['exceptions.php', 'Problems to sort out', 'flag'], ['programs.php', 'Programs', 'grid'], ['improvements.php', 'Improvements', 'loop'], ['assign.php', 'Assign a course', 'plus'], ['spec_import.php', 'Import specifications', 'upload'], ['catalog.php', 'Study plans', 'book']];
            case 'qa':
                return [['quality.php', 'Overview', 'home'], ['exceptions.php', 'Problems to sort out', 'flag'], ['approvals.php', 'Approvals & spot-checks', 'check'], ['programs.php', 'Programs', 'grid'], ['improvements.php', 'Improvements', 'loop'], ['spec_import.php', 'Import specifications', 'upload'], ['translations.php', 'Arabic wording', 'globe'], ['policies.php', 'Quality policies', 'sliders'], ['catalog.php', 'Study plans', 'book']];
            case 'dean':
                return [['college.php', 'My college', 'home'], ['programs.php', 'Programs', 'grid'], ['exceptions.php', 'Problems to sort out', 'flag'], ['improvements.php', 'Improvements', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'leadership':
                return [['institution.php', 'University overview', 'home'], ['programs.php', 'Programs', 'grid'], ['exceptions.php', 'Problems to sort out', 'flag'], ['improvements.php', 'Improvements', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'admin':
                return [['admin.php', 'System health', 'home'], ['admin.php?tab=users', 'Users & access', 'users'], ['admin.php?tab=security', 'Security events', 'shield'], ['incidents.php', 'Security incidents', 'alert'], ['admin.php?tab=audit', 'Activity log', 'list'], ['admin.php?tab=errors', 'Error log', 'alert'], ['admin.php?tab=integrations', 'University systems', 'plug'], ['translations.php', 'Arabic wording', 'globe'], ['policies.php', 'Policies (read-only)', 'sliders']];
        }
        return $common;
    }

    public static function icon(string $name): string
    {
        $paths = [
            'home' => 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z',
            'loop' => 'M4 12a8 8 0 0 1 13.7-5.7L20 8.6M20 4v4.6h-4.6M20 12a8 8 0 0 1-13.7 5.7L4 15.4M4 20v-4.6h4.6',
            'book' => 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zm0 0v16M8 7h7',
            'check' => 'M4 12.5 9 17.5 20 6.5',
            'flag' => 'M5 21V4m0 0h11l-2 4 2 4H5',
            'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
            'plus' => 'M12 5v14M5 12h14',
            'sliders' => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
            'users' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M22 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8',
            'shield' => 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z',
            'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
            'alert' => 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0',
            'plug' => 'M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4',
            'bell' => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
            'search' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16M21 21l-4.3-4.3',
            'spark' => 'M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8',
            'cpu' => 'M9 3v2M15 3v2M9 19v2M15 19v2M3 9h2M3 15h2M19 9h2M19 15h2M6 5h12a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM9 9h6v6H9z',
            'upload' => 'M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3',
            'lock' => 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3',
            'bolt' => 'M13 3 4 14h7l-1 7 9-11h-7z',
            'globe' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
            'route' => 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4M18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4M6 15V9a4 4 0 0 1 4-4h6M18 9v6a4 4 0 0 1-4 4H8',
        ];
        return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($paths[$name] ?? '') . '"/></svg>';
    }

    public static function header(string $title, array $user, array $opts = []): void
    {
        $active = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $unread = Notify::unreadCount($user['id']);
        $term = Db::one('SELECT * FROM terms WHERE status = "active" ORDER BY sequence DESC LIMIT 1');
        $week = $term ? max(1, (int) floor((Clock::now()->getTimestamp() - strtotime($term['starts_on'])) / 604800) + 1) : null;
        $roleLabel = Auth::ROLES[$user['role']] ?? $user['role'];
        $scope = $user['department_name'] ?? ($user['college_name'] ?? 'Al Yamamah University');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . self::h($title) . ' · SAQF</title><link rel="icon" href="' . self::url('assets/favicon.png') . '">';
        echo '<link rel="stylesheet" href="' . self::url('assets/app.css') . '?v=' . SAQF_VERSION . '">';
        echo '<meta name="csrf" content="' . self::h(Csrf::token()) . '"><meta name="saqf-idle" content="' . (int) \Saqf\Core\Policy::get('session.idle_minutes') * 60 . '"></head><body class="role-' . self::h($user['role']) . '">';
        echo '<a class="skip" href="#main">Skip to content</a><div class="shell"><aside class="side" id="side">';
        echo '<div class="brand"><img src="' . self::url('assets/yu-logo.png') . '" alt="Al Yamamah University"><div class="brand-name">SAQF <span>Academic Quality Automation</span></div></div>';
        echo '<nav class="nav" aria-label="Main">';
        foreach (self::nav($user) as [$href, $label, $icon]) {
            [$file, $hq] = array_pad(explode('?', $href, 2), 2, '');
            $isActive = $file === $active && ($hq === '' ? !str_contains($qs, 'tab=') || $file !== 'admin.php' : str_contains($qs, $hq));
            echo '<a href="' . self::url($href) . '" class="' . ($isActive ? 'on' : '') . '">' . self::icon($icon) . '<span>' . self::h($label) . '</span></a>';
        }
        echo '</nav><div class="side-foot"><div class="me"><div class="avatar">' . self::h(mb_substr(preg_replace('/^(Dr\.|Prof\.)\s*/', '', $user['full_name']), 0, 1)) . '</div><div><div class="me-name">' . self::h($user['full_name']) . '</div><div class="me-role">' . self::h($roleLabel) . '</div></div></div>';
        echo '<div class="side-links"><a href="' . self::url('account.php') . '">Account & security</a><form method="post" action="' . self::url('logout.php') . '">' . Csrf::field() . '<button class="linkbtn" type="submit">Sign out</button></form></div></div></aside>';
        echo '<div class="main"><header class="top"><button class="burger" type="button" aria-label="Menu" data-toggle="#side">☰</button>';
        echo '<div class="top-title"><h1>' . self::h($title) . '</h1>' . (!empty($opts['subtitle']) ? '<div class="sub">' . $opts['subtitle'] . '</div>' : '') . '</div>';
        echo '<form class="search" action="' . self::url('search.php') . '" method="get" role="search">' . self::icon('search') . '<input name="q" placeholder="Search courses, programs, CLOs, PLOs, people, issues" value="' . self::h($_GET['q'] ?? '') . '" aria-label="Search" autocomplete="off" id="globalSearch"><div class="search-pop" id="searchPop"></div></form>';
        if ($term) {
            echo '<div class="termchip" title="Active term from the SIS">' . self::h($term['name']) . ($week && $week <= 18 ? ' · week ' . $week : '') . '</div>';
        }
        echo '<a class="helpbtn" href="' . self::url('help.php') . '" title="Help and keyboard shortcuts (press ?)" aria-label="Help">?</a>';
        echo '<a class="bell" href="' . self::url('notifications.php') . '" title="Notifications (only things that need you)">' . self::icon('bell') . ($unread ? '<span class="dot">' . $unread . '</span>' : '') . '</a>';
        echo '<div class="scope" title="Your data scope">' . self::h($scope) . '</div>' . \Saqf\Web\I18n::switchLink() . '</header>';
        if (Config::demoMode()) {
            $opts = '';
            foreach (\Saqf\Demo\Story::USERS as [$u, $name, , $role]) {
                $opts .= '<option value="' . self::h($u) . '"' . ($u === $user['username'] ? ' selected' : '') . '>' . self::h((Auth::ROLES[$role] ?? $role) . ' — ' . $name) . '</option>';
            }
            echo '<div class="demobar"><span>Demo mode · fictional people and results on YU\'s real study plans · university systems simulated</span>'
                . '<form method="post" action="' . self::url('demo.php') . '" class="demo-switch">' . Csrf::field() . '<input type="hidden" name="next" value="index.php"><label>Switch role <select name="as" data-autosubmit>' . $opts . '</select></label></form>'
                . '<a href="' . self::url('tour.php') . '">Guided tour</a></div>';
        }
        echo '<main id="main" class="content">' . self::flash();
    }

    public static function footer(): void
    {
        echo '</main><footer class="foot">SAQF ' . SAQF_VERSION . ' · Al Yamamah University · Supports NCAAA-oriented academic quality workflows (not a compliance certification)<span aria-hidden="true"> · </span><a href="' . self::url('help.php') . '">Help</a></footer></div></div>';
        // Before the idle timeout signs the person out, the page warns and offers to stay signed in.
        echo '<div class="session-warn" id="sessionWarn" hidden role="alertdialog" aria-labelledby="sessionWarnTitle"><div><strong id="sessionWarnTitle">You will be signed out soon</strong>'
            . '<div class="small">For your security, SAQF signs you out after ' . (int) \Saqf\Core\Policy::get('session.idle_minutes') . ' minutes without activity. Time left: <b class="mono" data-countdown>2:00</b></div></div>'
            . '<div class="row"><button type="button" class="btn btn-sm btn-primary" data-stay>Stay signed in</button><form method="post" action="' . self::url('logout.php') . '">' . Csrf::field() . '<button class="btn btn-sm" type="submit">Sign out now</button></form></div></div>';
        echo '<div class="kbd-help" id="kbdHelp" hidden role="dialog" aria-modal="true" aria-labelledby="kbdTitle"><div class="kbd-card"><div class="row between"><h2 id="kbdTitle">Keyboard shortcuts</h2><button type="button" class="btn btn-sm" data-close>Close</button></div><table><tbody>'
            . '<tr><td><kbd>/</kbd></td><td>Search</td></tr><tr><td><kbd>g</kbd> <kbd>h</kbd></td><td>Go to your home page</td></tr><tr><td><kbd>g</kbd> <kbd>n</kbd></td><td>Go to notifications</td></tr>'
            . '<tr><td><kbd>g</kbd> <kbd>a</kbd></td><td>Go to Account & security</td></tr><tr><td><kbd>?</kbd></td><td>Show this list</td></tr><tr><td><kbd>Esc</kbd></td><td>Close this list or the search results</td></tr>'
            . '</tbody></table><p class="small"><a href="' . self::url('help.php') . '">Open the help page</a></p></div></div>';
        echo self::uiText() . '<script src="' . self::url('assets/app.js') . '?v=' . SAQF_VERSION . '"></script></body></html>';
    }

    /**
     * Words the page script shows (toasts, the live weight total, password tips, the robot check):
     * rendered in the page so the Arabic interface translates them like any other text.
     */
    public static function uiText(): string
    {
        $k = ['saved' => 'Saved', 'cleared' => 'Cleared automatically:', 'opened' => 'New check:', 'failed' => 'Could not save', 'network' => 'Network error — nothing was changed.',
            'total' => 'Total', 'must' => 'must be', 'nomatch' => 'No matches', 'show' => 'Show', 'hide' => 'Hide', 'showpw' => 'Show password', 'hidepw' => 'Hide password',
            'caps' => 'Caps Lock is on', 'weak' => 'Weak', 'fair' => 'Fair', 'good' => 'Good', 'strong' => 'Strong', 'len' => 'At least 10 characters',
            'mix' => 'Letters and numbers', 'long' => '14 or more characters, or a passphrase', 'common' => 'Not a common password or keyboard run',
            'passkey_failed' => 'The passkey could not be used.', 'passkey_unsupported' => 'This browser cannot use passkeys.', 'working' => 'Please wait…', 'robot' => 'Please confirm you are not a robot first.', 'dismiss' => 'Dismiss', 'stayed' => 'You are still signed in.'];
        $out = '<div id="ui-text" hidden>';
        foreach ($k as $key => $text) {
            $out .= '<span data-k="' . $key . '">' . self::h($text) . '</span>';
        }
        return $out . '</div>';
    }

    /** End of the stand-alone sign-in pages (sign-in, two-step, password reset): page script and its words. */
    public static function authFoot(): string
    {
        return self::uiText() . '<script src="' . self::url('assets/app.js') . '?v=' . SAQF_VERSION . '"></script>';
    }

    /** Tabs component. $tabs: key => label. */
    public static function tabs(array $tabs, string $active, string $base): string
    {
        $out = '<nav class="tabs" role="tablist">';
        foreach ($tabs as $k => $label) {
            $out .= '<a role="tab" aria-selected="' . ($k === $active ? 'true' : 'false') . '" class="' . ($k === $active ? 'on' : '') . '" href="' . self::h($base . (str_contains($base, '?') ? '&' : '?') . 'tab=' . $k) . '">' . $label . '</a>';
        }
        return $out . '</nav>';
    }

    /**
     * Achievement bar: the share of students who met an outcome, with the goal marked.
     * "72% · goal 70%"; "72% so far" while not every assessment is graded.
     */
    public static function bar(?float $value, ?float $target, bool $provisional = false): string
    {
        if ($value === null) {
            return '<div class="bar bar-empty"><span>no grades yet</span></div>';
        }
        $met = $target === null || $value >= $target - 1e-9;
        $w = max(2, min(100, $value));
        $tip = self::pct($value) . ' of students met this' . ($target === null ? '' : ' (goal ' . self::pct($target) . ')') . ($provisional ? '. Early result: not every assessment is graded yet.' : '.');
        $t = $target === null ? '' : '<i class="bar-target" style="left:' . min(100, $target) . '%"></i>';
        $label = self::pct($value) . ($provisional ? ' so far' : '') . ($target === null ? '' : ' · goal ' . self::pct($target));
        return '<div class="bar ' . ($provisional ? 'bar-prov ' : '') . ($met ? 'bar-ok' : 'bar-low') . '" title="' . self::h($tip) . '"><b style="width:' . $w . '%"></b>' . $t . '<span>' . self::h($label) . '</span></div>';
    }

    public static function spark(array $values, ?float $target = null, int $w = 120, int $h = 30): string
    {
        $values = array_values(array_filter($values, static fn($v) => $v !== null));
        if (count($values) < 2) {
            return '';
        }
        $min = min(array_merge($values, [$target ?? 100])) - 5;
        $max = max(array_merge($values, [$target ?? 0])) + 5;
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i / (count($values) - 1) * ($w - 6) + 3, 1) . ',' . round($h - 3 - ($v - $min) / max(1, $max - $min) * ($h - 6), 1);
        }
        $ty = $target !== null ? round($h - 3 - ($target - $min) / max(1, $max - $min) * ($h - 6), 1) : null;
        return '<svg class="spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">' . ($ty !== null ? '<line x1="0" x2="' . $w . '" y1="' . $ty . '" y2="' . $ty . '" class="spark-t"/>' : '') . '<polyline points="' . implode(' ', $pts) . '"/></svg>';
    }
}
