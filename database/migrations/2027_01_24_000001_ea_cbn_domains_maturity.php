<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Architecture: CBN blueprint domains and CMMI maturity (ATH-VAL-015).
 *
 * - The maturity framework is rebuilt on the CBN IT blueprint's five EA domains (Business,
 *   Application, Data, Technology, Security Architecture & Assurance), five practice areas each,
 *   rated on the CMMI scale 1-5. The previous ten domains held placeholder questions
 *   ("How mature is X — Q1?") answered with random levels; they and their responses are removed,
 *   and assessments that only held them are marked superseded.
 * - Assessments gain per-domain targets, a submit / approve workflow and a results snapshot;
 *   responses gain an evidence note and when they were answered.
 * - EA control mappings that pointed at another bank's controls (the Phase 2 seeder drew from
 *   every bank) are re-pointed at a control in the mapping's own bank, or removed if it has none.
 * - Threat models and anomaly findings take the bank of the record they are about.
 */
return new class extends Migration
{
    /** Domain => [name, description, practice areas [code, practice, evidence hint]]. */
    public const DOMAINS = [
        'BA' => ['Business Architecture', 'Capabilities, value streams, processes and business services, traced to strategy.', [
            ['BA-1', 'Business capabilities are modelled, owned and rated for maturity and criticality.', 'Capability map with owners'],
            ['BA-2', 'Value streams and processes are documented and linked to the capabilities they realise.', 'Value streams and process inventory'],
            ['BA-3', 'Business services are catalogued with owners and approved recovery objectives (RTO/RPO).', 'Business service catalogue, BIA-applied RTOs'],
            ['BA-4', 'Strategy is traceable to architecture: goals and drivers to capabilities to initiatives.', 'Motivation layer, initiatives linked to capabilities'],
            ['BA-5', 'Business architecture changes are reviewed at the ARB and kept under change control.', 'ARB decisions touching business architecture'],
        ]],
        'AA' => ['Application Architecture', 'The application portfolio, its integration and the solution designs that change it.', [
            ['AA-1', 'The application portfolio is complete, with owners, lifecycle and business criticality.', 'Application portfolio with owners and lifecycle'],
            ['AA-2', 'Applications are rationalised (TIME / 6R) and their cost (TCO) is known and managed.', 'TIME scores, TCO per application'],
            ['AA-3', 'Interfaces and APIs are catalogued (CBN connection catalogue) with contracts and reviews.', 'Interface catalogue, API register'],
            ['AA-4', 'Solution designs are reviewed against principles and standards before build.', 'ARB submissions and decisions'],
            ['AA-5', 'Applications are mapped to capabilities and impact analysis informs change.', 'Application-to-capability map, impact analyses'],
        ]],
        'DA' => ['Data Architecture', 'Information domains, the logical data model, data flows, privacy and data quality.', [
            ['DA-1', 'Information domains and a logical data model exist, with data owners for each domain.', 'Information domains with owners, logical entities'],
            ['DA-2', 'Data is classified and personal data is inventoried with its lawful basis (NDPA).', 'Classified entities, PII inventory'],
            ['DA-3', 'Data flows are documented, including cross-border transfers and residency obligations.', 'Data flows, transfer basis for cross-border flows'],
            ['DA-4', 'A business glossary and data-quality ownership are maintained.', 'Business glossary, stewardship'],
            ['DA-5', 'Privacy by design: changes to personal-data processing go through a DPIA.', 'DPIAs on changing processing'],
        ]],
        'TA' => ['Technology Architecture', 'Technology standards, the component inventory and its lifecycle, hosting and resilience.', [
            ['TA-1', 'Technology standards are catalogued with radar positions (adopt, trial, hold, retire).', 'Standards catalogue, technology radar'],
            ['TA-2', 'Technology components are inventoried with versions and end-of-life / end-of-support dates.', 'Component inventory with EOL dates'],
            ['TA-3', 'Hosting sites and their resilience (DR, power, connectivity) are documented.', 'Sites and resilience attributes'],
            ['TA-4', 'Obsolescence is tracked and refresh is planned on the roadmap.', 'Obsolescence flags, refresh initiatives'],
            ['TA-5', 'Cloud, outsourcing and vendor concentration risks in the technology stack are governed.', 'Hosting country, vendor concentration'],
        ]],
        'SA' => ['Security Architecture & Assurance', 'Trust zones, security controls mapped to the architecture, threat models and evidence that controls work.', [
            ['SA-1', 'Security zones and trust boundaries are defined and every application is assigned to one.', 'Zones, zone assignments'],
            ['SA-2', 'Security controls are mapped to architecture components with stated coverage.', 'Control mappings and coverage'],
            ['SA-3', 'Critical applications are threat-modelled (e.g. STRIDE) and models are kept current.', 'Threat models for critical applications'],
            ['SA-4', 'Control effectiveness is assured: tested, continuously monitored and independently reviewed.', 'Control effectiveness, CCM results'],
            ['SA-5', 'Security principles, standards and exceptions are governed and aligned to the CBN RBCSF.', 'Security principles, exceptions'],
        ]],
    ];

    public function up(): void
    {
        Schema::table('ea_maturity_domains', function (Blueprint $table) {
            $table->unsignedTinyInteger('sort_order')->default(0)->after('weight');
        });
        Schema::table('ea_maturity_assessments', function (Blueprint $table) {
            $table->json('targets')->nullable()->after('overall_score');
            $table->json('results')->nullable()->after('targets');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('created_by');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_at');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');
        });
        Schema::table('ea_maturity_responses', function (Blueprint $table) {
            $table->text('evidence_note')->nullable()->after('comment');
            $table->timestamp('answered_at')->nullable()->after('answered_by');
        });

        $this->framework();
        $this->repairControlMappings();
        $this->backfillOrganizations();
    }

    private function framework(): void
    {
        // The placeholder framework and its random responses go; assessments that held only them are superseded.
        $placeholder = DB::table('ea_maturity_questions')->where('question', 'like', 'How mature is %')->pluck('domain_id')->unique();
        if ($placeholder->isNotEmpty()) {
            $assessments = DB::table('ea_maturity_responses')->whereIn('question_id',
                DB::table('ea_maturity_questions')->whereIn('domain_id', $placeholder)->pluck('id'))->pluck('assessment_id')->unique();
            DB::table('ea_maturity_responses')->whereIn('assessment_id', $assessments)->delete();
            DB::table('ea_maturity_assessments')->whereIn('id', $assessments)->update(['status' => 'superseded', 'overall_score' => null]);
            DB::table('ea_maturity_questions')->whereIn('domain_id', $placeholder)->delete();
            DB::table('ea_maturity_domains')->whereIn('id', $placeholder)->delete();
        }
        // Status vocabulary: draft -> submitted -> approved (superseded for the retired framework).
        DB::table('ea_maturity_assessments')->where('status', 'in_progress')->update(['status' => 'draft']);
        DB::table('ea_maturity_assessments')->where('status', 'completed')->update(['status' => 'superseded']);

        $order = 0;
        foreach (self::DOMAINS as $code => [$name, $description, $practices]) {
            $domainId = DB::table('ea_maturity_domains')->where('code', $code)->value('id')
                ?? DB::table('ea_maturity_domains')->insertGetId(['code' => $code, 'framework' => 'cbn-ea', 'name' => $name, 'description' => $description,
                    'weight' => 20, 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($practices as [$pcode, $question, $guidance]) {
                if (! DB::table('ea_maturity_questions')->where('code', $pcode)->exists()) {
                    DB::table('ea_maturity_questions')->insert(['domain_id' => $domainId, 'code' => $pcode, 'question' => $question,
                        'guidance' => $guidance, 'max_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    /** Re-point mappings at a control in the mapping's own bank (same domain where possible). */
    private function repairControlMappings(): void
    {
        $controls = DB::table('controls')->whereNull('deleted_at')->orderBy('id')->get(['id', 'organization_id', 'domain'])->groupBy('organization_id');
        $byId = $controls->flatten(1)->keyBy('id');

        DB::table('ea_control_mappings')->orderBy('id')->get(['id', 'organization_id', 'control_id'])
            ->each(function ($m, $i) use ($controls, $byId) {
                $current = $byId->get($m->control_id);
                if ($current && (int) $current->organization_id === (int) $m->organization_id) {
                    return;
                }
                $own = $controls->get($m->organization_id);
                if (! $own || $own->isEmpty()) {
                    DB::table('ea_control_mappings')->where('id', $m->id)->delete();

                    return;
                }
                $sameDomain = $current ? $own->where('domain', $current->domain)->values() : collect();
                $pool = $sameDomain->isNotEmpty() ? $sameDomain : $own->values();
                DB::table('ea_control_mappings')->where('id', $m->id)->update(['control_id' => $pool[$i % $pool->count()]->id]);
            });
    }

    private function backfillOrganizations(): void
    {
        foreach (DB::table('ea_threat_models')->whereNull('organization_id')->get(['id', 'subject_type', 'subject_id']) as $tm) {
            $table = $tm->subject_type ? (new $tm->subject_type)->getTable() : null;
            $org = $table ? DB::table($table)->where('id', $tm->subject_id)->value('organization_id') : null;
            $org && DB::table('ea_threat_models')->where('id', $tm->id)->update(['organization_id' => $org]);
        }
        foreach (DB::table('ea_anomaly_findings')->whereNull('organization_id')->get(['id', 'entity_type', 'entity_id']) as $f) {
            $table = $f->entity_type && class_exists($f->entity_type) ? (new $f->entity_type)->getTable() : null;
            $org = $table && Schema::hasColumn($table, 'organization_id') ? DB::table($table)->where('id', $f->entity_id)->value('organization_id') : null;
            $org && DB::table('ea_anomaly_findings')->where('id', $f->id)->update(['organization_id' => $org]);
        }
    }

    public function down(): void
    {
        Schema::table('ea_maturity_responses', function (Blueprint $table) {
            $table->dropColumn(['evidence_note', 'answered_at']);
        });
        Schema::table('ea_maturity_assessments', function (Blueprint $table) {
            $table->dropColumn(['targets', 'results', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approval_note']);
        });
        Schema::table('ea_maturity_domains', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
