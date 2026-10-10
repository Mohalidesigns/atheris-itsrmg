<?php

namespace App\Services\DocIntel;

use App\Models\DocIntelligenceJob;
use App\Models\RegulatoryCircular;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document Intelligence extraction (spec §6.4): text layer → structured fields → confidence.
 *
 * The text layer is read directly from the upload (text formats, DOCX XML, PDF content streams). A
 * scanned PDF has no text layer; until an OCR engine (Tesseract / PaddleOCR) is attached it extracts
 * nothing, scores zero confidence, and the reviewer types the fields in. Extraction is deterministic
 * pattern matching rather than an LLM call, so every extracted value can be traced back to the text.
 */
class DocumentExtractionService
{
    /** Fields each document type is expected to yield; confidence is the share actually found. */
    public const FIELDS = [
        'circular' => ['regulator', 'reference', 'title', 'issued_date', 'effective_date', 'summary'],
        'ndpc_decision' => ['regulator', 'reference', 'title', 'respondent', 'issued_date', 'penalty_amount', 'summary'],
        'audit_report' => ['title', 'auditor', 'period', 'findings_total', 'high_findings', 'summary'],
        'vendor_ddq' => ['vendor_name', 'title', 'questions_total', 'answered_yes', 'answered_no', 'summary'],
    ];

    /** Fields a reviewer must have before a job of each type can be accepted. */
    public const REQUIRED = [
        'circular' => ['regulator', 'reference', 'title'],
        'ndpc_decision' => ['regulator', 'reference', 'title'],
        'audit_report' => ['title'],
        'vendor_ddq' => ['vendor_name'],
    ];

    private const REGULATOR_NAMES = [
        'CBN' => ['central bank of nigeria', 'cbn'],
        'NDPC' => ['nigeria data protection commission', 'ndpc', 'ndpb'],
        'SEC' => ['securities and exchange commission', 'sec nigeria'],
        'NCC' => ['nigerian communications commission', 'ncc'],
        'NAICOM' => ['national insurance commission', 'naicom'],
        'PENCOM' => ['national pension commission', 'pencom'],
        'NDIC' => ['nigeria deposit insurance corporation', 'ndic'],
    ];

    /** Run the pipeline for a stored upload and record each stage on the job. */
    public function process(DocIntelligenceJob $job): DocIntelligenceJob
    {
        $text = $this->textLayer($job->source_file, (string) $job->mime_type, (string) $job->original_name);
        $job->update(['ocr_text' => $text, 'status' => 'ocr_done']);

        $fields = $this->extract($job->doc_type, $text);
        $job->update([
            'structured_output' => $fields,
            'confidence' => $this->confidence($job->doc_type, $fields),
            'status' => 'extraction_done',
        ]);

        // Every extraction is human-reviewed before it feeds anything (spec §6.4.6).
        $job->update(['status' => 'awaiting_review']);

        return $job->fresh();
    }

    public function confidence(string $docType, array $fields): float
    {
        $expected = self::FIELDS[$docType] ?? [];
        if ($expected === []) {
            return 0.0;
        }
        $found = count(array_filter($expected, fn ($f) => filled($fields[$f] ?? null)));

        return round($found / count($expected), 4);
    }

    /** @return array<string, string|int|null> */
    public function extract(string $docType, string $text): array
    {
        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

        $fields = array_fill_keys(self::FIELDS[$docType] ?? [], null);

        if (array_key_exists('regulator', $fields)) {
            $fields['regulator'] = $this->regulator($text);
        }
        if (array_key_exists('reference', $fields)) {
            $fields['reference'] = $this->reference($text);
        }
        if (array_key_exists('title', $fields)) {
            $fields['title'] = $this->labelled($text, ['subject', 'title', 're']) ?? $this->title($lines);
        }
        if (array_key_exists('issued_date', $fields)) {
            $fields['issued_date'] = $this->date($this->labelled($text, ['date', 'issued', 'dated']) ?? $text);
        }
        if (array_key_exists('effective_date', $fields)) {
            $label = $this->labelled($text, ['effective date', 'effective from', 'with effect from']);
            $fields['effective_date'] = $label ? $this->date($label) : null;
        }
        if (array_key_exists('summary', $fields)) {
            $fields['summary'] = $this->summary($lines);
        }
        if (array_key_exists('respondent', $fields)) {
            $fields['respondent'] = $this->labelled($text, ['respondent', 'data controller', 'in the matter of']);
        }
        if (array_key_exists('penalty_amount', $fields)) {
            $fields['penalty_amount'] = $this->amount($text);
        }
        if (array_key_exists('auditor', $fields)) {
            $fields['auditor'] = $this->labelled($text, ['auditor', 'prepared by', 'audit firm']);
        }
        if (array_key_exists('period', $fields)) {
            $fields['period'] = $this->labelled($text, ['period', 'audit period', 'period covered']);
        }
        if (array_key_exists('findings_total', $fields)) {
            $findings = preg_match_all('/^\s*(finding|observation)\b/im', $text);
            $fields['findings_total'] = $findings ?: null;
            $high = preg_match_all('/\b(rating|risk|severity)\s*[:\-]\s*(high|critical)\b/i', $text);
            $fields['high_findings'] = $findings ? $high : null;
        }
        if (array_key_exists('vendor_name', $fields)) {
            $fields['vendor_name'] = $this->labelled($text, ['vendor', 'supplier', 'company name', 'service provider']);
        }
        if (array_key_exists('questions_total', $fields)) {
            $yes = preg_match_all('/\b(answer|response)?\s*[:\-]?\s*\byes\b/i', $text);
            $no = preg_match_all('/\b(answer|response)?\s*[:\-]?\s*\bno\b/i', $text);
            $questions = preg_match_all('/^\s*(q\d+|\d+[.)])\s+/im', $text);
            $fields['questions_total'] = $questions ?: null;
            $fields['answered_yes'] = $questions ? $yes : null;
            $fields['answered_no'] = $questions ? $no : null;
        }

        return $fields;
    }

    /** Create the bank's private circular from an accepted circular / NDPC decision extraction. */
    public function toCircular(DocIntelligenceJob $job): RegulatoryCircular
    {
        $f = $job->structured_output ?? [];

        return RegulatoryCircular::create([
            'organization_id' => $job->organization_id,
            'regulator_code' => strtoupper((string) ($f['regulator'] ?? 'CBN')),
            'circular_number' => $f['reference'] ?? null,
            'title' => Str::limit((string) ($f['title'] ?? $job->original_name ?? 'Untitled document'), 250, ''),
            'issued_at' => $f['issued_date'] ?? null,
            'ingested_at' => now(),
            'pdf_path' => $job->source_file,
            'plain_text' => $job->ocr_text,
            'llm_summary' => $f['summary'] ?? null,
            'status' => 'published',
            'doc_intelligence_job_id' => $job->id,
        ]);
    }

    /* ----------------------------------------------------------- text layer */

    public function textLayer(string $path, string $mime, string $name): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return '';
        }
        $raw = (string) $disk->get($path);
        $ext = strtolower(pathinfo($name ?: $path, PATHINFO_EXTENSION));

        $text = match (true) {
            $ext === 'pdf' || str_contains($mime, 'pdf') => $this->pdfText($raw),
            $ext === 'docx' => $this->docxText($disk->path($path)),
            default => $raw,
        };

        return mb_substr(trim((string) $text), 0, 200_000);
    }

    /** Text from PDF content streams (Tj / TJ operators), inflating FlateDecode streams. */
    private function pdfText(string $raw): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m);
        $out = [];
        foreach ($m[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) {
                $data = @gzinflate($stream);
            }
            $data = $data === false ? $stream : $data;
            if (! preg_match('/(Tj|TJ)/', $data)) {
                continue;
            }
            // A line ends at a text-positioning operator: ET, T*, Td, TD or ' (next-line show).
            foreach (preg_split('/\bET\b|T\*|\bT[dD]\b|\'(?=\s)/', $data) as $chunk) {
                preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $chunk, $strings);
                $line = implode('', array_map(fn ($s) => stripcslashes(substr($s, 1, -1)), $strings[0]));
                if (trim($line) !== '') {
                    $out[] = $line;
                }
            }
        }

        return implode("\n", $out);
    }

    private function docxText(string $absolutePath): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? '';

        return html_entity_decode(strip_tags($xml));
    }

    /* ---------------------------------------------------------- field rules */

    private function regulator(string $text): ?string
    {
        $lower = mb_strtolower($text);
        $best = null;
        $bestPos = PHP_INT_MAX;
        foreach (self::REGULATOR_NAMES as $code => $names) {
            foreach ($names as $needle) {
                $pos = preg_match('/\b'.preg_quote($needle, '/').'\b/', $lower, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
                if ($pos !== false && $pos < $bestPos) {
                    $best = $code;
                    $bestPos = $pos;
                }
            }
        }

        return $best;
    }

    /** Regulator reference numbers: BSD/DIR/GEN/LAB/14/028, NDPC/CIR/2026/001, Ref: ... */
    private function reference(string $text): ?string
    {
        if ($labelled = $this->labelled($text, ['ref', 'ref no', 'reference', 'our ref', 'circular no'])) {
            if (preg_match('/[A-Z0-9][A-Z0-9.\-]*(?:\/[A-Z0-9.\-]+){2,}/i', $labelled, $m)) {
                return strtoupper($m[0]);
            }
        }
        if (preg_match('/\b[A-Z]{2,6}(?:\/[A-Z0-9.\-]{1,12}){2,7}\b/', $text, $m)) {
            return $m[0];
        }

        return null;
    }

    /** The value after "Label:" on the same line. */
    private function labelled(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/^\s*'.preg_quote($label, '/').'\.?\s*[:\-–]\s*(.+)$/im', $text, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return Str::limit($value, 250, '');
                }
            }
        }

        return null;
    }

    private function title(array $lines): ?string
    {
        foreach ($lines as $line) {
            $words = str_word_count($line);
            if ($words >= 3 && $words <= 25 && ! preg_match('/^(ref|date|to|from)\b/i', $line)) {
                return Str::limit($line, 250, '');
            }
        }

        return null;
    }

    private function date(string $text): ?string
    {
        $patterns = [
            '/\b(\d{4}-\d{2}-\d{2})\b/',
            '/\b(\d{1,2}(?:st|nd|rd|th)?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*,?\s+\d{4})\b/i',
            '/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})\b/i',
            '/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                try {
                    $value = preg_replace('/(\d)(st|nd|rd|th)/i', '$1', $m[1]);
                    // Nigerian documents write dd/mm/yyyy.
                    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $d)) {
                        $value = "{$d[3]}-{$d[2]}-{$d[1]}";
                    }

                    return Carbon::parse($value)->toDateString();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function amount(string $text): ?int
    {
        if (preg_match('/(?:₦|NGN|N)\s?([\d,]{4,}(?:\.\d{2})?)/u', $text, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }

        return null;
    }

    private function summary(array $lines): ?string
    {
        // Body sentences only: skip "Label: value" header lines and Q&A rows.
        $body = collect($lines)
            ->reject(fn ($l) => preg_match('/^[A-Za-z][A-Za-z .]{0,24}:\s/', $l) || preg_match('/^(q\d+|\d+[.)])\s/i', $l))
            ->filter(fn ($l) => str_word_count($l) >= 8)->take(3)->implode(' ');

        return $body !== '' ? Str::limit($body, 600) : null;
    }
}
