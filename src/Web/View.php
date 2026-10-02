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

    public static function pct($v, int $dec = 1): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $s = number_format((float) $v, $dec, '.', '');
        return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s) . '%';
    }

    public static function date(?string $d, string $fmt = 'j M Y'): string
    {
        return $d ? date($fmt, strtotime($d)) : '—';
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
            'institution' => ['Registrar', 'Synced from institutional master data'],
            'sis' => ['SIS', 'From the Student Information System feed'],
            'lms' => ['LMS', 'Imported from the learning management system'],
            'inherited' => ['Inherited', 'Carried forward from the approved specification'],
            'calculated' => ['Calculated', 'Computed by SAQF from structured data'],
            'derived' => ['Derived', 'Derived from other records (no manual entry)'],
            'policy' => ['Policy default', 'Institutional policy value'],
            'faculty' => ['Faculty input', 'Entered by the instructor (academic judgement)'],
            'suggested' => ['Suggested', 'Assisted suggestion confirmed by a person'],
            'overridden' => ['Overridden', 'Rule set aside by an authorised person with a recorded reason'],
            'seed' => ['Prototype seed', 'Prototype data — replace from the real source'],
        ];
        [$label, $default] = $labels[$kind] ?? [$kind, ''];
        return '<span class="src src-' . self::h($kind) . '" title="' . self::h($title ?: $default) . '">' . self::h($label) . '</span>';
    }

    public static function severity(string $sev): string
    {
        $map = ['blocker' => ['Must fix', 'red'], 'warning' => ['Attention', 'amber'], 'info' => ['Advisory', 'grey']];
        [$l, $t] = $map[$sev] ?? [$sev, 'grey'];
        return self::pill($l, $t);
    }

    public static function category(string $cat): string
    {
        $map = ['validation' => 'Validation', 'data' => 'Data exception', 'academic' => 'Academic', 'policy' => 'Policy exception', 'evidence' => 'Evidence', 'workflow' => 'Workflow', 'quality_risk' => 'Quality risk'];
        return '<span class="cat cat-' . self::h($cat) . '">' . self::h($map[$cat] ?? $cat) . '</span>';
    }

    public static function specStatus(?string $status): string
    {
        $map = ['draft' => ['Draft', 'grey'], 'pending_hod' => ['With HoD', 'blue'], 'pending_qa' => ['With QA', 'amber'], 'approved' => ['Approved', 'green'], 'superseded' => ['Superseded', 'grey']];
        [$l, $t] = $map[(string) $status] ?? [(string) $status, 'grey'];
        return self::pill($l, $t);
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
                return [['faculty.php', 'My actions & courses', 'home'], ['improvements.php', 'Improvement actions', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'hod':
                return [['department.php', 'Department', 'home'], ['approvals.php', 'Approvals', 'check'], ['exceptions.php', 'Exceptions', 'flag'], ['programs.php', 'Programs', 'grid'], ['improvements.php', 'Improvement actions', 'loop'], ['assign.php', 'Assign a course', 'plus'], ['spec_import.php', 'Import specifications', 'upload'], ['catalog.php', 'Study plans', 'book']];
            case 'qa':
                return [['quality.php', 'Quality overview', 'home'], ['exceptions.php', 'Exception center', 'flag'], ['approvals.php', 'Approvals & sample', 'check'], ['programs.php', 'Programs', 'grid'], ['improvements.php', 'Improvement actions', 'loop'], ['spec_import.php', 'Import specifications', 'upload'], ['policies.php', 'Quality policies', 'sliders'], ['catalog.php', 'Study plans', 'book']];
            case 'dean':
                return [['college.php', 'College quality', 'home'], ['programs.php', 'Programs', 'grid'], ['exceptions.php', 'Exceptions', 'flag'], ['improvements.php', 'Improvement actions', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'leadership':
                return [['institution.php', 'Institution', 'home'], ['programs.php', 'Programs', 'grid'], ['exceptions.php', 'Exceptions', 'flag'], ['improvements.php', 'Improvement actions', 'loop'], ['catalog.php', 'Study plans', 'book']];
            case 'admin':
                return [['admin.php', 'System health', 'home'], ['admin.php?tab=users', 'Users & access', 'users'], ['admin.php?tab=security', 'Security events', 'shield'], ['admin.php?tab=audit', 'Audit log', 'list'], ['admin.php?tab=errors', 'Error log', 'alert'], ['admin.php?tab=integrations', 'Integrations', 'plug'], ['policies.php', 'Policies (read-only)', 'sliders']];
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
        echo '<meta name="csrf" content="' . self::h(Csrf::token()) . '"></head><body class="role-' . self::h($user['role']) . '">';
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
        echo '</main><footer class="foot">SAQF ' . SAQF_VERSION . ' · Al Yamamah University · Supports NCAAA-oriented academic quality workflows (not a compliance certification)</footer></div></div>';
        echo '<script src="' . self::url('assets/app.js') . '?v=' . SAQF_VERSION . '"></script></body></html>';
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

    public static function bar(?float $value, ?float $target, bool $provisional = false): string
    {
        if ($value === null) {
            return '<div class="bar bar-empty"><span>no results yet</span></div>';
        }
        $met = $target === null || $value >= $target - 1e-9;
        $w = max(2, min(100, $value));
        $t = $target === null ? '' : '<i class="bar-target" style="left:' . min(100, $target) . '%" title="Target ' . self::pct($target) . '"></i>';
        return '<div class="bar ' . ($provisional ? 'bar-prov ' : '') . ($met ? 'bar-ok' : 'bar-low') . '"><b style="width:' . $w . '%"></b>' . $t . '<span>' . self::pct($value) . ($provisional ? ' · provisional' : '') . '</span></div>';
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
