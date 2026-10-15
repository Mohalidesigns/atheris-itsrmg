<?php

namespace App\Providers;

use App\Events\Core\BiaRecordSaved;
use App\Events\Core\IncidentDeclared;
use App\Events\Core\IncidentSaved;
use App\Events\Ea\ArbDecisionRecorded;
use App\Events\Ea\ArchitectureEntityChanged;
use App\Events\Ea\CveMatchedToComponent;
use App\Events\Ea\EvidencePackGenerated;
use App\Events\Ea\TechComponentBecameObsolete;
use App\Listeners\Core\AttachBlastRadius;
use App\Listeners\Core\OpenCustomerImpactCapa;
use App\Listeners\Ea\BreakQualitySeal;
use App\Listeners\Ea\OpenConditionIssues;
use App\Listeners\Ea\OpenObsolescenceRisk;
use App\Listeners\Ea\OpenVulnerability;
use App\Listeners\Ea\RegisterEvidencePack;
use App\Listeners\Ea\UpdateProcessCriticality;
use App\Policies\Ea\EaPolicy;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Memoises per-bank finance settings for the life of the request.
        $this->app->singleton(FinanceSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Super Admin bypasses all permission checks.
        Gate::before(fn ($user, $ability) => $user->hasRole('Super Admin') ? true : null);

        $this->registerEaPolicies();
        $this->registerFinancePolicies();

        $this->registerEaContracts();
    }

    /**
     * ATH-EAR-002 §7.5 — the cross-module event bus.
     *
     * "Introduce a small set of domain events rather than direct service calls
     * between modules. This keeps the seams clean and makes each contract
     * testable in isolation."
     *
     * Every listener must be idempotent: I-1 in particular fires nightly
     * against the same component and must update rather than accumulate.
     */
    private function registerEaContracts(): void
    {
        $contracts = [
            // B3 — the freshness state machine (Phase 1).
            ArchitectureEntityChanged::class => BreakQualitySeal::class,

            // I-1  Obsolescence  → Risk
            TechComponentBecameObsolete::class => OpenObsolescenceRisk::class,
            // I-2  CVE           → Vulnerability
            CveMatchedToComponent::class => OpenVulnerability::class,
            // I-6  BIA           → EA process criticality / RTO / RPO
            BiaRecordSaved::class => UpdateProcessCriticality::class,
            // I-9  ARB decision  → Issues
            ArbDecisionRecorded::class => OpenConditionIssues::class,
            // I-10 Evidence pack → Evidence Vault
            EvidencePackGenerated::class => RegisterEvidencePack::class,
            // I-13 Incident      → EA blast radius
            IncidentDeclared::class => AttachBlastRadius::class,
            // IT-GOV-CAPA-001 §3.3  Customer-impacting incident → CAPA (toggle: capa.auto_trigger)
            IncidentSaved::class => OpenCustomerImpactCapa::class,
        ];

        foreach ($contracts as $event => $listener) {
            Event::listen($event, $listener);
        }
    }

    /**
     * Bind every App\Models\Ea\* model to the single EaPolicy.
     *
     * Laravel's convention-based policy discovery looks for
     * App\Policies\Ea\{Model}Policy — one class per model. The EA module uses
     * one policy for the whole namespace, so the mapping is explicit.
     * ATH-EAR-002 §2.2 Defect C: previously there was no mapping at all, so
     * EaPolicy was dead code.
     */
    private function registerEaPolicies(): void
    {
        $dir = app_path('Models/Ea');
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\Ea\\'.basename($file, '.php');
            if (class_exists($class)) {
                Gate::policy($class, EaPolicy::class);
            }
        }
    }

    /**
     * Bind every App\Models\Finance\* model to the single FinancePolicy (ATH-FIN-001 §8).
     */
    private function registerFinancePolicies(): void
    {
        $dir = app_path('Models/Finance');
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\Finance\\'.basename($file, '.php');
            if (class_exists($class)) {
                Gate::policy($class, FinancePolicy::class);
            }
        }
    }
}
