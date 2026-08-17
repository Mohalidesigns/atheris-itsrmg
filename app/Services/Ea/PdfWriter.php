<?php

namespace App\Services\Ea;

/**
 * PdfWriter — minimal PDF 1.4 writer implemented in pure PHP. The output is a
 * valid PDF with multiple pages, headings, paragraphs, key/value lines and
 * simple tables. Used by {@see EvidencePackGenerator} to avoid pulling in
 * `dompdf` or `wkhtmltopdf` for the regulator-facing evidence pack.
 *
 * It is deliberately small in scope: Helvetica only, single column, manual line
 * wrapping. That is sufficient for the CBN EA evidence pack and any other
 * narrative + table artefact NexusRisk needs to emit.
 */
class PdfWriter
{
    private array $pages = [];
    private array $currentLines = [];
    private float $cursorY = 0;
    private float $pageWidth = 595.0;   // A4 portrait
    private float $pageHeight = 842.0;
    private float $marginX = 50.0;
    private float $marginY = 50.0;
    private float $lineHeight = 14.0;

    public function newPage(): void
    {
        if (!empty($this->currentLines)) {
            $this->pages[] = $this->currentLines;
            $this->currentLines = [];
        }
        $this->cursorY = $this->pageHeight - $this->marginY;
    }

    /**
     * Resize the page. Added for the WS 4.1 diagram export, which needs
     * landscape and a canvas-shaped MediaBox rather than A4 portrait.
     */
    public function setPageSize(float $width, float $height): void
    {
        $this->pageWidth = $width;
        $this->pageHeight = $height;
        $this->cursorY = $this->pageHeight - $this->marginY;
    }

    /** Absolute-positioned text, bypassing the flow cursor. */
    public function textAt(float $x, float $y, string $text, int $size = 9, bool $bold = false, ?array $rgb = null): void
    {
        $this->currentLines[] = ['text', $x, $y, $size, $this->escape($text), $bold ? 'F1' : 'F2', $rgb];
    }

    /**
     * A filled and/or stroked rectangle — the diagram element box.
     *
     * @param  array{0:float,1:float,2:float}|null  $fill    RGB 0–1
     * @param  array{0:float,1:float,2:float}|null  $stroke  RGB 0–1
     */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.8): void
    {
        $this->currentLines[] = ['rect', $x, $y, $w, $h, $fill, $stroke, $lineWidth];
    }

    /** An absolute-positioned line, optionally coloured — a diagram edge. */
    public function lineAt(float $x1, float $y1, float $x2, float $y2, ?array $rgb = null, float $lineWidth = 0.7): void
    {
        $this->currentLines[] = ['line', $x1, $y1, $x2, $y2, $rgb, $lineWidth];
    }

    public function heading(string $text, int $size = 16): void
    {
        $this->ensureRoom($size + 8);
        $this->currentLines[] = ['text', $this->marginX, $this->cursorY, $size, $this->escape($text), 'F1'];
        $this->cursorY -= $size + 8;
    }

    public function paragraph(string $text, int $size = 10): void
    {
        $wrap = $this->wrapText($text, $size, $this->pageWidth - 2 * $this->marginX);
        foreach ($wrap as $line) {
            $this->ensureRoom($this->lineHeight);
            $this->currentLines[] = ['text', $this->marginX, $this->cursorY, $size, $this->escape($line), 'F2'];
            $this->cursorY -= $this->lineHeight;
        }
        $this->cursorY -= 4;
    }

    public function kv(string $key, string $value, int $size = 10): void
    {
        $this->ensureRoom($this->lineHeight);
        $this->currentLines[] = ['text', $this->marginX, $this->cursorY, $size, $this->escape($key.': '), 'F1'];
        $this->currentLines[] = ['text', $this->marginX + 120, $this->cursorY, $size, $this->escape($value), 'F2'];
        $this->cursorY -= $this->lineHeight;
    }

    public function table(array $headers, array $rows, int $size = 9): void
    {
        $colCount = count($headers);
        if ($colCount === 0) return;
        $usable = $this->pageWidth - 2 * $this->marginX;
        $colWidth = $usable / $colCount;
        // header
        $this->ensureRoom($this->lineHeight + 4);
        foreach ($headers as $i => $h) {
            $this->currentLines[] = ['text', $this->marginX + $i * $colWidth, $this->cursorY, $size, $this->escape($h), 'F1'];
        }
        $this->cursorY -= $this->lineHeight;
        $this->currentLines[] = ['line', $this->marginX, $this->cursorY + 4, $this->pageWidth - $this->marginX, $this->cursorY + 4];
        foreach ($rows as $row) {
            $this->ensureRoom($this->lineHeight);
            foreach ($row as $i => $cell) {
                $cellStr = is_scalar($cell) ? (string) $cell : '';
                $this->currentLines[] = ['text', $this->marginX + $i * $colWidth, $this->cursorY, $size, $this->escape(substr($cellStr, 0, 36)), 'F2'];
            }
            $this->cursorY -= $this->lineHeight;
        }
        $this->cursorY -= 4;
    }

    public function rule(): void
    {
        $this->ensureRoom(6);
        $this->currentLines[] = ['line', $this->marginX, $this->cursorY, $this->pageWidth - $this->marginX, $this->cursorY];
        $this->cursorY -= 6;
    }

    public function space(float $units = 8.0): void
    {
        $this->cursorY -= $units;
    }

    public function render(): string
    {
        if (!empty($this->currentLines)) {
            $this->pages[] = $this->currentLines;
            $this->currentLines = [];
        }
        if (empty($this->pages)) {
            $this->pages = [[]];
        }
        return $this->compile();
    }

    private function ensureRoom(float $units): void
    {
        if ($this->cursorY - $units < $this->marginY) {
            $this->pages[] = $this->currentLines;
            $this->currentLines = [];
            $this->cursorY = $this->pageHeight - $this->marginY;
        }
    }

    private function wrapText(string $text, int $size, float $maxWidth): array
    {
        $charsPerLine = (int) max(20, floor($maxWidth / ($size * 0.45)));
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $current = '';
        foreach ($words as $w) {
            if ($current === '') {
                $current = $w;
            } elseif (strlen($current) + 1 + strlen($w) <= $charsPerLine) {
                $current .= ' '.$w;
            } else {
                $lines[] = $current;
                $current = $w;
            }
        }
        if ($current !== '') $lines[] = $current;
        return $lines;
    }

    private function escape(string $text): string
    {
        // PDF strings escape backslash, parens; render non-ASCII safely.
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    private function compile(): string
    {
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $pageRefs = [];
        $pageObjects = [];
        $contentObjects = [];
        $startObj = 4; // 1 catalog, 2 pages, 3 fonts (we'll use 3+4 for fonts)

        // Fonts at obj 3 and obj 4
        $fontRegular = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $fontBold = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objects[3] = $fontBold;   // F1
        $objects[4] = $fontRegular;// F2

        $nextObj = 5;
        foreach ($this->pages as $cmds) {
            $contentStream = '';
            foreach ($cmds as $c) {
                if ($c[0] === 'text') {
                    [, $x, $y, $size, $escaped, $font] = $c;
                    $rgb = $c[6] ?? null;
                    $colour = $rgb ? sprintf('%.3f %.3f %.3f rg ', $rgb[0], $rgb[1], $rgb[2]) : '0 0 0 rg ';
                    $contentStream .= "BT {$colour}/{$font} {$size} Tf {$x} {$y} Td ({$escaped}) Tj ET\n";
                } elseif ($c[0] === 'line') {
                    [, $x1, $y1, $x2, $y2] = $c;
                    $rgb = $c[5] ?? null;
                    $width = $c[6] ?? 0.7;
                    $contentStream .= sprintf("q %.2f w %.3f %.3f %.3f RG %s %s m %s %s l S Q\n",
                        $width,
                        $rgb[0] ?? 0.55, $rgb[1] ?? 0.58, $rgb[2] ?? 0.64,
                        $x1, $y1, $x2, $y2);
                } elseif ($c[0] === 'rect') {
                    [, $x, $y, $w, $h, $fill, $stroke, $width] = $c;
                    $ops = 'q ';
                    if ($fill) {
                        $ops .= sprintf('%.3f %.3f %.3f rg ', $fill[0], $fill[1], $fill[2]);
                    }
                    if ($stroke) {
                        $ops .= sprintf('%.2f w %.3f %.3f %.3f RG ', $width, $stroke[0], $stroke[1], $stroke[2]);
                    }
                    $paint = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
                    $contentStream .= $ops."{$x} {$y} {$w} {$h} re {$paint} Q\n";
                }
            }
            $contentObj = $nextObj++;
            $objects[$contentObj] = "<< /Length ".strlen($contentStream)." >>\nstream\n".$contentStream."endstream";

            $pageObj = $nextObj++;
            $resources = "<< /Font << /F1 3 0 R /F2 4 0 R >> /ProcSet [/PDF /Text] >>";
            $box = sprintf('[0 0 %d %d]', (int) round($this->pageWidth), (int) round($this->pageHeight));
            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox $box /Resources $resources /Contents $contentObj 0 R >>";
            $pageRefs[] = "$pageObj 0 R";
        }
        $objects[2] = "<< /Type /Pages /Count ".count($pageRefs)." /Kids [".implode(' ', $pageRefs)."] >>";

        // Build the PDF byte-stream with offsets for the xref table.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$body\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";
        return $pdf;
    }
}
