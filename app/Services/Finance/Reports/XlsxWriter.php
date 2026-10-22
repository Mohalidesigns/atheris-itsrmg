<?php

namespace App\Services\Finance\Reports;

use ZipArchive;

/** A small dependency-free XLSX writer over ZipArchive: inline strings, numeric values, a bold header (plan P4-10, C106). Report exports only. */
class XlsxWriter
{
    /** @var list<array{name:string,header:array,rows:array,types:array}> */
    private array $sheets = [];

    /** @param list<string> $header @param iterable<int, array<int,mixed>> $rows @param list<string> $types per column: text, money, pct, int, date */
    public function addSheet(string $name, array $header, iterable $rows, array $types): void
    {
        $this->sheets[] = ['name' => $this->sheetName($name, count($this->sheets) + 1), 'header' => $header, 'rows' => is_array($rows) ? $rows : iterator_to_array($rows, false), 'types' => $types];
    }

    public function render(): string
    {
        class_exists(ZipArchive::class) || abort(422, 'XLSX is not available on this server.');
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true || abort(422, 'XLSX is not available on this server.');
        $n = count($this->sheets);
        $overrides = '';
        $sheetsXml = '';
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$k.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetsXml .= '<sheet name="'.$this->esc($s['name']).'" sheetId="'.$k.'" r:id="rId'.$k.'"/>';
            $rels .= '<Relationship Id="rId'.$k.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$k.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$k.'.xml', $this->sheetXml($s));
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetsXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $head.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function sheetXml(array $s): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $x .= '<row r="1">';
        foreach ($s['header'] as $c => $h) {
            $x .= '<c r="'.$this->col($c).'1" s="1" t="inlineStr"><is><t xml:space="preserve">'.$this->esc((string) $h).'</t></is></c>';
        }
        $x .= '</row>';
        foreach ($s['rows'] as $r => $row) {
            $rn = $r + 2;
            $x .= '<row r="'.$rn.'">';
            foreach (array_values($row) as $c => $v) {
                $ref = $this->col($c).$rn;
                $type = $s['types'][$c] ?? 'text';
                if ($v === null || $v === '') {
                    continue;
                }
                if (in_array($type, ['money', 'pct', 'int'], true) && is_numeric($v)) {
                    $x .= '<c r="'.$ref.'"'.($type === 'money' ? ' s="2"' : '').'><v>'.$this->esc((string) $v).'</v></c>';
                } else {
                    $x .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->esc((string) $v).'</t></is></c>';
                }
            }
            $x .= '</row>';
        }

        return $x.'</sheetData></worksheet>';
    }

    private function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private function esc(string $v): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheetName(string $name, int $n): string
    {
        $clean = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name) ?? '');

        return mb_substr($clean !== '' ? $clean : "Sheet{$n}", 0, 31);
    }
}
