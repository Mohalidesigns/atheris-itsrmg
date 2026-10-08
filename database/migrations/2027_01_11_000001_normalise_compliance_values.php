<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gaps were written with two vocabularies: the Compliance module's (critical/high/medium/low,
 * identified … closed) and ISO audit terms from the ISMS seeders (major/minor, open/resolved).
 * Neither module's filters, colours or open-gap counts matched the other's rows. Normalise to
 * the Gap model's scale; ISMS pages present it with non-conformity labels.
 */
return new class extends Migration
{
    private const SEVERITY = ['major' => 'high', 'moderate' => 'medium', 'minor' => 'low'];

    private const STATUS = ['open' => 'identified', 'resolved' => 'remediated'];

    public function up(): void
    {
        foreach (self::SEVERITY as $from => $to) {
            DB::table('gaps')->where('severity', $from)->update(['severity' => $to]);
        }
        foreach (self::STATUS as $from => $to) {
            DB::table('gaps')->where('status', $from)->update(['status' => $to]);
        }

        DB::table('controls')->whereNull('effectiveness')->update(['effectiveness' => 'not_assessed']);
    }

    public function down(): void
    {
        // Lossless reversal is not possible (high was already a valid value); nothing to undo.
    }
};
