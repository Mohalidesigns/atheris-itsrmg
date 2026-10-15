<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('sso_connections', 'idp_entity_id')) {
                $table->string('idp_entity_id')->nullable();
            }
            if (! Schema::hasColumn('sso_connections', 'sso_url')) {
                $table->string('sso_url')->nullable();
            }
        });

        Schema::table('scim_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('scim_tokens', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable();
            }
            if (! Schema::hasColumn('scim_tokens', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (! Schema::hasColumn('scim_tokens', 'token_prefix')) {
                $table->string('token_prefix', 16)->nullable();
            }
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('scim_tokens', function (Blueprint $table) {
            $table->dropIndex(['token_hash']);
            $table->dropColumn(['revoked_at', 'created_by', 'token_prefix']);
        });
        Schema::table('sso_connections', function (Blueprint $table) {
            $table->dropColumn(['idp_entity_id', 'sso_url']);
        });
    }
};
