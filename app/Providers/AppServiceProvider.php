<?php

namespace App\Providers;

use App\Policies\Ea\EaPolicy;
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
        //
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
            \App\Events\Ea\ArchitectureEntityChanged::class => \App\Listeners\Ea\BreakQualitySeal::class,

            // I-1  Obsolescence  → Risk
            \App\Events\Ea\TechComponentBecameObsolete::class => \App\Listeners\Ea\OpenObsolescenceRisk::class,
            // I-2  CVE           → Vulnerability
            \App\Events\Ea\CveMatchedToComponent::class => \App\Listeners\Ea\OpenVulnerability::class,
            // I-6  BIA           → EA process criticality / RTO / RPO
            \App\Events\Core\BiaRecordSaved::class => \App\Listeners\Ea\UpdateProcessCriticality::class,
            // I-9  ARB decision  → Issues
            \App\Events\Ea\ArbDecisionRecorded::class => \App\Listeners\Ea\OpenConditionIssues::class,
            // I-10 Evidence pack → Evidence Vault
            \App\Events\Ea\EvidencePackGenerated::class => \App\Listeners\Ea\RegisterEvidencePack::class,
            // I-13 Incident      → EA blast radius
            \App\Events\Core\IncidentDeclared::class => \App\Listeners\Core\AttachBlastRadius::class,
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
}
