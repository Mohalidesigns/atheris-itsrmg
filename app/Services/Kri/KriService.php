<?php

namespace App\Services\Kri;

use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\KriReading;
use App\Models\User;
use App\Services\Issues\IssueWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KRI readings and breaches (ATH-VAL-014).
 *
 * A reading's status is computed from the KRI's thresholds in its direction, never taken from the
 * person entering it. One reading per reporting period (a second entry for the period replaces
 * the first). An amber or red reading opens a breach, or escalates the open one to red; a green
 * reading resolves it. A red breach raises one issue (source "kri") for the KRI owner, unless one
 * is already open. The owner records an action plan against each breach.
 *
 * Platform KRIs (source "platform") are measured from the bank's own records by KriMeasures, from
 * the "Measure now" action or the weekly `kri:measure` schedule.
 */
class KriService
{
    public function __construct(private KriMeasures $measures, private IssueWorkflow $issues) {}

    public function record(Kri $kri, float $value, ?User $actor, ?string $commentary = null, $at = null, bool $raiseIssues = true): KriReading
    {
        $at = $at ? now()->parse($at) : now();
        $period = $kri->periodFor($at);

        return DB::transaction(function () use ($kri, $value, $actor, $commentary, $at, $period, $raiseIssues) {
            $reading = KriReading::withoutGlobalScopes()->updateOrCreate(
                ['kri_id' => $kri->id, 'period' => $period],
                ['organization_id' => $kri->organization_id, 'value' => $value, 'status' => $kri->statusFor($value),
                    'commentary' => $commentary, 'recorded_by' => $actor?->id, 'recorded_at' => $at],
            );
            $this->breach($kri, $reading, $actor, $raiseIssues);

            return $reading;
        });
    }

    /** Measure a platform KRI now and record it. */
    public function measure(Kri $kri, ?User $actor = null, $at = null, bool $raiseIssues = true): KriReading
    {
        $kri->source === 'platform' && $this->measures->supports($kri->code)
            || throw ValidationException::withMessages(['kri' => 'This KRI is not measured from platform data; record its reading by hand.']);
        [$value, $note] = $this->measures->measure($kri->code, (int) $kri->organization_id, $at);

        return $this->record($kri, $value, $actor, $note, $at, $raiseIssues);
    }

    /** Measure every platform KRI, across banks (the scheduler). */
    public function measureAll(): int
    {
        $kris = Kri::withoutGlobalScopes()->where('source', 'platform')->whereNotNull('organization_id')->get()
            ->filter(fn ($k) => $this->measures->supports($k->code));
        $kris->each(fn ($k) => $this->measure($k));

        return $kris->count();
    }

    public function acknowledge(KriBreach $breach, string $plan, User $actor): void
    {
        $breach->resolved_at && throw ValidationException::withMessages(['breach' => 'This breach is already resolved.']);
        $breach->update(['action_plan' => $plan, 'acknowledged_by' => $actor->id, 'acknowledged_at' => now()]);
    }

    private function breach(Kri $kri, KriReading $reading, ?User $actor, bool $raiseIssues): void
    {
        $open = KriBreach::withoutGlobalScopes()->where('kri_id', $kri->id)->whereNull('resolved_at')->latest('occurred_at')->first();

        if ($reading->status === 'green') {
            $open?->update(['resolved_at' => $reading->recorded_at,
                'notes' => trim(($open->notes ? $open->notes."\n" : '')."Back within appetite in {$reading->period}".$this->valueText($kri, $reading->value, ' (%s)').'.')]);
            if ($open?->issue_id && ($issue = Issue::withoutGlobalScopes()->find($open->issue_id)) && $issue->isOpen()) {
                $this->issues->log($issue, 'comment', "{$kri->code} is green again in {$reading->period}. Ready for remediation sign-off.", $actor);
            }

            return;
        }

        if (! $open) {
            $open = KriBreach::withoutGlobalScopes()->create([
                'organization_id' => $kri->organization_id, 'kri_id' => $kri->id, 'reading_id' => $reading->id,
                'level' => $reading->status, 'occurred_at' => $reading->recorded_at,
                'notes' => ucfirst($reading->status)." in {$reading->period}".$this->valueText($kri, $reading->value, ': %s').'.',
            ]);
        } elseif ($open->level === 'amber' && $reading->status === 'red') {
            $open->update(['level' => 'red', 'reading_id' => $reading->id,
                'notes' => trim($open->notes."\nEscalated to red in {$reading->period}".$this->valueText($kri, $reading->value, ': %s').'.')]);
        }

        if ($raiseIssues && $open->level === 'red' && config('kri.auto_issue', true)) {
            $existing = $open->issue_id ? Issue::withoutGlobalScopes()->find($open->issue_id) : null;
            if (! $existing || ! $existing->isOpen()) {
                $issue = $this->issues->open([
                    'organization_id' => $kri->organization_id,
                    'title' => "KRI red: {$kri->name}",
                    'description' => $this->issueText($kri, $reading),
                    'severity' => 'high',
                    'source_type' => 'kri',
                    'source_id' => $kri->id,
                    'source_reference' => $kri->code,
                    'owner_id' => $kri->owner_id,
                ], $actor);
                $open->update(['issue_id' => $issue->id]);
            }
        }
    }

    /** Money KRIs (config finance.masked_kri_codes) carry no amount in notes or auto-raised Issues: those are visible beyond `view it-finance-amounts`. */
    private function valueText(Kri $kri, mixed $value, string $format): string
    {
        return $this->isMasked($kri) ? '' : sprintf($format, $kri->formatValue($value));
    }

    private function issueText(Kri $kri, KriReading $reading): string
    {
        $head = $this->isMasked($kri)
            ? "{$kri->code} breached its red limit in {$reading->period}, beyond its amber limit."
            : "{$kri->code} read ".$kri->formatValue($reading->value)." in {$reading->period}, beyond its amber limit (".$kri->formatValue($kri->threshold_amber).').';

        return $head.($kri->risk ? " Indicates risk {$kri->risk->risk_id_code} {$kri->risk->title}." : '');
    }

    private function isMasked(Kri $kri): bool
    {
        return in_array($kri->code, (array) config('finance.masked_kri_codes', []), true);
    }
}
