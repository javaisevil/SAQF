<?php
declare(strict_types=1);

namespace Saqf\Web;

use InvalidArgumentException;

/**
 * QR code generator (ISO/IEC 18004: byte mode, error correction level M, versions 1–10, all eight
 * masks scored) rendered as inline SVG. Used for the authenticator-app setup code; no library or
 * external service is involved, so the secret never leaves the server.
 */
final class Qr
{
    /** version => [EC codewords per block, [[blocks, data codewords per block], …]] for level M */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    private int $size;
    private array $m = [];
    private array $fn = [];

    /** @return list<list<bool>> modules (true = dark) */
    public static function matrix(string $text): array
    {
        $q = new self();
        return $q->encode($text);
    }

    public static function svg(string $text, int $scale = 5, string $label = 'QR code'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $q = 4; // quiet zone
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $q) . ',' . ($y + $q) . 'h1v1h-1z';
                }
            }
        }
        $w = ($n + 2 * $q) * $scale;
        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" width="' . $w . '" height="' . $w . '" viewBox="0 0 ' . ($n + 2 * $q) . ' ' . ($n + 2 * $q) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private function encode(string $text): array
    {
        $len = strlen($text);
        $version = null;
        foreach (self::BLOCKS as $v => [$ec, $groups]) {
            $data = array_sum(array_map(static fn($g) => $g[0] * $g[1], $groups));
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $data * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('Text too long for a version 10 QR code.');
        }
        [$ecLen, $groups] = self::BLOCKS[$version];
        $capacity = array_sum(array_map(static fn($g) => $g[0] * $g[1], $groups));

        // Bit stream: byte mode, length, data, terminator, padding.
        $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $codewords = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($codewords) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        // Error correction per block, then interleave.
        $blocks = [];
        $ecBlocks = [];
        $divisor = self::rsDivisor($ecLen);
        $k = 0;
        foreach ($groups as [$count, $dataLen]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($codewords, $k, $dataLen);
                $k += $dataLen;
                $blocks[] = $block;
                $ecBlocks[] = self::rsRemainder($block, $divisor);
            }
        }
        $final = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $final[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $ecb) {
                $final[] = $ecb[$i];
            }
        }

        $this->size = 17 + 4 * $version;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->m;
        $this->functionPatterns($version);
        $this->placeData($final);

        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->formatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
            $this->applyMask($mask); // undo (XOR)
        }
        $this->applyMask($best);
        $this->formatBits($best);
        return $this->m;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function functionPatterns(int $version): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$version];
        $c = count($pos);
        for ($i = 0; $i < $c; $i++) {
            for ($j = 0; $j < $c; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $c - 1) || ($i === $c - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->formatBits(0); // reserve
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function formatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i) => (($bits >> $i) & 1) === 1;
        $n = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true);
    }

    private function placeData(array $codewords): void
    {
        $n = $this->size;
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                switch ($mask) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                    case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    default: $inv = ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0;
                }
                if ($inv) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** ISO penalty rules N1–N4. */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $n; $j++) {
                $row .= $this->m[$i][$j] ? '1' : '0';
                $col .= $this->m[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
            $dark += substr_count($row, '1');
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $mm)) {
                foreach ($mm[0] as $run) {
                    $score += 3 + strlen($run) - 5;
                }
            }
            $score += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $line));
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->m[$y][$x];
                if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        return $score + 10 * intdiv((int) abs($dark * 100 / ($n * $n) - 50), 5);
    }

    private static function rsMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::rsMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::rsMultiply($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ $result[0];
            array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $d) {
                $result[$i] ^= self::rsMultiply($d, $factor);
            }
        }
        return $result;
    }
}
