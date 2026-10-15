<?php

namespace App\Http\Controllers;

use App\Models\AucsControl;
use App\Models\AucsFrameworkMapping;
use App\Models\Control;
use App\Models\CopilotConversation;
use App\Models\CopilotMessage;
use App\Models\CustomField;
use App\Models\EvidenceVaultItem;
use App\Models\FairRun;
use App\Models\FairScenario;
use App\Models\FeatureFlag;
use App\Models\FrameworkClause;
use App\Models\Obligation;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Tenant;
use App\Models\TenantTheme;
use App\Models\Vulnerability;
use App\Services\FairMonteCarloService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PlatformController extends Controller
{
    /* ------------------------------ Copilot ------------------------------ */
    public function copilotIndex(Request $request)
    {
        $conversations = CopilotConversation::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')->take(20)->get();
        $active = $request->filled('c')
            ? CopilotConversation::where('user_id', $request->user()->id)->findOrFail($request->integer('c'))
            : $conversations->first();
        $messages = $active
            ? CopilotMessage::where('conversation_id', $active->id)->orderBy('created_at')->orderBy('id')->get()
            : collect();

        return Inertia::render('Copilot/Index', compact('conversations', 'active', 'messages'));
    }

    public function copilotSend(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'content' => 'required|string|max:2000',
        ]);
        $user = $request->user();
        $conv = ! empty($data['conversation_id'])
            ? CopilotConversation::where('user_id', $user->id)->findOrFail($data['conversation_id'])
            : CopilotConversation::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'title' => Str::limit($data['content'], 40),
                'mode' => 'general',
            ]);
        CopilotMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $data['content'],
            'tokens_in' => 0,
            'model' => 'atheris-offline',
        ]);
        CopilotMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => $this->offlineReply($data['content']),
            'tokens_out' => 0,
            'model' => 'atheris-offline',
        ]);

        return redirect()->route('copilot.index', ['c' => $conv->id])->with('success', 'Copilot response received');
    }

    /** Deterministic reply from the bank's own risks, controls and the AUCS catalogue. No language model is involved. */
    private function offlineReply(string $question): string
    {
        $terms = collect(preg_split('/[^a-z0-9]+/i', Str::lower($question)))
            ->filter(fn ($w) => strlen($w) >= 4 && ! in_array($w, ['show', 'which', 'what', 'list', 'about', 'with', 'have', 'from', 'that', 'this', 'there', 'tell', 'summarise', 'latest'], true))
            ->unique()->take(6)->values();

        $match = function ($query, array $cols) use ($terms) {
            $query->where(function ($q) use ($terms, $cols) {
                foreach ($terms as $t) {
                    foreach ($cols as $c) {
                        $q->orWhere($c, 'like', "%{$t}%");
                    }
                }
            });

            return $query;
        };

        $riskTotal = Risk::count();
        $controlTotal = Control::count();
        $lines = ["Offline assistant (no language model connected). Your register holds {$riskTotal} risks and {$controlTotal} controls."];

        if ($terms->isEmpty()) {
            return $lines[0]."\nAsk with a keyword (for example \"MFA\", \"vendor\" or \"backup\") and I will list matching records.";
        }

        $risks = $match(Risk::query(), ['title', 'description', 'risk_id_code'])->orderByDesc('inherent_score')->limit(5)->get();
        $controls = $match(Control::query(), ['title', 'description', 'control_code'])->limit(5)->get();
        $aucs = $match(AucsControl::query(), ['title', 'objective_text', 'code'])->limit(5)->get();

        $lines[] = 'Matches for "'.$terms->implode(' ').'": '.$risks->count().' risks, '.$controls->count().' controls, '.$aucs->count().' AUCS controls (top 5 each).';
        foreach ($risks as $r) {
            $lines[] = "• Risk {$r->risk_id_code}: {$r->title} (inherent {$r->inherent_rating}, status {$r->status})";
        }
        foreach ($controls as $c) {
            $lines[] = "• Control {$c->control_code}: {$c->title} (effectiveness ".($c->effectiveness ?? 'not rated').')';
        }
        foreach ($aucs as $a) {
            $lines[] = "• AUCS {$a->code}: {$a->title}";
        }
        if ($risks->isEmpty() && $controls->isEmpty() && $aucs->isEmpty()) {
            $lines[] = 'No records match those keywords.';
        }

        return implode("\n", $lines);
    }

    public function obligationsIndex(Request $r)
    {
        $obligations = Obligation::query()
            ->when($r->query('regulator'), fn ($q, $reg) => $q->where('regulator_code', $reg))
            ->orderBy('regulator_code')
            ->get()
            ->map(fn ($o) => $o->toArray() + [
                'next_due' => $o->nextDue()->toDateString(),
                'days_to_due' => (int) today()->diffInDays($o->nextDue(), false),
            ])
            ->sortBy('next_due')->values();

        return Inertia::render('Obligations/Index', [
            'obligations' => $obligations,
            'regulators' => Obligation::query()->distinct()->orderBy('regulator_code')->pluck('regulator_code'),
            'regulator' => $r->query('regulator'),
        ]);
    }

    /* ------------------------------- AUCS ------------------------------- */
    public function aucsIndex(Request $r)
    {
        $domain = $r->query('domain');
        $controls = AucsControl::query()
            ->when($domain, fn ($q) => $q->where('domain', $domain))
            ->orderBy('code')
            ->get();
        $domains = AucsControl::distinct('domain')->orderBy('domain')->pluck('domain');
        $mappingsByControl = AucsFrameworkMapping::with('clause')->get()->groupBy('aucs_control_id');
        $frameworkCodes = FrameworkClause::distinct('framework_code')->pluck('framework_code');

        return Inertia::render('Aucs/Index', compact('controls', 'domains', 'mappingsByControl', 'frameworkCodes', 'domain'));
    }

    /* ------------------------------- Risk Graph ------------------------------- */
    public function risksGraph(Request $request)
    {
        $limit = in_array((int) $request->get('limit'), [10, 20, 40], true) ? (int) $request->get('limit') : 10;

        // Top active risks by exposure, with their real relationships (all tenant-scoped).
        $risks = Risk::active()
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('rating'), fn ($q) => $q->where('inherent_rating', $request->rating))
            ->with([
                'controls:id,control_code,title,effectiveness',
                'assets:id,asset_id_code,name',
                'threatAssessments' => fn ($q) => $q->with('threat:id,threat_id_code,name,severity'),
            ])
            ->orderByDesc('inherent_score')->orderByDesc('residual_score')
            ->limit($limit)
            ->get(['id', 'risk_id_code', 'title', 'inherent_score', 'inherent_rating', 'residual_score']);

        $assetIds = $risks->flatMap(fn ($r) => $r->assets->pluck('id'))->unique();
        $vulns = $assetIds->isEmpty() ? collect() : Vulnerability::query()
            ->whereNotIn('status', ['remediated', 'closed', 'false_positive'])
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $assetIds))
            ->with('assets:id')
            ->get(['id', 'vuln_id_code', 'cve_id', 'title', 'severity'])
            ->map(fn ($v) => $v->only(['id', 'vuln_id_code', 'cve_id', 'title', 'severity'])
                + ['asset_ids' => $v->assets->pluck('id')->intersect($assetIds)->values()]);

        return Inertia::render('Risks/Graph', [
            'risks' => $risks,
            'vulns' => $vulns,
            'categories' => RiskCategory::orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['limit' => $limit] + $request->only('category_id', 'rating'),
        ]);
    }

    /* ---------------------------- Evidence Vault ---------------------------- */
    public function evidenceVaultIndex()
    {
        $evidence = EvidenceVaultItem::orderByDesc('created_at')->take(200)->get();
        $stats = [
            'total' => EvidenceVaultItem::count(),
            'worm_locked' => EvidenceVaultItem::where('worm_locked', true)->count(),
            'bytes' => EvidenceVaultItem::sum('bytes'),
        ];

        return Inertia::render('EvidenceVault/Index', compact('evidence', 'stats'));
    }

    /* --------------------------------- FAIR --------------------------------- */
    public function fairIndex()
    {
        $scenarios = FairScenario::with([
            'risk:id,risk_id_code,title,fair_annual_loss_expectancy',
            'runs' => fn ($q) => $q->latest('ran_at')->latest('id'),
        ])->orderBy('name')->get()
            ->map(fn ($s) => $s->setRelation('runs', $s->runs->take(5)));

        return Inertia::render('Fair/Index', [
            'scenarios' => $scenarios,
            // Active risks, plus any already-linked risk that has since closed so the link still shows.
            'risks' => Risk::where(fn ($q) => $q->active()->orWhereIn('id', $scenarios->pluck('risk_id')->filter()))
                ->orderBy('risk_id_code')->get(['id', 'risk_id_code', 'title', 'status']),
        ]);
    }

    public function fairRun(FairScenario $scenario, FairMonteCarloService $monteCarlo)
    {
        $result = $monteCarlo->simulate($scenario);

        FairRun::create(collect($result)->only(['ale_mean_ngn', 'ale_median_ngn', 'ale_p95_ngn', 'ale_p99_ngn', 'histogram'])->all() + [
            'scenario_id' => $scenario->id,
            'ran_at' => now(),
        ]);

        // A scenario quantifies its linked risk: the register's ALE/SLE follow the latest run.
        if ($scenario->risk) {
            $scenario->risk->update([
                'fair_annual_loss_expectancy' => $result['ale_mean_ngn'],
                'fair_single_loss_expectancy' => $result['sle_ngn'],
            ]);
        }

        return redirect()->back()->with('success', sprintf(
            'FAIR Monte Carlo completed (%s iterations): mean ALE ₦%s, P95 ₦%s%s.',
            number_format($result['iterations']),
            number_format($result['ale_mean_ngn']),
            number_format($result['ale_p95_ngn']),
            $scenario->risk ? " — {$scenario->risk->risk_id_code} ALE updated" : ''
        ));
    }

    public function fairLink(Request $request, FairScenario $scenario)
    {
        $validated = $request->validate([
            'risk_id' => ['nullable', Rule::exists('risks', 'id')
                ->where('organization_id', $request->user()->organization_id)->whereNull('deleted_at')],
        ]);
        $scenario->update(['risk_id' => $validated['risk_id'] ?? null]);

        return redirect()->back()->with('success', $scenario->risk_id
            ? "Scenario linked to {$scenario->risk->risk_id_code}. Run the simulation to update its ALE."
            : 'Scenario unlinked.');
    }

    /* --------------------------------- DR --------------------------------- */
    /* -------------------------------- Settings -------------------------------- */
    public const CUSTOM_FIELD_ENTITIES = ['Risk', 'Control', 'Incident', 'Vendor', 'Policy', 'Asset', 'Issue'];

    public static function defaultTheme(): array
    {
        return ['navy' => '#0A1F44', 'gold' => '#C9A86A', 'green' => '#2D7D46', 'charcoal' => '#2D3748'];
    }

    public function settingsTheme()
    {
        // Strictly the bank's own theme; platform defaults when it has none.
        $row = TenantTheme::first();
        $theme = [
            'tokens' => array_merge(self::defaultTheme(), $row?->tokens ?? []),
            'logo_path' => $row?->logo_path,
            'is_default' => $row === null,
        ];

        return Inertia::render('Settings/Theme', compact('theme'));
    }

    public function settingsThemeUpdate(Request $request)
    {
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $data = $request->validate([
            'tokens' => ['required', 'array'],
            'tokens.navy' => $hex, 'tokens.gold' => $hex, 'tokens.green' => $hex, 'tokens.charcoal' => $hex,
            'logo_path' => ['nullable', 'url', 'max:500'],
        ]);
        $theme = TenantTheme::first() ?? new TenantTheme(['status' => 'active']);
        $theme->fill([
            'organization_id' => $request->user()->organization_id,
            'tokens' => $data['tokens'], 'logo_path' => $data['logo_path'] ?? null,
        ])->save();

        return back()->with('success', 'Theme saved for your bank. Note: the application shell does not yet read these tokens, so they are stored but not applied.');
    }

    public function settingsCustomFields()
    {
        $fields = CustomField::orderBy('subject_type')->orderBy('order_index')->get();
        $entities = self::CUSTOM_FIELD_ENTITIES;

        return Inertia::render('Settings/CustomFields', compact('fields', 'entities'));
    }

    public function settingsCustomFieldStore(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(self::CUSTOM_FIELD_ENTITIES)],
            'key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,62}$/', Rule::unique('custom_fields', 'key')
                ->where('organization_id', $orgId)->where('subject_type', $request->input('subject_type'))],
            'label' => ['required', 'string', 'max:255'],
            'data_type' => ['required', Rule::in(['text', 'number', 'date', 'select'])],
            'required' => ['sometimes', 'boolean'],
            'options' => ['required_if:data_type,select', 'nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100'],
        ]);
        $data['options'] = $data['data_type'] === 'select' ? array_values($data['options'] ?? []) : null;
        $data['order_index'] = (int) CustomField::where('subject_type', $data['subject_type'])->max('order_index') + 10;
        CustomField::create($data + ['organization_id' => $orgId]);

        return back()->with('success', 'Custom field added.');
    }

    public function settingsCustomFieldDestroy(int $id)
    {
        CustomField::findOrFail($id)->delete();

        return back()->with('success', 'Custom field removed.');
    }

    public function settingsFeatureFlags(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $overrides = FeatureFlag::where('organization_id', $orgId)->get()->keyBy('key');
        $flags = FeatureFlag::whereNull('organization_id')->orderBy('key')->get()->map(fn ($f) => [
            'id' => $f->id, 'key' => $f->key, 'rollout_percent' => $f->rollout_percent,
            'default_enabled' => (bool) $f->enabled,
            'override' => $overrides->has($f->key) ? (bool) $overrides[$f->key]->enabled : null,
            'enabled' => $overrides->has($f->key) ? (bool) $overrides[$f->key]->enabled : (bool) $f->enabled,
        ]);

        return Inertia::render('Settings/FeatureFlags', [
            'flags' => $flags, 'isSuperAdmin' => $request->user()->hasRole('Super Admin'),
        ]);
    }

    /** Bank-level toggle: writes this bank's override row only. */
    public function settingsFeatureFlagToggle(Request $request, int $flag)
    {
        $global = FeatureFlag::whereNull('organization_id')->findOrFail($flag);
        $orgId = $request->user()->organization_id;
        $effective = FeatureFlag::enabledFor($global->key, $orgId) ?? false;
        FeatureFlag::updateOrCreate(
            ['organization_id' => $orgId, 'key' => $global->key],
            ['enabled' => ! $effective, 'rollout_percent' => $global->rollout_percent],
        );

        return redirect()->back();
    }

    /** Platform-wide default: Super Admin only. */
    public function settingsFeatureFlagToggleGlobal(Request $request, int $flag)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        $global = FeatureFlag::whereNull('organization_id')->findOrFail($flag);
        $global->update(['enabled' => ! $global->enabled]);

        return redirect()->back();
    }
}
