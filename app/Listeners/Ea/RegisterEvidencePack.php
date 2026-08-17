<?php

namespace App\Listeners\Ea;

use App\Events\Ea\EvidencePackGenerated;
use App\Models\Ea\EvidencePack;
use App\Services\Ea\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract I-10 — EA evidence → Evidence Vault.
 *
 * §7.3: "Register as `EvidenceVaultItem` with hash, period and scope so
 * auditors find it where they look." Status before this: "Absent — packs sit on
 * local disk."
 *
 * That is the whole finding. EvidencePackGenerator already hash-seals its
 * output (§2.6 lists it among the module's genuine assets); the gap is that an
 * auditor opening the Evidence Vault sees nothing, so the seal protects a file
 * nobody can find.
 */
class RegisterEvidencePack
{
    public function handle(EvidencePackGenerated $event): void
    {
        if (! config('ea.contracts.evidence_to_vault', true)) {
            return;
        }

        if (! Schema::hasTable('evidence_vault')) {
            return;
        }

        $pack = $event->pack;

        // The pack is hash-sealed as a PDF with an optional ZIP of the
        // supporting evidence; the vault stores one row per artefact.
        $path = $pack->zip_path ?: $pack->pdf_path;
        $hash = $pack->zip_path ? ($pack->zip_hash ?: '') : ($pack->pdf_hash ?: '');

        if (! $path) {
            return;
        }

        // Idempotent on (subject, hash): regenerating a pack for the same
        // period with identical content must not create a second vault row.
        $existing = DB::table('evidence_vault')
            ->where('subject_type', EvidencePack::class)
            ->where('subject_id', $pack->id)
            ->where('sha256', $hash)
            ->exists();

        if ($existing) {
            return;
        }

        DB::table('evidence_vault')->insert([
            'organization_id' => $pack->organization_id,
            'subject_type' => EvidencePack::class,
            'subject_id' => $pack->id,
            'file_path' => $path,
            // The vault column is 64 chars; EA stores a longer prefixed hash.
            'sha256' => substr($hash, 0, 64),
            'bytes' => $this->sizeOf($path),
            'mime' => $pack->zip_path ? 'application/zip' : 'application/pdf',
            // §10 makes evidence immutable once signed. Nigerian switch
            // licence conditions require seven-year transaction logging
            // (§6.3 A7); a regulatory evidence pack is retained to match.
            'retention_until' => now()->addYears(7),
            'worm_locked' => true,
            'category' => 'ea_evidence_pack',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditLogger::logBare('contract.i10.register', EvidencePack::class, $pack->id, [
            'sha256' => substr($hash, 0, 64),
            'period' => $pack->period,
        ]);
    }

    private function sizeOf(string $path): int
    {
        try {
            return \Illuminate\Support\Facades\Storage::exists($path)
                ? (int) \Illuminate\Support\Facades\Storage::size($path)
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
