<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'organization_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->index();
            });
        }

        $tables = array_unique(array_map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t, Schema::getTableListing()));
        $tableOf = [];

        DB::table('activity_log')->whereNull('organization_id')->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$tableOf, $tables) {
                foreach ($rows as $row) {
                    $org = null;
                    if ($row->subject_type && $row->subject_id) {
                        $class = Relation::getMorphedModel($row->subject_type) ?? $row->subject_type;
                        if (! array_key_exists($class, $tableOf)) {
                            $tableOf[$class] = null;
                            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                                $t = (new $class)->getTable();
                                if (in_array($t, $tables, true) && Schema::hasColumn($t, 'organization_id')) {
                                    $tableOf[$class] = [$t, (new $class)->getKeyName()];
                                }
                            }
                        }
                        if ($tableOf[$class]) {
                            [$t, $key] = $tableOf[$class];
                            $org = DB::table($t)->where($key, $row->subject_id)->value('organization_id');
                        }
                    }
                    if (! $org && $row->subject_id && ($row->subject_type === Organization::class || $row->subject_type === 'organization')) {
                        $org = $row->subject_id;
                    }
                    if (! $org && $row->causer_id && $row->causer_type) {
                        $causerClass = Relation::getMorphedModel($row->causer_type) ?? $row->causer_type;
                        if ($causerClass === User::class) {
                            $org = DB::table('users')->where('id', $row->causer_id)->value('organization_id');
                        }
                    }
                    if ($org) {
                        DB::table('activity_log')->where('id', $row->id)->update(['organization_id' => $org]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_log', 'organization_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropIndex(['organization_id']);
                $table->dropColumn('organization_id');
            });
        }
    }
};
