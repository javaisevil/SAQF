<?php
declare(strict_types=1);

namespace Saqf\Demo;

/** One-page PDF documents for the demo's evidence files (exam papers, rubrics). ASCII text only. */
final class SamplePdf
{
    /** @param list<string> $lines */
    public static function make(string $title, array $lines): string
    {
        $esc = static fn(string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) preg_replace('/[^\x20-\x7E]/', '-', $s));
        $text = "BT /F1 16 Tf 56 790 Td (" . $esc($title) . ") Tj ET\n";
        $y = 760;
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line, 92, "\n", true)) as $part) {
                $text .= "BT /F1 10 Tf 56 $y Td (" . $esc($part) . ") Tj ET\n";
                $y -= 15;
                if ($y < 60) {
                    break 2;
                }
            }
            $y -= 4;
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
