<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->string('control_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('domain')->nullable();
            $table->string('category')->nullable();
            $table->string('type')->nullable(); // preventive, detective, corrective, deterrent
            $table->string('nature')->nullable(); // technical, administrative, physical
            $table->string('frequency')->nullable(); // continuous, daily, weekly, monthly, quarterly, annual
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active'); // draft, active, inactive, deprecated
            $table->string('effectiveness')->nullable(); // effective, partially_effective, ineffective, not_assessed
            $table->boolean('is_key_control')->default(false);
            $table->text('implementation_notes')->nullable();
            $table->date('last_tested')->nullable();
            $table->date('next_review_date')->nullable();
            $table->json('tags')->nullable();
            $table->integer('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['organization_id', 'control_code']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controls');
    }
};
