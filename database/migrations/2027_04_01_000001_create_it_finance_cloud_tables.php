<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P6: cloud accounts, tag rules, daily cost lines and anomalies (FIN-03.09, FIN-03.10; plan P6-1..P6-4). */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
    }

    public function up(): void
    {
        Schema::create('fin_cloud_accounts', function (Blueprint $t) {
            $this->base($t);
            $t->string('provider', 8);
            $t->string('account_identifier', 100);
            $t->string('name', 160);
            $t->string('default_format', 12)->default('generic');
            $t->char('currency', 3);
            $t->foreignId('default_cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->json('tag_mapping')->nullable();
            $t->boolean('is_active')->default(true);
            $t->date('last_usage_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'provider', 'account_identifier'], 'fin_cloud_accounts_unique');
            $t->index(['organization_id', 'is_active']);
        });

        Schema::create('fin_cloud_tag_rules', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('cloud_account_id')->nullable()->constrained('fin_cloud_accounts')->cascadeOnDelete();
            $t->string('tag_key', 128);
            $t->string('tag_value', 255);
            $t->string('dimension', 20);
            $t->unsignedBigInteger('target_id');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'cloud_account_id', 'tag_key', 'tag_value', 'dimension'], 'fin_cloud_tag_rules_unique');
            $t->index(['organization_id', 'tag_key']);
        });

        Schema::table('fin_import_batches', function (Blueprint $t) {
            Schema::hasColumn('fin_import_batches', 'cloud_account_id') || $t->foreignId('cloud_account_id')->nullable()->constrained('fin_cloud_accounts')->nullOnDelete();
            Schema::hasColumn('fin_import_batches', 'format') || $t->string('format', 12)->nullable();
            Schema::hasColumn('fin_import_batches', 'date_from') || $t->date('date_from')->nullable();
            Schema::hasColumn('fin_import_batches', 'date_to') || $t->date('date_to')->nullable();
        });

        Schema::create('fin_cloud_cost_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('cloud_account_id')->constrained('fin_cloud_accounts')->cascadeOnDelete();
            $t->foreignId('import_batch_id')->nullable()->constrained('fin_import_batches')->nullOnDelete();
            $t->date('usage_date');
            $t->foreignId('period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->string('service', 160);
            $t->string('resource_id', 512)->nullable();
            $t->string('region', 64)->nullable();
            $t->json('tags')->nullable();
            $t->char('line_hash', 40);
            $t->decimal('cost', 18, 6);
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8)->default(1);
            $t->decimal('base_cost', 18, 2);
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->unsignedBigInteger('ea_application_id')->nullable();
            $t->unsignedBigInteger('business_service_id')->nullable();
            $t->boolean('is_untagged')->default(false);
            $t->string('attributed_by', 12)->nullable();
            $t->timestamps();
            $t->unique(['cloud_account_id', 'usage_date', 'line_hash'], 'fin_cloud_cost_lines_unique');
            $t->index(['organization_id', 'usage_date']);
            $t->index(['organization_id', 'cloud_account_id', 'usage_date'], 'fin_ccl_org_acct_date');
            $t->index(['organization_id', 'is_untagged', 'usage_date'], 'fin_ccl_org_untagged');
            $t->index(['organization_id', 'ea_application_id', 'usage_date'], 'fin_ccl_org_app');
            $t->index(['organization_id', 'business_service_id', 'usage_date'], 'fin_ccl_org_svc');
            $t->index(['organization_id', 'cost_centre_id', 'usage_date'], 'fin_ccl_org_cc');
        });

        Schema::create('fin_cloud_anomalies', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('cloud_account_id')->constrained('fin_cloud_accounts')->cascadeOnDelete();
            $t->date('detected_on');
            $t->string('scope', 12);
            $t->string('scope_ref', 191);
            $t->string('method', 16);
            $t->decimal('baseline_cost', 18, 2);
            $t->decimal('observed_cost', 18, 2);
            $t->decimal('baseline_base_cost', 18, 2);
            $t->decimal('observed_base_cost', 18, 2);
            $t->decimal('deviation_pct', 9, 2);
            $t->string('status', 16)->default('open');
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'cloud_account_id', 'detected_on', 'scope', 'scope_ref'], 'fin_cloud_anomalies_unique');
            $t->index(['organization_id', 'status', 'detected_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cloud_anomalies');
        Schema::dropIfExists('fin_cloud_cost_lines');
        Schema::table('fin_import_batches', function (Blueprint $t) {
            if (Schema::hasColumn('fin_import_batches', 'cloud_account_id')) {
                $t->dropConstrainedForeignId('cloud_account_id');
            }
            foreach (['format', 'date_from', 'date_to'] as $c) {
                Schema::hasColumn('fin_import_batches', $c) && $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('fin_cloud_tag_rules');
        Schema::dropIfExists('fin_cloud_accounts');
    }
};
