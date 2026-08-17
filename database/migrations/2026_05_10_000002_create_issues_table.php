<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('severity', ['critical', 'high', 'moderate', 'low'])->default('moderate');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('sla_minutes')->default(1440);
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            $table->enum('status', ['open', 'in_progress', 'blocked', 'remediated', 'verified', 'closed', 'escalated'])->default('open');
            $table->text('root_cause')->nullable();
            $table->text('remediation_plan')->nullable();
            $table->string('external_ticket_id')->nullable();
            $table->string('external_ticket_system')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_events');
        Schema::dropIfExists('issues');
    }
};
