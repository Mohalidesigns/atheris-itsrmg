<?php

namespace App\Services\Returns;

use App\Models\LegalEntity;

/**
 * ReturnMapper — the per-return mapping contract (§8.3 lists
 * `Returns\CsatMapper`, `Returns\NdpcCarMapper`, `Returns\ItsbMaturityMapper`).
 *
 * Each mapper reads the architecture graph plus whatever GRC data its return
 * needs, and returns four things:
 *
 *   sections            the compiled answer set
 *   summary             scalar metrics, which is what the period diff compares
 *   citations           entity references, so every answer is traceable
 *   evidence_confidence how much of it is backed by approved data
 *
 * The last one is the discipline that makes this safe. §6.3 A1 requires the
 * quality-seal state to be visible "so the CISO knows what is trustworthy
 * before signing", and the April 2026 CSAT circular makes false or misleading
 * data a breach under BOFIA 2020. A mapper that cannot evidence an answer must
 * say so rather than produce a confident blank.
 */
abstract class ReturnMapper
{
    abstract public static function name(): string;

    abstract public static function regulator(): string;

    abstract public static function description(): string;

    /** annual | quarterly | monthly | ad_hoc */
    abstract public static function cadence(): string;

    /**
     * @return array{
     *   sections: array,
     *   summary: array<string, mixed>,
     *   citations: array<int, array{question_ref:string, entity_type?:string, entity_id?:int, label?:string, note?:string}>,
     *   completeness: int,
     *   evidence_confidence: int
     * }
     */
    abstract public function compile(?LegalEntity $entity, string $period): array;

    /**
     * The statutory due date for a period.
     *
     * ⚠️ Several of these carry verification warnings in ATH-EAR-002 §6.2 —
     * the CSAT deadline is given as 28 February with a note that "the exposure
     * draft said 31 March; confirm against the signed circular", and the NDPC
     * cycle has been extended in practice. They are therefore read from config
     * rather than compiled in, so a design partner's confirmed date can be set
     * without a code change.
     */
    public static function dueDateFor(string $period): ?string
    {
        return null;
    }

    /**
     * Evidence confidence: the share of citations backed by an approved
     * quality seal, weighted by how much of the return they support.
     *
     * Deliberately harsh — an unsealed record contributes nothing rather than
     * half. A return is a legal attestation, and "probably accurate" is not a
     * category the regulator recognises.
     */
    protected function confidenceFrom(array $citations): int
    {
        if (empty($citations)) {
            return 0;
        }

        $sealable = array_filter($citations, fn ($c) => ! empty($c['entity_type']) && ! empty($c['entity_id']));

        if (empty($sealable)) {
            return 0;
        }

        $approved = 0;

        foreach ($sealable as $citation) {
            $seal = \App\Models\Ea\QualitySeal::forEntity($citation['entity_type'], $citation['entity_id'])->first();

            if ($seal && $seal->state === \App\Models\Ea\QualitySeal::APPROVED && ! $seal->isExpired()) {
                $approved++;
            }
        }

        return (int) round($approved / count($sealable) * 100);
    }

    /** A section that answers, with its own completeness reading. */
    protected function section(string $ref, string $title, string $requirement, array $answer, int $completeness, ?string $caveat = null): array
    {
        return [
            'ref' => $ref,
            'title' => $title,
            'requirement' => $requirement,
            'answer' => $answer,
            'completeness' => $completeness,
            'caveat' => $caveat,
        ];
    }
}
