<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ir_narratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->tinyInteger('category_code')->unsigned();
            $table->string('narrative_key', 100);
            $table->string('narrative_label');
            $table->text('narrative_value')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'category_code', 'narrative_key'], 'csat_ir_narr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ir_narratives');
    }
};
