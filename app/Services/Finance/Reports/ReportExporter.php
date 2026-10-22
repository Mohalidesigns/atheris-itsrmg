<?php

namespace App\Services\Finance\Reports;

use App\Services\Ea\PdfWriter;

/** Renders a FinanceReports::run() result as CSV, XLSX or PDF (FIN-07.07). */
class ReportExporter
{
    /** @param array<string,mixed> $result */
    public function csv(array $result, string $title): string
    {
        $h = fopen('php://temp', 'r+');
        $cell = fn ($v) => ($s = (string) ($v ?? '')) !== '' && ! is_numeric($s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
        fputcsv($h, array_map($cell, array_column($result['columns'], 'label')), ',', '"', '\\');
        foreach ($this->rowsOf($result) as $row) {
            fputcsv($h, array_map($cell, $row), ',', '"', '\\');
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$csv;
    }

    /** @param array<string,mixed> $result */
    public function xlsx(array $result, string $title): string
    {
        $w = new XlsxWriter;
        $w->addSheet($title, array_column($result['columns'], 'label'), $this->rowsOf($result), array_column($result['columns'], 'type'));

        return $w->render();
    }

    /** @param array<string,mixed> $result */
    public function pdf(array $result, string $title): string
    {
        $pdf = new PdfWriter;
        $pdf->setPageSize(842, 595);
        $pdf->newPage();
        $pdf->heading($title, 16);
        $pdf->space(4);
        $max = (int) config('finance.reports.pdf_max_rows', 1500);
        $rows = $this->rowsOf($result);
        $shown = array_slice($rows, 0, $max);
        $pdf->table(array_column($result['columns'], 'label'), array_map(fn ($r) => array_map(fn ($v) => (string) ($v ?? ''), $r), $shown), 7);
        if (count($rows) > $max || ! empty($result['truncated'])) {
            $pdf->space(4);
            $pdf->paragraph('Truncated: export CSV or XLSX for every row.', 9);
        }

        return $pdf->render();
    }

    /** @return list<list<mixed>> rows (and the totals row) in column order */
    private function rowsOf(array $result): array
    {
        $keys = array_column($result['columns'], 'key');
        $rows = array_map(fn ($r) => array_map(fn ($k) => $r[$k] ?? null, $keys), $result['rows']);
        if (! empty($result['totals'])) {
            $rows[] = array_map(fn ($k) => $result['totals'][$k] ?? null, $keys);
        }

        return $rows;
    }
}
