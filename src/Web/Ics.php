<?php
declare(strict_types=1);

namespace Saqf\Web;

/** Minimal iCalendar (RFC 5545) writer for all-day deadlines: opens in Outlook, Google and Apple Calendar. */
final class Ics
{
    /** @param list<array{uid:string,date:string,title:string,description?:string}> $events */
    public static function calendar(string $name, array $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SAQF//Academic Quality//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::text($name)];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $e) {
            $day = \DateTimeImmutable::createFromFormat('Y-m-d', substr($e['date'], 0, 10));
            if (!$day) {
                continue;
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . substr(hash('sha256', $e['uid']), 0, 32) . '@saqf';
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd');
            $lines[] = 'SUMMARY:' . self::text($e['title']);
            if (!empty($e['description'])) {
                $lines[] = 'DESCRIPTION:' . self::text($e['description']);
            }
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:' . self::text($e['title']);
            $lines[] = 'TRIGGER:-P3D';
            $lines[] = 'END:VALARM';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function text(string $s): string
    {
        $s = str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], $s);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    }

    /** Lines over 75 octets continue on the next line after a space (never inside a UTF-8 character). */
    private static function fold(string $line): string
    {
        $out = '';
        $max = 75;
        while (strlen($line) > $max) {
            $cut = $max;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $max = 74; // the leading space of a continuation line counts toward the 75
        }
        return $out . $line;
    }
}
