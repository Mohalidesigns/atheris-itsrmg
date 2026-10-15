<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_reports')) {
            Schema::create('scheduled_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('name');
                $table->string('report_type', 32);
                $table->string('frequency', 16);
                $table->json('recipients')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('scheduled_report_runs')) {
            Schema::create('scheduled_report_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('scheduled_report_id')->index();
                $table->timestamp('ran_at');
                $table->string('status', 16)->default('recorded');
                $table->json('summary')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('integration_connections')) {
            Schema::create('integration_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('connector_key', 64);
                $table->text('config')->nullable();
                $table->string('status', 24)->default('not_configured');
                $table->timestamp('last_tested_at')->nullable();
                $table->string('last_test_result', 16)->nullable();
                $table->string('last_test_message', 500)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'connector_key']);
            });
        }

        if (! Schema::hasColumn('users', 'notification_preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('notification_preferences')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report_runs');
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('integration_connections');
        if (Schema::hasColumn('users', 'notification_preferences')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
        }
    }
};
