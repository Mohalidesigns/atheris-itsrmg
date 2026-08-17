<?php

namespace App\Services\Returns;

use App\Models\LegalEntity;
use App\Models\Returns\RegulatoryReturn;
use App\Models\Returns\ReturnCitation;
use App\Models\User;
use App\Services\Ea\AuditLogger;
use App\Services\Ea\QualitySealService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ReturnCompiler — ATH-EAR-002 A1, §8.3: "Compile a return from its
 * data-dependency graph; emit citations with seal state."
 *
 * §6.3 A1: "A return-generation engine that treats each regulatory submission
 * as a compiled artefact of the architecture graph plus the GRC data …
 * **Every answer carries evidence citations back to the EA entities that
 * produced it, with the quality-seal state shown so the CISO knows what is
 * trustworthy before signing.**"
 *
 * That last clause is not decoration. The April 2026 CSAT circular makes false
 * or misleading data a regulatory breach under BOFIA 2020, and the return is
 * CISO-signed. A compiler that produced confident-looking answers over
 * unverified data would be actively dangerous — which is why every mapper
 * reports its own evidence confidence and the seal state is snapshotted at
 * capture rather than looked up live.
 */
class ReturnCompiler
{
    /** @var array<string, class-string<ReturnMapper>> */
    public const TEMPLATES = [
        'csat' => CsatMapper::class,
        'itsb_maturity' => ItsbMaturityMapper::class,
        'ndpc_car' => NdpcCarMapper::class,
        'localisation_gap' => LocalisationGapMapper::class,
    ];

    public function __construct(private ?QualitySealService $seals = null)
    {
        $this->seals ??= new QualitySealService();
    }

    public static function templateOptions(): array
    {
        return collect(self::TEMPLATES)
            ->map(fn ($class, $key) => [
                'value' => $key,
                'label' => $class::name(),
                'regulator' => $class::regulator(),
                'description' => $class::description(),
                'cadence' => $class::cadence(),
            ])
            ->values()->all();
    }

    /**
     * Compile a return. Idempotent on (template, period, legal entity): a
     * second compile refreshes the payload rather than creating a rival
     * artefact — unless the existing one is signed, in which case it is
     * immutable (§10) and a new period must be opened instead.
     *
     * @throws \RuntimeException
     */
    public function compile(
        string $template,
        string $period,
        ?LegalEntity $entity = null,
        ?User $actor = null,
    ): RegulatoryReturn {
        $mapperClass = self::TEMPLATES[$template] ?? null;

        if (! $mapperClass) {
            throw new \RuntimeException("Unknown return template [{$template}].");
        }

        /** @var ReturnMapper $mapper */
        $mapper = new $mapperClass();

        $existing = RegulatoryReturn::where('template', $template)
            ->where('period', $period)
            ->where('legal_entity_id', $entity?->id)
            ->first();

        if ($existing && $existing->isSealed()) {
            throw new \RuntimeException(
                "The {$mapper::name()} for {$period} has already been signed and is immutable. "
                .'Open a new period, or supersede it.'
            );
        }

        $result = $mapper->compile($entity, $period);

        return DB::transaction(function () use ($existing, $template, $period, $entity, $actor, $mapper, $result) {
            $attributes = [
                'organization_id' => $entity?->organization_id ?? optional($actor)->organization_id,
                'legal_entity_id' => $entity?->id,
                'template' => $template,
                'name' => $mapper::name().' — '.$period.($entity ? ' — '.$entity->name : ''),
                'period' => $period,
                'due_date' => $mapper::dueDateFor($period),
                'regulator' => $mapper::regulator(),
                'payload' => $result['sections'],
                'summary' => $result['summary'],
                'completeness' => $result['completeness'],
                'evidence_confidence' => $result['evidence_confidence'],
                'generated_at' => now(),
            ];

            if ($existing) {
                $existing->update($attributes);
                $return = $existing;
                $return->citations()->delete();
            } else {
                $attributes['code'] = $this->nextCode($template, $period);
                $attributes['state'] = RegulatoryReturn::IN_PREPARATION;
                $attributes['owner_id'] = $actor?->id;
                // Period-on-period diff (§6.3 A1: "a diff against the prior
                // period") needs a pointer to what came before.
                $attributes['previous_return_id'] = RegulatoryReturn::where('template', $template)
                    ->where('legal_entity_id', $entity?->id)
                    ->where('period', '<', $period)
                    ->orderByDesc('period')
                    ->value('id');

                $return = RegulatoryReturn::create($attributes);
            }

            $this->recordCitations($return, $result['citations']);

            AuditLogger::logBare('return.compile', RegulatoryReturn::class, $return->id, [
                'template' => $template,
                'period' => $period,
                'completeness' => $result['completeness'],
                'evidence_confidence' => $result['evidence_confidence'],
                'citations' => count($result['citations']),
            ]);

            return $return->fresh();
        });
    }

    /**
     * Persist citations with the seal state **as it stands now**.
     *
     * §8.1 names the column `seal_state_at_capture` deliberately. If the seal
     * later expires, a return signed today must not retrospectively appear
     * weaker; equally it must never appear stronger than the evidence was.
     */
    private function recordCitations(RegulatoryReturn $return, array $citations): void
    {
        foreach ($citations as $citation) {
            $sealState = null;
            $completeness = null;

            if (! empty($citation['entity_type']) && ! empty($citation['entity_id'])) {
                $seal = \App\Models\Ea\QualitySeal::forEntity($citation['entity_type'], $citation['entity_id'])->first();
                $sealState = $seal?->state ?? 'unsealed';
                $completeness = $seal?->completeness;
            }

            ReturnCitation::create([
                'organization_id' => $return->organization_id,
                'return_id' => $return->id,
                'question_ref' => $citation['question_ref'],
                'entity_type' => $citation['entity_type'] ?? '',
                'entity_id' => $citation['entity_id'] ?? 0,
                'entity_label' => $citation['label'] ?? null,
                'seal_state_at_capture' => $sealState,
                'completeness_at_capture' => $completeness,
                'note' => $citation['note'] ?? null,
            ]);
        }
    }

    /**
     * Sign a return. Once signed it is hash-sealed and immutable — §10:
     * "Every generated return and evidence pack hash-sealed, immutable once
     * signed, with the signer and timestamp recorded."
     *
     * @throws \RuntimeException
     */
    public function sign(RegulatoryReturn $return, User $signer, string $role): RegulatoryReturn
    {
        if ($return->isSealed()) {
            throw new \RuntimeException('This return has already been signed.');
        }

        // A CISO signing under BOFIA 2020 should not be able to do so blind.
        // The block is deliberate: below the floor, the signature would be
        // attesting to data the platform itself cannot vouch for.
        $floor = (int) config('ea.returns.minimum_evidence_confidence', 30);

        if ($return->evidence_confidence < $floor) {
            throw new \RuntimeException(
                "Evidence confidence is {$return->evidence_confidence}%, below the {$floor}% floor required to sign. "
                .'Approve the quality seals on the cited records, or record the gap explicitly before signing.'
            );
        }

        $archive = $this->archive($return);

        $chain = $return->signoff_chain ?? [];
        $chain[] = [
            'role' => $role,
            'name' => $signer->name,
            'email' => $signer->email,
            'signed_at' => now()->toIso8601String(),
            'evidence_confidence' => $return->evidence_confidence,
        ];

        $return->update([
            'state' => RegulatoryReturn::SIGNED,
            'signed_by' => $signer->name,
            'signed_role' => $role,
            'signed_at' => now(),
            'signoff_chain' => $chain,
            'archive_path' => $archive['path'],
            'archive_hash' => $archive['hash'],
        ]);

        AuditLogger::logBare('return.sign', RegulatoryReturn::class, $return->id, [
            'signer' => $signer->name,
            'role' => $role,
            'hash' => $archive['hash'],
        ]);

        return $return->fresh();
    }

    /**
     * Write the immutable artefact and hash it.
     *
     * JSON rather than PDF: the hash has to be over something reproducible, and
     * §8.4 anticipates an integrator asking for the machine-readable form. A
     * rendered PDF can be generated from this at any time; the reverse is not
     * true.
     */
    private function archive(RegulatoryReturn $return): array
    {
        $document = [
            'code' => $return->code,
            'name' => $return->name,
            'template' => $return->template,
            'regulator' => $return->regulator,
            'period' => $return->period,
            'legal_entity' => $return->legalEntity?->name,
            'jurisdiction' => $return->legalEntity?->jurisdiction,
            'generated_at' => optional($return->generated_at)->toIso8601String(),
            'completeness' => $return->completeness,
            'evidence_confidence' => $return->evidence_confidence,
            'summary' => $return->summary,
            'sections' => $return->payload,
            'citations' => $return->citations()->get([
                'question_ref', 'entity_type', 'entity_id', 'entity_label',
                'seal_state_at_capture', 'completeness_at_capture',
            ])->toArray(),
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);
        $path = "returns/{$return->template}/{$return->code}.json";

        try {
            Storage::put($path, $json);
        } catch (\Throwable $e) {
            report($e);
        }

        return ['path' => $path, 'hash' => 'sha256:'.$hash];
    }

    /**
     * Period-on-period diff — §6.3 A1: "a diff against the prior period."
     *
     * The question a supervisor asks first is "what changed since last time",
     * and answering it from two PDFs is the reason returns take weeks.
     */
    public function diff(RegulatoryReturn $return): ?array
    {
        $previous = $return->previous;

        if (! $previous) {
            return null;
        }

        $current = collect($return->summary ?? []);
        $prior = collect($previous->summary ?? []);

        $changes = [];

        foreach ($current as $key => $value) {
            $before = $prior->get($key);

            if (! is_numeric($value) || ! is_numeric($before)) {
                continue;
            }

            if ((float) $value === (float) $before) {
                continue;
            }

            $changes[] = [
                'metric' => $key,
                'before' => $before,
                'after' => $value,
                'delta' => round((float) $value - (float) $before, 2),
                'direction' => $value > $before ? 'up' : 'down',
            ];
        }

        return [
            'previous_period' => $previous->period,
            'previous_code' => $previous->code,
            'completeness_delta' => $return->completeness - $previous->completeness,
            'confidence_delta' => $return->evidence_confidence - $previous->evidence_confidence,
            'changes' => $changes,
        ];
    }

    private function nextCode(string $template, string $period): string
    {
        $prefix = strtoupper(str_replace('_', '-', $template));
        $base = "{$prefix}-{$period}";
        $code = $base;
        $n = 2;

        while (RegulatoryReturn::where('code', $code)->exists()) {
            $code = "{$base}-{$n}";
            $n++;
        }

        return $code;
    }
}
