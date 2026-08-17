<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('country', 2)->default('NG');
            $table->string('regulator_primary')->default('CBN');
            $table->string('licence_type')->nullable();
            $table->enum('hosting_mode', ['saas-shared', 'saas-dedicated', 'on-prem'])->default('saas-shared');
            $table->enum('status', ['active', 'suspended', 'archived'])->default('active');
            $table->string('tier')->default('professional');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
