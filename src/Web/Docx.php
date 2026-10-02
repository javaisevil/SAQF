<?php
declare(strict_types=1);

namespace Saqf\Web;

/**
 * Small Word (.docx) builder for SAQF's generated documents: headings, paragraphs, key-value
 * tables and data tables on A4, with right-to-left layout for Arabic documents. Output opens in
 * Microsoft Word, LibreOffice and Google Docs; no external library is used.
 */
final class Docx
{
    private string $body = '';

    public function __construct(private string $title, private bool $rtl = false)
    {
    }

    public function heading(string $text, int $level = 1): self
    {
        $this->body .= $this->para($text, ['style' => $level === 0 ? 'Title' : 'Heading' . min(3, $level)]);
        return $this;
    }

    public function paragraph(string $text, array $opts = []): self
    {
        $this->body .= $this->para($text, $opts);
        return $this;
    }

    /** Two-column label/value table. @param array<string,string> $pairs */
    public function fields(array $pairs): self
    {
        $rows = [];
        foreach ($pairs as $k => $v) {
            $rows[] = [$k, $v];
        }
        return $this->table([], $rows, [30, 70], true);
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @param list<int> $widths percentages
     */
    public function table(array $header, array $rows, array $widths = [], bool $labelColumn = false): self
    {
        $cols = max(count($header), $rows ? max(array_map('count', $rows)) : 0);
        if ($cols === 0) {
            return $this;
        }
        $widths = $widths ?: array_fill(0, $cols, intdiv(100, $cols));
        $total = 9638; // A4 text width in twentieths of a point (2 cm margins)
        $tw = array_map(static fn($p) => (int) round($total * $p / 100), $widths);
        $x = '<w:tbl><w:tblPr><w:tblStyle w:val="SaqfTable"/><w:tblW w:w="5000" w:type="pct"/>' . ($this->rtl ? '<w:bidiVisual/>' : '') . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="0" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr><w:tblGrid>';
        foreach ($tw as $w) {
            $x .= '<w:gridCol w:w="' . $w . '"/>';
        }
        $x .= '</w:tblGrid>';
        if ($header) {
            $x .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($header as $i => $h) {
                $x .= $this->cell((string) $h, $tw[$i] ?? 1000, 'DCE6F2', true);
            }
            $x .= '</w:tr>';
        }
        foreach ($rows as $r) {
            $x .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            for ($i = 0; $i < $cols; $i++) {
                $x .= $this->cell((string) ($r[$i] ?? ''), $tw[$i] ?? 1000, $labelColumn && $i === 0 ? 'F2F4F7' : null, $labelColumn && $i === 0);
            }
            $x .= '</w:tr>';
        }
        $this->body .= $x . '</w:tbl>' . $this->para('', ['spacing' => 60]);
        return $this;
    }

    public function bytes(): string
    {
        $zip = new Zip();
        $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $zip->add('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($this->title) . '</dc:title><dc:creator>SAQF</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>SAQF</Application></Properties>');
        $zip->add('word/styles.xml', $this->styles());
        $zip->add('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $this->body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>' . ($this->rtl ? '<w:bidi/>' : '') . '</w:sectPr></w:body></w:document>');
        return $zip->bytes();
    }

    /** Sends the document as a download. */
    public function send(string $filename): void
    {
        $bytes = $this->bytes();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, no-store');
        echo $bytes;
    }

    private function para(string $text, array $o = []): string
    {
        // In an Arabic document, text without Arabic letters (course content in English, codes, numbers)
        // stays left-to-right so its punctuation and brackets are not reordered.
        $rtl = $this->rtl && ($text === '' || preg_match('/\p{Arabic}/u', $text));
        $ppr = (isset($o['style']) ? '<w:pStyle w:val="' . $o['style'] . '"/>' : '')
            . (isset($o['spacing']) ? '<w:spacing w:after="' . (int) $o['spacing'] . '"/>' : '')
            . ($rtl ? '<w:bidi/>' : ($this->rtl ? '<w:jc w:val="left"/>' : ''));
        $rpr = (!empty($o['bold']) ? '<w:b/>' : '') . (!empty($o['italic']) ? '<w:i/>' : '') . (!empty($o['small']) ? '<w:sz w:val="16"/>' : '')
            . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '') . ($rtl ? '<w:rtl/>' : '');
        $runs = [];
        foreach (explode("\n", $text) as $line) {
            $runs[] = '<w:t xml:space="preserve">' . self::x($line) . '</w:t>';
        }
        return '<w:p>' . ($ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . '<w:r>' . ($rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . implode('<w:br/>', $runs) . '</w:r></w:p>';
    }

    private function cell(string $text, int $width, ?string $fill, bool $bold): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . ($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '</w:tcPr>'
            . $this->para($text, ['bold' => $bold, 'spacing' => 0]) . '</w:tc>';
    }

    private function styles(): string
    {
        $font = $this->rtl ? 'Arial' : 'Calibri';
        $h = static fn(string $id, string $name, int $size, string $color, int $before) => '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="' . $before . '" w:after="120"/></w:pPr><w:rPr><w:b/><w:color w:val="' . $color . '"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:style>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-GB" w:bidi="ar-SA"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $h('Title', 'Title', 32, '1F3864', 0) . $h('Heading1', 'heading 1', 26, '1F3864', 240) . $h('Heading2', 'heading 2', 22, '2E5597', 200) . $h('Heading3', 'heading 3', 20, '404040', 160)
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '<w:style w:type="table" w:styleId="SaqfTable"><w:name w:val="SAQF Table"/><w:basedOn w:val="TableNormal"/><w:tblPr><w:tblBorders>'
            . implode('', array_map(static fn($b) => '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="A6AEBB"/>', ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
            . '</w:tblBorders><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '</w:styles>';
    }

    private static function x(string $s): string
    {
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
