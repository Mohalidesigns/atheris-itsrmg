<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT Financial Management foundation (ATH-FIN-001 §3.1, §4.1): settings, fiscal calendar, cost
 * model (cost centres, TBM categories, GL accounts), currencies and FX, taxes and cost drivers.
 * Every table carries organization_id; currency codes are ISO 4217 char(3) rather than foreign keys.
 */
return new class extends Migration
{
    private const TABLES = [
        'fin_driver_values', 'fin_drivers', 'fin_tax_types', 'fin_fx_rates', 'fin_currencies', 'fin_categories',
        'fin_cost_centres', 'fin_gl_accounts', 'fin_periods', 'fin_fiscal_years', 'fin_settings',
    ];

    private function base(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
    }

    public function up(): void
    {
        Schema::create('fin_settings', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $t->string('period_scheme', 16)->default('monthly');
            $t->decimal('variance_amber_pct', 6, 2)->default(5);
            $t->decimal('variance_red_pct', 6, 2)->default(10);
            $t->decimal('variance_abs_floor', 18, 2)->default(0);
            $t->unsignedSmallInteger('renewal_lead_days')->default(90);
            $t->json('renewal_notice_offsets')->nullable();
            $t->string('escalation_role', 64)->nullable();
            $t->string('overbudget_mode', 8)->default('warn');
            $t->decimal('match_tolerance_pct', 6, 2)->default(0);
            $t->decimal('match_tolerance_abs', 18, 2)->default(0);
            $t->string('eac_method', 24)->default('remaining_budget');
            $t->boolean('showback_enabled')->default(false);
            $t->unsignedTinyInteger('digest_hour')->default(7);
            $t->json('doa_bands')->nullable();
            $t->json('investment_score_weights')->nullable();
            $t->string('fx_provider', 32)->nullable();
            $t->timestamps();
            $t->unique('organization_id');
        });

        Schema::create('fin_fiscal_years', function (Blueprint $t) {
            $this->base($t);
            $t->string('year_label', 16);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('period_scheme', 16)->default('monthly');
            $t->string('status', 16)->default('planning');
            $t->boolean('is_current')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'year_label']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_periods', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->cascadeOnDelete();
            $t->unsignedTinyInteger('sequence');
            $t->string('name', 32);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 8)->default('open');
            $t->boolean('is_adjustment')->default(false);
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['fiscal_year_id', 'sequence']);
            $t->index(['organization_id', 'starts_on']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_gl_accounts', function (Blueprint $t) {
            $this->base($t);
            $t->string('code', 32);
            $t->string('name', 120);
            $t->string('type', 16);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('fin_cost_centres', function (Blueprint $t) {
            $this->base($t);
            $t->string('code', 32);
            $t->string('name', 120);
            $t->foreignId('parent_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->string('business_unit', 120)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('fin_categories', function (Blueprint $t) {
            $this->base($t);
            $t->string('code', 32);
            $t->string('name', 120);
            $t->string('kind', 16);
            $t->foreignId('parent_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->string('tbm_reference', 64)->nullable();
            $t->boolean('default_capex')->default(false);
            $t->foreignId('default_gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'kind']);
        });

        Schema::create('fin_currencies', function (Blueprint $t) {
            $this->base($t);
            $t->char('code', 3);
            $t->string('name', 64);
            $t->string('symbol', 8)->nullable();
            $t->unsignedTinyInteger('decimals')->default(2);
            $t->boolean('is_base')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('fin_fx_rates', function (Blueprint $t) {
            $this->base($t);
            $t->char('from_currency', 3);
            $t->char('to_currency', 3);
            $t->decimal('rate', 18, 8);
            $t->date('rate_date');
            $t->string('source', 16)->default('manual');
            $t->timestamps();
            $t->unique(['organization_id', 'from_currency', 'to_currency', 'rate_date'], 'fin_fx_rates_pair_date_unique');
            $t->index(['organization_id', 'to_currency', 'rate_date']);
        });

        Schema::create('fin_tax_types', function (Blueprint $t) {
            $this->base($t);
            $t->string('code', 16);
            $t->string('name', 120);
            $t->string('kind', 16)->default('vat');
            $t->decimal('rate', 7, 4)->default(0);
            $t->boolean('is_inclusive')->default(false);
            $t->json('applies_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('fin_drivers', function (Blueprint $t) {
            $this->base($t);
            $t->string('code', 32);
            $t->string('name', 120);
            $t->string('unit', 32);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('fin_driver_values', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('driver_id')->constrained('fin_drivers')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->cascadeOnDelete();
            $t->decimal('value', 18, 4)->default(0);
            $t->timestamps();
            $t->unique(['driver_id', 'period_id']);
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
