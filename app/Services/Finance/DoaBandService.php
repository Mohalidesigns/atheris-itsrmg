<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\Setting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioned delegation-of-authority bands with maker-checker (ATH-FIN-003 C8/S7/D5, plan C29).
 * A bank proposes new bands, a second person approves, and on the effective date the version
 * becomes active: fin_settings.doa_bands is rewritten and Workflow Studio stage conditions on
 * `amount_band` are re-mapped by ordinal, with every remap recorded in the audit event.
 */
class DoaBandService
{
    public function __construct(private readonly FinanceSettings $settings) {}

    /** The version in force on a date (default today); v1 is created from settings when a bank has none. */
    public function active(int $orgId, ?CarbonInterface $on = null): DoaBandVersion
    {
        $on ??= now();
        $q = fn () => DoaBandVersion::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active');
        $found = $q()->whereDate('effective_from', '<=', $on->toDateString())->orderByDesc('effective_from')->orderByDesc('version_no')->first()
            ?? $q()->orderBy('effective_from')->orderBy('version_no')->first();
        if ($found) {
            return $found;
        }

        $setting = $this->settings->for($orgId);

        return DoaBandVersion::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'version_no' => (int) DoaBandVersion::withoutGlobalScopes()->where('organization_id', $orgId)->max('version_no') + 1,
            'bands' => $setting->doa_bands ?: config('finance.settings_defaults.doa_bands'),
            'effective_from' => $setting->created_at?->toDateString() ?? now()->toDateString(), 'status' => 'active',
            'reason' => 'Initial bands', 'proposed_at' => now(), 'activated_at' => now(),
        ]);
    }

    /** @return array{key:string,label:string,min:mixed,max:mixed,version_id:int,version_no:int} */
    public function bandFor(int $orgId, string|float|int $baseAmount, ?CarbonInterface $on = null): array
    {
        $version = $this->active($orgId, $on);
        $bands = $version->bands;
        $amount = (string) $baseAmount;
        $hit = end($bands);
        foreach ($bands as $band) {
            $aboveMin = bccomp($amount, (string) $band['min'], 2) >= 0;
            $belowMax = $band['max'] === null || bccomp($amount, (string) $band['max'], 2) < 0;
            if ($aboveMin && $belowMax) {
                $hit = $band;
                break;
            }
        }

        return ['key' => $hit['key'], 'label' => $hit['label'], 'min' => $hit['min'], 'max' => $hit['max'],
            'version_id' => $version->id, 'version_no' => $version->version_no];
    }

    /** key => "10m-50m (NGN 10,000,000 to NGN 50,000,000)" for Workflow Studio condition pickers. */
    public function conditionValues(int $orgId): array
    {
        $code = $this->settings->baseCurrency($orgId);
        $fmt = fn ($n) => $code.' '.number_format((float) $n);
        $out = [];
        foreach ($this->active($orgId)->bands as $b) {
            $out[$b['key']] = $b['key'].' ('.($b['max'] === null ? $fmt($b['min']).' and above' : $fmt($b['min']).' to '.$fmt($b['max'])).')';
        }

        return $out;
    }

    public function propose(int $orgId, array $bands, string $effectiveFrom, ?string $reason, User $maker): DoaBandVersion
    {
        $this->settings->validateBands(array_values($bands));
        $bands = $this->settings->bandsWithKeys(array_values($bands));
        if (Carbon::parse($effectiveFrom)->startOfDay()->lt(today())) {
            $this->fail('The effective date cannot be in the past.', 'effective_from');
        }
        if (DoaBandVersion::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['pending', 'scheduled'])->exists()) {
            $this->fail('Another band version is already waiting for approval or its effective date.');
        }
        $this->active($orgId); // make sure version 1 exists

        $version = DoaBandVersion::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $maker->id,
            'version_no' => (int) DoaBandVersion::withoutGlobalScopes()->where('organization_id', $orgId)->max('version_no') + 1,
            'bands' => $bands, 'effective_from' => $effectiveFrom, 'status' => 'pending', 'reason' => $reason,
            'proposed_by_id' => $maker->id, 'proposed_at' => now(),
        ]);
        $this->audit($version, $maker, 'doa_bands.proposed', 'DoA bands proposed (version '.$version->version_no.')', ['version_no' => $version->version_no, 'new_bands' => $bands, 'effective_from' => $effectiveFrom]);

        return $version;
    }

    public function approve(DoaBandVersion $v, User $checker, ?string $note = null): DoaBandVersion
    {
        $this->assertPending($v);
        $this->assertChecker($v, $checker, 'approve');
        $this->assertNoRemovedBandInUse($v);

        $v->forceFill(['decided_by_id' => $checker->id, 'decided_at' => now(), 'decision_note' => $note]);
        $v->status = $v->effective_from->lte(today()) ? 'pending' : 'scheduled';
        $v->save();
        $this->audit($v, $checker, 'doa_bands.approved', 'DoA bands approved (version '.$v->version_no.')', [
            'version_no' => $v->version_no, 'maker_id' => $v->proposed_by_id, 'checker_id' => $checker->id, 'effective_from' => $v->effective_from->toDateString(),
        ]);
        if ($v->effective_from->lte(today())) {
            $this->activate($v);
        }

        return $v->refresh();
    }

    public function reject(DoaBandVersion $v, User $checker, string $note): DoaBandVersion
    {
        $this->assertPending($v);
        $this->assertChecker($v, $checker, 'reject');
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Give a reason for rejecting the bands.']);
        }
        $v->forceFill(['status' => 'rejected', 'decided_by_id' => $checker->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit($v, $checker, 'doa_bands.rejected', 'DoA bands rejected (version '.$v->version_no.')', ['version_no' => $v->version_no, 'note' => $note]);

        return $v;
    }

    public function withdraw(DoaBandVersion $v, User $maker): DoaBandVersion
    {
        if (! in_array($v->status, ['pending', 'scheduled'], true)) {
            $this->fail("A {$v->status} band version cannot be withdrawn.");
        }
        if ((int) $v->proposed_by_id !== (int) $maker->id) {
            $this->fail('Only the person who proposed the bands can withdraw them.');
        }
        $v->forceFill(['status' => 'withdrawn'])->save();
        $this->audit($v, $maker, 'doa_bands.withdrawn', 'DoA bands withdrawn (version '.$v->version_no.')', ['version_no' => $v->version_no]);

        return $v;
    }

    public function activate(DoaBandVersion $v): void
    {
        DB::transaction(function () use ($v) {
            $old = DoaBandVersion::withoutGlobalScopes()->where('organization_id', $v->organization_id)->where('status', 'active')->where('id', '!=', $v->id)->get();
            $oldBands = $old->sortByDesc('version_no')->first()?->bands ?? [];
            foreach ($old as $o) {
                $o->forceFill(['status' => 'superseded', 'superseded_at' => now()])->save();
            }
            $v->forceFill(['status' => 'active', 'activated_at' => now()])->save();
            Setting::withoutGlobalScopes()->where('organization_id', $v->organization_id)->update(['doa_bands' => json_encode($v->bands)]);
            $this->settings->flush();

            $remapped = $this->remapStages($v, $oldBands);
            $this->audit($v, null, 'doa_bands.activated', 'DoA bands activated (version '.$v->version_no.')', [
                'version_no' => $v->version_no, 'old_bands' => $oldBands, 'new_bands' => $v->bands, 'effective_from' => $v->effective_from->toDateString(),
                'maker_id' => $v->proposed_by_id, 'checker_id' => $v->decided_by_id, 'remapped_stages' => $remapped,
            ]);
        });
    }

    /** Activate every scheduled version whose effective date has arrived (fin:doa-activate). */
    public function activateDue(): int
    {
        $n = 0;
        foreach (DoaBandVersion::withoutGlobalScopes()->where('status', 'scheduled')->whereDate('effective_from', '<=', today()->toDateString())->orderBy('id')->get() as $v) {
            $this->activate($v);
            $n++;
        }

        return $n;
    }

    /** @return array<int, array{stage_id:int,from:string,to:string}> */
    private function remapStages(DoaBandVersion $v, array $oldBands): array
    {
        $oldKeys = array_column($oldBands, 'key');
        $newKeys = array_column($v->bands, 'key');
        if (! $oldKeys || $oldKeys === $newKeys) {
            return [];
        }
        $log = [];
        foreach ($this->bandStages($v->organization_id) as $stage) {
            $new = [];
            foreach ((array) $stage->condition_values as $key) {
                $i = array_search($key, $oldKeys, true);
                $to = $i === false ? (in_array($key, $newKeys, true) ? $key : end($newKeys)) : ($newKeys[$i] ?? end($newKeys));
                if ($to !== $key) {
                    $log[] = ['stage_id' => $stage->id, 'from' => $key, 'to' => $to];
                }
                $new[] = $to;
            }
            // "From X upward" stages that reached the old top band must also reach every band added above it.
            $oldTop = end($oldKeys);
            if (in_array($oldTop, (array) $stage->condition_values, true) && count($newKeys) > count($oldKeys)) {
                foreach (array_slice($newKeys, count($oldKeys)) as $extra) {
                    $new[] = $extra;
                    $log[] = ['stage_id' => $stage->id, 'from' => $oldTop, 'to' => $extra];
                }
            }
            $new = array_values(array_unique($new));
            if ($new !== array_values((array) $stage->condition_values)) {
                $stage->forceFill(['condition_values' => $new])->save();
            }
        }

        return $log;
    }

    private function bandStages(int $orgId)
    {
        $ids = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $orgId)->where('process', 'like', 'finance.%')->pluck('id');

        return ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('workflow_id', $ids)
            ->where('condition_field', 'amount_band')->where('is_active', true)->get();
    }

    private function assertNoRemovedBandInUse(DoaBandVersion $v): void
    {
        $oldKeys = array_column($this->active($v->organization_id)->bands, 'key');
        $count = count($v->bands);
        foreach ($this->bandStages($v->organization_id) as $stage) {
            foreach ((array) $stage->condition_values as $key) {
                $i = array_search($key, $oldKeys, true);
                if ($i !== false && $i >= $count) {
                    $this->fail("Stage \"{$stage->name}\" is limited to band {$key}, which the new bands remove. Edit the stage in Workflow Studio first.");
                }
            }
        }
    }

    private function assertPending(DoaBandVersion $v): void
    {
        if ($v->status !== 'pending') {
            $this->fail("A {$v->status} band version cannot be decided.");
        }
    }

    private function assertChecker(DoaBandVersion $v, User $checker, string $verb): void
    {
        if ((int) $v->proposed_by_id === (int) $checker->id) {
            $this->fail("Maker-checker: the person who proposed the bands cannot {$verb} them.");
        }
        if ((int) $checker->organization_id !== (int) $v->organization_id) {
            $this->fail('These bands belong to another bank.');
        }
    }

    private function audit(DoaBandVersion $v, ?User $by, string $event, string $description, array $props): void
    {
        $logger = activity('it-finance')->performedOn($v)->event($event)->withProperties($props);
        if ($by) {
            $logger->causedBy($by);
        }
        $logger->log($description);
    }

    private function fail(string $message, string $key = 'workflow'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
