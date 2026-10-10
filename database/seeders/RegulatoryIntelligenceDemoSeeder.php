<?php

namespace Database\Seeders;

use App\Models\CircularAssessment;
use App\Models\CircularImpact;
use App\Models\Control;
use App\Models\DocIntelligenceJob;
use App\Models\Obligation;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\RegulatoryCircular;
use App\Models\ReturnRun;
use App\Models\Returns\RegulatoryReturn;
use App\Models\ReturnTemplate;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DocIntel\DocumentExtractionService;
use App\Services\Returns\ReturnCompiler;
use App\Services\ReturnsCentre\ReturnRunPopulator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Regulatory Intelligence demo estate (ATH-VAL-004), for both bank tenants:
 *
 * - circular triage (Unread / Assessed / Actioned / Not applicable) with impacts linked to each
 *   bank's own obligations, controls, policies and risks, plus platform circulars still in
 *   Content-Ops editorial review that tenants must not see;
 * - Document Intelligence jobs from real uploaded files (PDF, DOCX, text) run through the extractor,
 *   in every review state, including a scanned PDF with no text layer;
 * - Returns Centre runs populated from each bank's register, across the whole lifecycle;
 * - First Bank's architecture-compiled returns (it holds the EA repository), with a prior period so
 *   the period-on-period diff has something to compare.
 *
 * Runs after the EA stewardship seeder so the compiled returns capture real seal states.
 */
class RegulatoryIntelligenceDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['admin' => 'admin@kanoheritage.ng', 'preparer' => 'compliance@kanoheritage.ng', 'prefix' => 'KHB'],
        'first-bank-nigeria' => ['admin' => 'ciso@firstbanknigeria.ng', 'preparer' => 'compliance@firstbanknigeria.ng', 'prefix' => 'FBN'],
    ];

    public function run(): void
    {
        $this->editorialCirculars();

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            $preparer = User::where('email', $bank['preparer'])->first() ?? $admin;
            if (! $org || ! $admin) {
                continue;
            }

            // Tenant scopes and creating-hooks follow the signed-in user, exactly as in a request.
            Auth::setUser($admin);

            $this->triage($org, $admin, $preparer);
            $this->documents($org, $admin, $preparer, $bank['prefix']);
            $this->returnRuns($org, $admin, $preparer, $bank['prefix']);
            if ($slug === 'first-bank-nigeria') {
                $this->architectureReturns($admin);
            }
        }

        Auth::forgetUser();
    }

    /** Platform circulars Content-Ops has not released: visible to Super Admin only. */
    private function editorialCirculars(): void
    {
        $items = [
            ['CBN', 'BSD/DIR/PUB/LAB/019/011', 'Exposure draft: Guidelines on the use of AI in banking operations', 'editorial_review'],
            ['NDPC', 'NDPC/GAID/2026/004', 'Draft guidance on biometric data processing', 'impact_pending'],
        ];
        foreach ($items as [$reg, $num, $title, $status]) {
            RegulatoryCircular::withoutGlobalScopes()->firstOrCreate(
                ['regulator_code' => $reg, 'circular_number' => $num, 'organization_id' => null],
                [
                    'title' => $title,
                    'issued_at' => now()->subDays(2),
                    'ingested_at' => now()->subDays(1),
                    'source_url' => 'https://example.org/'.$reg.'/'.Str::slug($num),
                    'plain_text' => "{$title}. Draft circular pending Content-Ops editorial review.",
                    'llm_summary' => "Draft summary of {$reg} {$num} awaiting editorial approval.",
                    'status' => $status,
                ]
            );
        }
    }

    /* ------------------------------------------------------------------ triage */

    private function triage(Organization $org, User $admin, User $preparer): void
    {
        if (CircularAssessment::where('organization_id', $org->id)->exists()) {
            return;
        }

        $circulars = RegulatoryCircular::query()->whereNull('organization_id')
            ->whereIn('status', RegulatoryCircular::TENANT_VISIBLE)->orderByDesc('issued_at')->get()->values();

        // [status, applicability, severity, due offset days, notes, impact keywords]
        $plan = [
            ['actioned', 'applicable', 'high', -10, 'Gap assessed against the incident response standard; procedures updated and the board risk committee informed.', ['incident', 'cyber', 'security']],
            ['assessed', 'applicable', 'high', 21, 'Applies to our NDPC breach-reporting process; DPCO to update the breach form template.', ['breach', 'data', 'privacy']],
            ['actioned', 'partially_applicable', 'medium', -30, 'Only the NIP settlement window changes affect us; operations runbook updated.', ['payment', 'transfer', 'settlement']],
            ['assessed', 'applicable', 'medium', -5, 'BVN linkage checks to be added to onboarding controls — overdue with Retail Operations.', ['customer', 'identity', 'access']],
            ['not_applicable', 'not_applicable', 'low', null, 'NCC licensing applies to telecom operators; we hold no NCC licence.', []],
            ['actioned', 'applicable', 'medium', -45, 'Cross-border transfer register reviewed; two transfers moved to adequacy-based mechanisms.', ['data', 'transfer', 'vendor']],
            ['assessed', 'partially_applicable', 'low', 40, 'Disclosure expectations noted for our listed holding company; investor relations to confirm.', ['disclosure', 'reporting']],
        ];

        foreach ($plan as $i => [$status, $applicability, $severity, $due, $notes, $keywords]) {
            $circular = $circulars[$i] ?? null;
            if (! $circular) {
                break;
            }

            CircularAssessment::create([
                'organization_id' => $org->id,
                'circular_id' => $circular->id,
                'status' => $status,
                'applicability' => $applicability,
                'severity' => $severity,
                'owner_id' => $status === 'not_applicable' ? null : $preparer->id,
                'due_date' => $due !== null ? today()->addDays($due) : null,
                'notes' => $notes,
                'assessed_by_id' => $preparer->id,
                'assessed_at' => now()->subDays(12 - $i),
                'actioned_at' => $status === 'actioned' ? now()->subDays(6 - min($i, 5)) : null,
            ]);

            if ($status === 'not_applicable') {
                continue;
            }

            foreach ($this->impactTargets($keywords, $status === 'actioned' ? 3 : 2) as [$type, $id, $rationale]) {
                CircularImpact::create([
                    'organization_id' => $org->id,
                    'circular_id' => $circular->id,
                    'impacted_type' => $type,
                    'impacted_id' => $id,
                    'impact_score' => [4.0, 3.0, 2.5][$i % 3],
                    'rationale' => $rationale,
                    'action_required' => $status === 'actioned' ? 'Completed' : 'Update to reflect the circular',
                ]);
            }
        }
    }

    /** Records in the bank's register that a circular about $keywords plausibly touches. */
    private function impactTargets(array $keywords, int $limit): array
    {
        $targets = [];
        $sources = [
            'obligation' => [Obligation::class, 'title', 'Reporting obligation changed by the circular.'],
            'control' => [Control::class, 'title', 'Control design must reflect the new requirement.'],
            'policy' => [Policy::class, 'title', 'Policy wording must be updated for the circular.'],
            'risk' => [Risk::class, 'title', 'Risk rating revisited in light of the circular.'],
        ];

        foreach ($sources as $type => [$model, $column, $rationale]) {
            if (count($targets) >= $limit) {
                break;
            }
            $record = $model::query()
                ->where(fn ($q) => collect($keywords)->each(fn ($k) => $q->orWhere($column, 'like', "%{$k}%")))
                ->orderBy('id')->first() ?? $model::query()->orderBy('id')->first();
            if ($record) {
                $targets[] = [$type, $record->id, $rationale];
            }
        }

        return $targets;
    }

    /* --------------------------------------------------------------- documents */

    private function documents(Organization $org, User $admin, User $preparer, string $prefix): void
    {
        if (DocIntelligenceJob::where('organization_id', $org->id)->whereNotNull('original_name')->exists()) {
            return;
        }

        $extractor = app(DocumentExtractionService::class);
        $vendor = Vendor::orderBy('id')->first();
        $docs = $this->demoDocuments($org->name, $vendor?->name ?? 'Interswitch Group');

        foreach ($docs as $i => $doc) {
            $path = "doc-intel/{$org->id}/demo-".Str::slug($doc['name']).'.'.$doc['ext'];
            Storage::disk('local')->put($path, $doc['content']);

            $job = DocIntelligenceJob::create([
                'organization_id' => $org->id,
                'doc_type' => $doc['type'],
                'source_file' => $path,
                'original_name' => $doc['name'].'.'.$doc['ext'],
                'mime_type' => $doc['mime'],
                'size_bytes' => strlen($doc['content']),
                'status' => 'uploaded',
                'uploaded_by_id' => $preparer->id,
            ]);
            $job = $extractor->process($job);
            $job->forceFill(['created_at' => now()->subDays(count($docs) - $i)])->save();

            if ($doc['outcome'] === 'accepted') {
                $attributes = ['status' => 'accepted', 'reviewed_by_id' => $admin->id, 'reviewed_at' => now()->subDays(1),
                    'review_notes' => 'Fields checked against the source document.'];
                if (in_array($job->doc_type, ['circular', 'ndpc_decision'], true)) {
                    $attributes['circular_id'] = $extractor->toCircular($job)->id;
                }
                if ($job->doc_type === 'vendor_ddq' && $vendor) {
                    $vendor->update(['last_assessed' => today()->subDay()]);
                    $attributes['vendor_id'] = $vendor->id;
                }
                $job->update($attributes);
            } elseif ($doc['outcome'] === 'rejected') {
                $job->update(['status' => 'rejected', 'reviewed_by_id' => $admin->id, 'reviewed_at' => now()->subDays(2),
                    'review_notes' => 'Superseded draft — the final report was uploaded separately.']);
            }
        }
    }

    private function demoDocuments(string $bank, string $vendor): array
    {
        $circular = "CENTRAL BANK OF NIGERIA\nBanking Supervision Department\nRef: BSD/DIR/PUB/LAB/019/004\nDate: 14 September 2026\n"
            ."To: All Deposit Money Banks\nSubject: Guidelines on Cloud Computing Risk Management for Deposit Money Banks\n"
            ."Effective date: 1 January 2027\n"
            ."The Central Bank of Nigeria issues these guidelines to strengthen the management of risks arising from the adoption of cloud services by deposit money banks.\n"
            ."Banks shall maintain a register of all cloud service providers and obtain prior approval before migrating critical workloads to a public cloud.\n"
            ."Banks shall ensure that customer data hosted in the cloud remains within Nigeria unless an exemption has been granted in writing.\n";

        $ndpc = "NIGERIA DATA PROTECTION COMMISSION\nReference: NDPC/ENF/2026/017\nDate: 3 August 2026\n"
            ."Title: Final Order in the matter of unauthorised disclosure of customer records\n"
            ."Respondent: Lagos Microfinance Bank Limited\n"
            ."The Commission found that the data controller failed to implement appropriate technical and organisational measures to secure personal data of its customers.\n"
            ."The Respondent is directed to pay a remedial fine of NGN 25,000,000 and to complete a compliance audit within ninety days.\n";

        $audit = "Internal Audit Report\nTitle: IT General Controls Review {$bank}\nPrepared by: Group Internal Audit\n"
            // No "Period:" label, so the period field is not found and the extraction lands below the
            // 90% review threshold — the reviewer must confirm every field.
            ."This report summarises the review of IT general controls over core banking and channels for January to June 2026.\n"
            ."Finding 1 - Privileged access reviews not performed quarterly. Rating: High\n"
            ."Finding 2 - Backup restoration tests not evidenced for two systems. Rating: Medium\n"
            ."Finding 3 - Change approvals recorded after deployment for emergency changes. Rating: High\n"
            ."Finding 4 - Dormant user accounts not disabled within 90 days. Rating: Low\n";

        $ddq = "Vendor Due Diligence Questionnaire\nVendor: {$vendor}\nTitle: Annual third-party security questionnaire 2026\n"
            ."The service provider completed the annual security questionnaire covering governance, access control and resilience.\n"
            ."Q1 Do you hold a current ISO 27001 certificate? Answer: Yes\nQ2 Is customer data encrypted at rest? Answer: Yes\n"
            ."Q3 Do you perform annual penetration tests? Answer: Yes\nQ4 Do you subcontract processing outside Nigeria? Answer: No\n"
            ."Q5 Have you had a reportable breach in the last 12 months? Answer: No\n";

        return [
            ['type' => 'circular', 'name' => 'CBN cloud computing risk guidelines', 'ext' => 'pdf', 'mime' => 'application/pdf',
                'content' => $this->pdf($circular, compress: true), 'outcome' => 'accepted'],
            ['type' => 'ndpc_decision', 'name' => 'NDPC final order NDPC-ENF-2026-017', 'ext' => 'txt', 'mime' => 'text/plain',
                'content' => $ndpc, 'outcome' => 'pending'],
            ['type' => 'audit_report', 'name' => 'ITGC review H1 2026', 'ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'content' => $this->docx($audit), 'outcome' => 'pending'],
            ['type' => 'vendor_ddq', 'name' => 'Annual security DDQ '.Str::limit($vendor, 30, ''), 'ext' => 'txt', 'mime' => 'text/plain',
                'content' => $ddq, 'outcome' => 'accepted'],
            ['type' => 'circular', 'name' => 'Scanned circular (image only)', 'ext' => 'pdf', 'mime' => 'application/pdf',
                'content' => $this->pdf(''), 'outcome' => 'pending'],
            ['type' => 'audit_report', 'name' => 'ITGC review H1 2026 draft', 'ext' => 'txt', 'mime' => 'text/plain',
                'content' => "Draft\n".$audit, 'outcome' => 'rejected'],
        ];
    }

    /** A one-page PDF whose content stream carries $text (none = an image-only scan). */
    private function pdf(string $text, bool $compress = false): string
    {
        $ops = "BT /F1 11 Tf 14 TL 50 780 Td\n";
        foreach (preg_split('/\R/', trim($text)) as $line) {
            if ($line !== '') {
                $ops .= '('.addcslashes($line, '()\\').") Tj T*\n";
            }
        }
        $ops .= 'ET';
        $stream = $compress ? gzcompress($ops) : $ops;
        $filter = $compress ? ' /Filter /FlateDecode' : '';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream).$filter." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($n + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function docx(string $text): string
    {
        $paragraphs = collect(preg_split('/\R/', trim($text)))
            ->map(fn ($l) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($l, ENT_XML1).'</w:t></w:r></w:p>')->implode('');
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    /* ------------------------------------------------------------- return runs */

    private function returnRuns(Organization $org, User $admin, User $preparer, string $prefix): void
    {
        $populator = app(ReturnRunPopulator::class);

        // Runs from the earlier demo seed carry placeholder PDF/XLSX paths to files that never existed
        // and no field values. Populate them from the register and drop the dead paths.
        ReturnRun::where('organization_id', $org->id)->with('template')->get()
            ->filter(fn (ReturnRun $run) => ! isset($run->data['values']))
            ->each(fn (ReturnRun $run) => $run->update([
                'data' => $populator->populate($run),
                'pdf_path' => null, 'xlsx_path' => null, 'csv_path' => null,
            ]));

        if (ReturnRun::where('organization_id', $org->id)->exists()) {
            return;
        }
        $ref = 1;

        foreach (ReturnTemplate::orderBy('id')->get() as $template) {
            $cadence = ReturnRunPopulator::cadence($template);
            $current = ReturnRunPopulator::currentPeriod($cadence);
            $previous = ReturnRunPopulator::currentPeriod($cadence, match ($cadence) {
                'annual' => now()->subYear(),
                'quarterly' => now()->subQuarter(),
                default => now()->subMonth(),
            });

            // Prior period: filed. Current period: one run in review, the rest in draft.
            $filed = $this->makeRun($org, $template, $previous, $preparer, $populator);
            $filed->update([
                'status' => ReturnRun::SUBMITTED,
                'approved_by_id' => $admin->id,
                'approved_at' => now()->subWeeks(4),
                'submitted_by_id' => $admin->id,
                'submitted_at' => now()->subWeeks(3),
                'submission_reference' => "{$template->regulator_code}/{$prefix}/".now()->year.'/'.str_pad((string) $ref++, 4, '0', STR_PAD_LEFT),
            ]);

            $draft = $this->makeRun($org, $template, $current, $preparer, $populator);
            if ($template->return_code === 'cbn_crms_it') {
                $data = $draft->data;
                $data['overrides']['incidents_in_period'] = [
                    'value' => (string) (((int) ($data['values']['incidents_in_period'] ?? 0)) + 1),
                    'reason' => 'One incident logged in the SOC tool after the register cut-off; added pending sync.',
                    'by' => $preparer->name,
                    'at' => now()->subDay()->toIso8601String(),
                ];
                $draft->update(['data' => $data, 'status' => ReturnRun::IN_REVIEW]);
            }
        }
    }

    private function makeRun(Organization $org, ReturnTemplate $template, string $period, User $preparer, ReturnRunPopulator $populator): ReturnRun
    {
        $run = ReturnRun::create([
            'organization_id' => $org->id,
            'template_id' => $template->id,
            'period' => $period,
            'status' => ReturnRun::DRAFT,
            'prepared_by_id' => $preparer->id,
            'data' => [],
        ]);
        $run->setRelation('template', $template);
        $run->update(['data' => $populator->populate($run)]);

        return $run;
    }

    /* ---------------------------------------------------- architecture returns */

    private function architectureReturns(User $ciso): void
    {
        if (RegulatoryReturn::where('organization_id', $ciso->organization_id)->exists()) {
            return;
        }

        $compiler = app(ReturnCompiler::class);
        $year = now()->year;

        // Prior-year CSAT pack, signed and submitted where the evidence clears the floor, so the
        // current year's pack has a period to diff against and the calendar has a filing to show.
        $prior = $compiler->compile('csat', (string) ($year - 1), null, $ciso);
        try {
            $compiler->sign($prior, $ciso, 'CISO');
            $prior->refresh()->update(['state' => RegulatoryReturn::SUBMITTED]);
        } catch (\RuntimeException) {
            // Below the signing floor: it stays in preparation, which the demo should show honestly.
        }

        foreach (['csat', 'ndpc_car', 'localisation_gap', 'itsb_maturity'] as $template) {
            try {
                $compiler->compile($template, (string) $year, null, $ciso);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
