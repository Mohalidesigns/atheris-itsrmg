<?php

namespace App\Services\Ea;

use App\Models\Ea\AnomalyFinding;
use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\DpiaAssessment;
use App\Models\Ea\EaApi;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\Initiative;
use App\Models\Ea\TechComponent;

/**
 * AnomalyEngine — implements the five seed anomaly rules called for in the
 * gap analysis (§5.12 remediation) plus a handful of practical extensions.
 * Findings are written to `ea_anomaly_findings` so they can be triaged from
 * the UI.
 *
 * Rules:
 *   EA-ANO-01  Applications with no owner
 *   EA-ANO-02  Capabilities with no application
 *   EA-ANO-03  Active interfaces where source or target application is retired
 *   EA-ANO-04  Tech components with EOL within 12 months and no replacement initiative
 *   EA-ANO-05  PII logical entities whose info domain has no DPO assigned
 *   EA-ANO-06  Cross-border data flows without an approved DPIA
 *   EA-ANO-07  Applications missing API contract on interfaces
 */
class AnomalyEngine
{
    public function run(): array
    {
        $created = 0;
        $rules = [
            'EA-ANO-01' => fn () => $this->ownerlessApps(),
            'EA-ANO-02' => fn () => $this->capabilitiesNoApp(),
            'EA-ANO-03' => fn () => $this->staleInterfaces(),
            'EA-ANO-04' => fn () => $this->eolWithoutReplacement(),
            'EA-ANO-05' => fn () => $this->piiWithNoDpo(),
            'EA-ANO-06' => fn () => $this->crossBorderNoDpia(),
            'EA-ANO-07' => fn () => $this->apisMissingContract(),
        ];

        $newFindings = [];
        foreach ($rules as $code => $fn) {
            $rows = $fn();
            foreach ($rows as $row) {
                $existing = AnomalyFinding::where('rule_code', $code)
                    ->where('entity_type', $row['entity_type'])
                    ->where('entity_id', $row['entity_id'])
                    ->first();
                if ($existing) {
                    $existing->update(['last_seen_at' => now(), 'detail' => $row['detail']]);
                } else {
                    $newFindings[] = AnomalyFinding::create([
                        'rule_code' => $code,
                        'severity' => $row['severity'],
                        'entity_type' => $row['entity_type'],
                        'entity_id' => $row['entity_id'],
                        'title' => $row['title'],
                        'detail' => $row['detail'],
                        'status' => 'open',
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ]);
                    $created++;
                }
            }
        }

        return ['created' => $created, 'new' => $newFindings];
    }

    public function ownerlessApps(): array
    {
        return EaApplication::query()
            ->where(function ($q) { $q->whereNull('owner_role')->orWhere('owner_role', ''); })
            ->get()
            ->map(fn ($a) => [
                'entity_type' => EaApplication::class,
                'entity_id' => $a->id,
                'severity' => $a->criticality === 'critical' ? 'high' : 'medium',
                'title' => "Application '{$a->name}' has no owner",
                'detail' => "Code: {$a->code}. Lifecycle: {$a->lifecycle}. Without a named owner the application cannot enter the ARB workflow.",
            ])->all();
    }

    public function capabilitiesNoApp(): array
    {
        $caps = Capability::all();
        $missing = [];
        foreach ($caps as $c) {
            $count = EaApplication::whereJsonContains('capability_ids', $c->id)->count();
            if ($count === 0 && $c->level >= 2) {
                $missing[] = [
                    'entity_type' => Capability::class,
                    'entity_id' => $c->id,
                    'severity' => $c->criticality === 'critical' ? 'high' : 'low',
                    'title' => "Capability '{$c->name}' has no supporting application",
                    'detail' => "Code: {$c->code}. Level: {$c->level}. Either an application is missing, or this capability is decommissioned.",
                ];
            }
        }
        return $missing;
    }

    public function staleInterfaces(): array
    {
        return EaInterface::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereIn('source_app_id', EaApplication::where('lifecycle', 'retired')->pluck('id'))
                  ->orWhereIn('target_app_id', EaApplication::where('lifecycle', 'retired')->pluck('id'));
            })
            ->get()
            ->map(fn ($i) => [
                'entity_type' => EaInterface::class,
                'entity_id' => $i->id,
                'severity' => 'high',
                'title' => "Active interface '{$i->name}' points to a retired application",
                'detail' => "Source: {$i->source_app_id}, target: {$i->target_app_id}. Protocol: {$i->protocol}.",
            ])->all();
    }

    public function eolWithoutReplacement(): array
    {
        $eolList = TechComponent::query()
            ->whereNotNull('eol_date')
            ->whereBetween('eol_date', [now(), now()->addYear()])
            ->get();
        $missing = [];
        foreach ($eolList as $t) {
            $replacement = Initiative::query()
                ->where(function ($q) use ($t) {
                    $q->where('description', 'like', "%{$t->name}%")
                      ->orWhere('name', 'like', "%{$t->name}%");
                })
                ->whereIn('status', ['proposed', 'approved', 'in_flight'])
                ->exists();
            if (!$replacement) {
                $missing[] = [
                    'entity_type' => TechComponent::class,
                    'entity_id' => $t->id,
                    'severity' => 'high',
                    'title' => "Tech '{$t->name}' EOL within 12 months — no replacement initiative",
                    'detail' => "EOL: {$t->eol_date}. Radar: {$t->radar_status}. Add a roadmap initiative referencing this component.",
                ];
            }
        }
        return $missing;
    }

    public function piiWithNoDpo(): array
    {
        $domains = InfoDomain::all();
        $rows = [];
        foreach ($domains as $d) {
            if ($d->entities()->where('pii_flag', true)->exists() && empty($d->owner_role)) {
                $rows[] = [
                    'entity_type' => InfoDomain::class,
                    'entity_id' => $d->id,
                    'severity' => 'critical',
                    'title' => "Info domain '{$d->name}' carries PII but has no DPO assignment",
                    'detail' => "NDPA Article 32 requires a named data-protection officer for processing personal data.",
                ];
            }
        }
        return $rows;
    }

    public function crossBorderNoDpia(): array
    {
        $flows = DataFlow::where('cross_border', true)->get();
        $rows = [];
        foreach ($flows as $f) {
            $hasDpia = DpiaAssessment::where('data_flow_id', $f->id)
                ->whereIn('status', ['in_review', 'approved'])
                ->exists();
            if (!$hasDpia) {
                $rows[] = [
                    'entity_type' => DataFlow::class,
                    'entity_id' => $f->id,
                    'severity' => 'high',
                    'title' => "Cross-border flow '{$f->name}' has no approved DPIA",
                    'detail' => "Protocol: {$f->protocol}. Classification: {$f->classification}. NDPA Article 41 requires a DPIA for cross-border transfers of personal data.",
                ];
            }
        }
        return $rows;
    }

    public function apisMissingContract(): array
    {
        return EaApi::query()
            ->where(function ($q) { $q->whereNull('contract_text')->orWhere('contract_text', ''); })
            ->get()
            ->map(fn ($api) => [
                'entity_type' => EaApi::class,
                'entity_id' => $api->id,
                'severity' => 'medium',
                'title' => "API '{$api->name}' missing contract definition",
                'detail' => "Auth: {$api->auth_method}. Version: {$api->version}. Add an OpenAPI / AsyncAPI / WSDL document so consumers can be onboarded.",
            ])->all();
    }
}
