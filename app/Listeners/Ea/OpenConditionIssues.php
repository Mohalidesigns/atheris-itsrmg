<?php

namespace App\Listeners\Ea;

use App\Events\Ea\ArbDecisionRecorded;
use App\Models\Ea\ArbSubmission;
use App\Models\Issue;
use App\Services\Ea\AuditLogger;

/**
 * Contract I-9 — ARB decision → Issue.
 *
 * §7.3: "ARB conditions raise `Issue` records with owners and due dates."
 * Status before this: "Absent — both are self-contained EA state changes."
 *
 * An approval-with-conditions that leaves the conditions in the minutes is not
 * governance, it is a paper trail. Turning each condition into an owned Issue
 * with a due date is what makes the ARB's authority real — and it is what an
 * assessor looks for when testing whether architecture governance operates.
 */
class OpenConditionIssues
{
    public function handle(ArbDecisionRecorded $event): void
    {
        if (! config('ea.contracts.arb_to_issues', true)) {
            return;
        }

        $submission = $event->submission;

        // Only an approval carries conditions. A rejection ends the matter and
        // a deferral has no conditions to track yet.
        if ($submission->status !== 'approved') {
            return;
        }

        $conditions = $this->conditions($event, $submission);

        foreach ($conditions as $index => $condition) {
            $text = trim(is_array($condition) ? ($condition['text'] ?? '') : (string) $condition);

            if ($text === '') {
                continue;
            }

            // Stable per (submission, ordinal) so a re-decide updates rather
            // than duplicating — §7.5 requires every listener to be idempotent.
            $reference = $submission->id.'-'.($index + 1);

            $existing = Issue::withoutGlobalScopes()
                ->where('source_type', 'ea_arb_condition')
                ->where('external_ticket_id', $reference)
                ->first();

            if ($existing) {
                continue;
            }

            $issue = Issue::create([
                'organization_id' => $submission->organization_id,
                'source_type' => 'ea_arb_condition',
                'source_id' => $submission->id,
                'title' => 'ARB condition: '.\Illuminate\Support\Str::limit($text, 200),
                'description' => $this->describe($submission, $text),
                'severity' => $this->severity($submission->risk_band),
                'owner_id' => is_array($condition) ? ($condition['owner_id'] ?? null) : null,
                'due_date' => $this->dueDate($condition, $submission),
                'status' => 'open',
                'external_ticket_id' => $reference,
                'external_ticket_system' => 'ea_arb',
            ]);

            AuditLogger::logBare('contract.i9.issue', ArbSubmission::class, $submission->id, [
                'issue_id' => $issue->id,
                'condition' => \Illuminate\Support\Str::limit($text, 120),
            ]);
        }
    }

    /**
     * Conditions come either from the event payload or from the submission's
     * own fields, depending on how the decision was recorded.
     */
    private function conditions(ArbDecisionRecorded $event, ArbSubmission $submission): array
    {
        if (! empty($event->conditions)) {
            return $event->conditions;
        }

        $stored = $submission->conditions ?? null;

        if (is_array($stored)) {
            return $stored;
        }

        // Free-text rationale listing conditions one per line is the common
        // real-world shape; split rather than lose them.
        if (is_string($stored) && trim($stored) !== '') {
            return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $stored))));
        }

        return [];
    }

    private function severity(?string $riskBand): string
    {
        return match (strtolower((string) $riskBand)) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'moderate',
            default => 'low',
        };
    }

    private function dueDate($condition, ArbSubmission $submission): string
    {
        if (is_array($condition) && ! empty($condition['due_date'])) {
            return $condition['due_date'];
        }

        // Higher-risk approvals get a tighter clock.
        return now()->addDays(match (strtolower((string) $submission->risk_band)) {
            'critical' => 14,
            'high' => 30,
            'medium' => 60,
            default => 90,
        })->toDateString();
    }

    private function describe(ArbSubmission $submission, string $condition): string
    {
        return implode("\n", array_filter([
            "Raised automatically from Architecture Review Board submission {$submission->code}.",
            '',
            'Condition:',
            $condition,
            '',
            "Submission: {$submission->title}",
            $submission->decided_by ? "Decided by: {$submission->decided_by}" : null,
            $submission->decided_at ? "Decided on: {$submission->decided_at->toDateString()}" : null,
            '',
            'The approval stands only while this condition is being met.',
        ]));
    }
}
