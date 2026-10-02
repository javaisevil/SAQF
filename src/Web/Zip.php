<?php
declare(strict_types=1);

namespace Saqf\Web;

/** Minimal ZIP writer (deflate, UTF-8 names) for generated Office files; no PHP zip extension needed. */
final class Zip
{
    private array $entries = [];

    public function add(string $name, string $data): void
    {
        $this->entries[] = [$name, $data];
    }

    public function bytes(): string
    {
        [$time, $date] = self::dosTime(time());
        $out = '';
        $central = '';
        foreach ($this->entries as [$name, $data]) {
            $crc = crc32($data);
            $deflated = (string) gzdeflate($data, 6);
            $offset = strlen($out);
            $common = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($data), strlen($name), 0);
            $out .= pack('V', 0x04034b50) . $common . $name . $deflated;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        $n = count($this->entries);
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
    }

    /** @return array{0:int,1:int} DOS time and date */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
