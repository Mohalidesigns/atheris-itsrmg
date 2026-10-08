<?php

namespace Database\Seeders;

use App\Modules\CBNCSAT\Models\CsatMaNarrative;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CsatMaNarrativesTemplateSeeder extends Seeder
{
    public function run(): void
    {

        // Every assessment (both demo banks) gets the narrative questions.
        foreach (DB::table('csat_assessments')->pluck('id') as $assessmentId) {
            CsatMaNarrative::provisionFor($assessmentId);
        }

    }
}
