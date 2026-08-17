<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements_of_applicability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('framework_requirements')->cascadeOnDelete();
            $table->boolean('is_applicable')->default(true);
            $table->text('justification')->nullable();
            $table->string('implementation_status')->nullable(); // implemented, planned, not_implemented
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'requirement_id'], 'soa_org_req_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statements_of_applicability');
    }
};
