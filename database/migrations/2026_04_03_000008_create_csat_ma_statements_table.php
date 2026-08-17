<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ma_statements', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('domain_code')->unsigned();
            $table->string('domain_name');
            $table->string('factor_code', 12);
            $table->string('factor_name');
            $table->string('component_code', 15);
            $table->string('component_name');
            $table->tinyInteger('maturity_level')->unsigned();
            $table->smallInteger('sequence')->unsigned();
            $table->text('statement_text');
            $table->smallInteger('population_count')->unsigned()->default(1);
            $table->string('framework_version', 20)->default('FFIEC-CAT-1.1');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['domain_code', 'factor_code', 'component_code']);
            $table->index(['component_code', 'maturity_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ma_statements');
    }
};
