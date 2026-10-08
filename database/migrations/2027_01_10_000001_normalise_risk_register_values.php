<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings stored risk-register values onto the canonical sets in App\Models\Risk:
 *  - legacy status 'in_progress' → 'treating'
 *  - legacy sources from the old create form → canonical source keys
 *  - inherent/residual ratings recomputed from the score on the single 5×5 scale
 *    (critical ≥20, high ≥12, medium ≥6, low ≥3, very_low ≥1)
 *
 * Driver-agnostic (CASE expressions only) so it runs on SQLite and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('risks')->where('status', 'in_progress')->update(['status' => 'treating']);

        DB::table('risks')->whereIn('source', ['assessment', 'self_identified', 'self_assessment'])
            ->update(['source' => 'self-assessment']);

        foreach (['inherent', 'residual'] as $type) {
            DB::table('risks')->update([
                "{$type}_rating" => DB::raw(
                    "CASE WHEN {$type}_score IS NULL OR {$type}_score < 1 THEN NULL
                          WHEN {$type}_score >= 20 THEN 'critical'
                          WHEN {$type}_score >= 12 THEN 'high'
                          WHEN {$type}_score >= 6 THEN 'medium'
                          WHEN {$type}_score >= 3 THEN 'low'
                          ELSE 'very_low' END"
                ),
            ]);
        }

        DB::table('risk_assessments')->whereNotNull('score')->update([
            'rating' => DB::raw(
                "CASE WHEN score >= 20 THEN 'critical'
                      WHEN score >= 12 THEN 'high'
                      WHEN score >= 6 THEN 'medium'
                      WHEN score >= 3 THEN 'low'
                      ELSE 'very_low' END"
            ),
        ]);
    }

    public function down(): void
    {
        // Data normalisation only — nothing to reverse.
    }
};
