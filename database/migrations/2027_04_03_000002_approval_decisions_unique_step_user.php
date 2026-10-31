<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** P7 M3: one decision per person per step, enforced by the database so two concurrent approvals cannot both land. */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse any historic repeats (keep the earliest) so the index can be built; NULL user_ids (deleted users) never collide.
        $dupes = DB::table('approval_decisions')->whereNotNull('user_id')->select('step_id', 'user_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('step_id', 'user_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dupes as $d) {
            DB::table('approval_decisions')->where('step_id', $d->step_id)->where('user_id', $d->user_id)->where('id', '!=', $d->keep_id)->delete();
        }
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->unique(['step_id', 'user_id'], 'approval_decisions_step_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropUnique('approval_decisions_step_user_unique');
        });
    }
};
