<?php

namespace App\Services\Monitoring;

use App\Models\Asset;
use App\Models\Change\ChangeRequest;
use App\Models\ConfigDriftEvent;
use App\Models\Issue;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Issues\IssueWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Configuration-drift register (ATH-VAL-013).
 *
 * Detection: each source scans the bank's own registered assets. No bank has connected AWS Config,
 * Defender, Wazuh or Tenable, so a scan runs in sandbox mode against a deterministic catalogue of
 * baseline deviations for the asset types that source covers. An open event for the same asset and
 * deviation is not duplicated.
 *
 * Reconciliation: a deviation on an asset inside the window of an approved change that lists the
 * asset is "authorised" and linked to the change; anything else is unauthorised drift and stays
 * open until it is remediated, accepted (by someone other than its owner, with an expiry) or
 * linked to a change by hand. Unauthorised critical drift raises an issue when detected.
 */
class DriftService
{
    /** Change states in which the change was authorised to alter the asset. */
    public const AUTHORISING_CHANGE_STATES = ['approved', 'scheduled', 'implementing', 'implemented', 'review', 'closed'];

    /** Source => asset filter and the deviations it reports: [title, severity, baseline, observed]. */
    public const CATALOGUE = [
        'aws_config' => ['types' => ['cloud_service'], 'deviations' => [
            ['Security group allows SSH (22) from 0.0.0.0/0', 'critical', 'Ingress 22/tcp from 10.0.0.0/8 only', 'Ingress 22/tcp from 0.0.0.0/0'],
            ['S3 public-access block removed', 'high', 'BlockPublicAcls = true', 'BlockPublicAcls = false'],
            ['CloudTrail logging disabled', 'high', 'Trail enabled, multi-region', 'Trail stopped'],
        ]],
        'defender' => ['types' => ['database'], 'deviations' => [
            ['Encryption at rest disabled', 'critical', 'TDE enabled', 'TDE disabled'],
            ['SQL audit policy disabled', 'high', 'Auditing to Log Analytics', 'Auditing off'],
            ['TLS 1.0 enabled on listener', 'medium', 'Minimum TLS 1.2', 'Minimum TLS 1.0'],
        ]],
        'wazuh' => ['types' => ['hardware'], 'categories' => ['Server', 'Infrastructure'], 'deviations' => [
            ['sshd_config changed: PermitRootLogin yes', 'high', 'PermitRootLogin no', 'PermitRootLogin yes'],
            ['Audit log forwarding stopped', 'high', 'rsyslog → SIEM', 'rsyslog stopped'],
            ['Local administrator group membership changed', 'medium', '3 members (baseline)', '5 members'],
        ]],
        'tenable' => ['types' => ['hardware'], 'categories' => ['Network'], 'deviations' => [
            ['Telnet enabled on management interface', 'high', 'SSH only', 'Telnet + SSH'],
            ['Firewall rule added: any → DMZ 3389', 'critical', 'No inbound RDP', 'Permit any → DMZ 3389/tcp'],
            ['SNMP v2c community "public" enabled', 'medium', 'SNMP v3 only', 'SNMP v2c, community public'],
        ]],
    ];

    public function __construct(private IssueWorkflow $issues) {}

    /** Scan one source for the user's bank; returns [found, new, authorised]. */
    public function scan(string $source, User $user): array
    {
        abort_unless(isset(self::CATALOGUE[$source]), 422, 'Unknown drift source.');
        $orgId = (int) $user->organization_id;
        $found = $this->detect($source, $orgId, today()->toDateString());
        $new = 0;
        $authorised = 0;
        foreach ($found as $f) {
            $event = $this->record($orgId, $f + ['source' => $source, 'detected_at' => now()], $user);
            if ($event) {
                $new++;
                $authorised += $event->status === 'authorised' ? 1 : 0;
            }
        }

        return [count($found), $new, $authorised];
    }

    /** Deterministic deviations for a source and day: [asset, title, severity, baseline, observed]. */
    public function detect(string $source, int $orgId, string $day): array
    {
        $cfg = self::CATALOGUE[$source];
        $assets = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')
            ->whereIn('asset_type', $cfg['types'])
            ->when($cfg['categories'] ?? null, fn ($q, $c) => $q->whereIn('category', $c))
            ->orderBy('id')->get(['id', 'name', 'owner_id']);
        if ($assets->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($cfg['deviations'] as $i => [$title, $severity, $baseline, $observed]) {
            $h = crc32("{$orgId}|{$source}|{$i}|{$day}");
            if ($h % 3 === 0) {
                continue; // not every deviation shows up on every scan
            }
            $asset = $assets[$h % $assets->count()];
            $out[] = ['asset' => $asset, 'title' => $title, 'severity' => $severity, 'baseline' => $baseline, 'observed' => $observed];
        }

        return $out;
    }

    /** Record a detected deviation (null when an open event already covers it). */
    public function record(int $orgId, array $f, ?User $actor): ?ConfigDriftEvent
    {
        $asset = $f['asset'];
        if (ConfigDriftEvent::withoutGlobalScopes()->where('organization_id', $orgId)->where('asset_id', $asset->id)
            ->where('title', $f['title'])->whereIn('status', ConfigDriftEvent::OPEN_STATUSES)->exists()) {
            return null;
        }
        $detected = now()->parse($f['detected_at']);
        $change = $this->authorisingChange($orgId, $asset->id, $detected);

        $event = ConfigDriftEvent::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'drift_code' => $f['code'] ?? ConfigDriftEvent::generateNextCode($orgId),
            'asset_id' => $asset->id,
            'source' => $f['source'],
            'title' => $f['title'],
            'description' => $f['description'] ?? "{$asset->name} no longer matches its configuration baseline.",
            'baseline_value' => $f['baseline'] ?? null,
            'observed_value' => $f['observed'] ?? null,
            'severity' => $f['severity'],
            'detected_at' => $detected,
            'status' => $change ? 'authorised' : 'open',
            'owner_id' => $asset->owner_id,
            'change_request_id' => $change?->id,
            'resolved_at' => $change ? $detected : null,
            'resolution_note' => $change ? "Within the window of approved change {$change->change_code}." : null,
        ]);

        if (! $change && in_array($event->severity, config('ccm.drift_issue_severities', ['critical']), true)) {
            $this->raiseIssue($event, $actor);
        }

        return $event;
    }

    /** An approved change that lists the asset and whose window covers the detection time. */
    public function authorisingChange(int $orgId, int $assetId, $at): ?ChangeRequest
    {
        $at = now()->parse($at);

        return $this->changesFor($orgId, $assetId)->first(function (ChangeRequest $c) use ($at) {
            $start = $c->actual_start_at ?? $c->scheduled_start_at;
            $end = $c->actual_end_at ?? $c->scheduled_end_at ?? $start?->copy()->addHours(8);

            return $start && $at->between($start->copy()->subHours(2), $end->copy()->addDay());
        });
    }

    /** The bank's authorising changes that list an asset. */
    public function changesFor(int $orgId, int $assetId): Collection
    {
        return ChangeRequest::withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereIn('status', self::AUTHORISING_CHANGE_STATES)->whereNotNull('affected_asset_ids')
            ->orderByDesc('scheduled_start_at')->get()
            ->filter(fn ($c) => in_array($assetId, array_map('intval', (array) $c->affected_asset_ids), true))->values();
    }

    /** Returns a message when Workflow Studio stages remain (the drift stays open), else null. */
    public function transition(ConfigDriftEvent $event, string $action, User $actor, array $input = []): ?string
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['drift' => $m]);

        switch ($action) {
            case 'investigate':
                $event->status === 'open' || $fail('Only open drift can be taken into investigation.');
                $event->update(['status' => 'investigating', 'owner_id' => $input['owner_id'] ?? $event->owner_id ?? $actor->id]);
                break;

            case 'remediate':
                $event->isOpen() || $fail('This drift is already closed.');
                app(ApprovalEngine::class)->cancel('drift.acceptance', $event, 'Remediated instead');
                $event->update(['status' => 'remediated', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $input['note']]);
                break;

            case 'accept':
                $event->isOpen() || $fail('This drift is already closed.');
                (int) $event->owner_id === (int) $actor->id && $fail('The owner of the drift cannot accept its risk; another approver must.');
                if ($pending = app(ApprovalEngine::class)->gate('drift.acceptance', $event, $actor, $input['note'] ?? null)) {
                    return $pending;
                }
                $event->update(['status' => 'accepted', 'resolved_by' => $actor->id, 'resolved_at' => now(),
                    'resolution_note' => $input['note'], 'accepted_until' => $input['accepted_until']]);
                break;

            case 'authorise':
                $event->isOpen() || $fail('This drift is already closed.');
                $change = $this->changesFor($event->organization_id, (int) $event->asset_id)->firstWhere('id', (int) ($input['change_request_id'] ?? 0));
                $change || throw ValidationException::withMessages(['change_request_id' => 'Choose an approved change that lists this asset.']);
                $event->update(['status' => 'authorised', 'change_request_id' => $change->id, 'resolved_by' => $actor->id, 'resolved_at' => now(),
                    'resolution_note' => $input['note'] ?? "Linked to approved change {$change->change_code}."]);
                break;

            case 'reopen':
                in_array($event->status, ['remediated', 'accepted'], true) || $fail('Only remediated or accepted drift can be reopened.');
                $event->update(['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'accepted_until' => null,
                    'resolution_note' => $input['note'] ?? null]);
                break;

            case 'raise_issue':
                $event->isOpen() || $fail('This drift is already closed.');
                $open = $event->issue_id ? Issue::find($event->issue_id) : null;
                $open && $open->isOpen() && $fail("Issue {$open->issue_code} is already open for this drift.");
                $this->raiseIssue($event, $actor);
                break;

            default:
                $fail('Unknown action.');
        }

        return null;
    }

    private function raiseIssue(ConfigDriftEvent $event, ?User $actor): Issue
    {
        $event->loadMissing('asset');
        $issue = $this->issues->open([
            'organization_id' => $event->organization_id,
            'title' => "Unauthorised drift: {$event->title}".($event->asset ? " on {$event->asset->name}" : ''),
            'description' => 'Detected by '.(ConfigDriftEvent::SOURCES[$event->source] ?? $event->source).' on '.$event->detected_at->format('d M Y H:i')
                .". Baseline: {$event->baseline_value}. Observed: {$event->observed_value}. No approved change covers it.",
            'severity' => $event->severity === 'medium' ? 'moderate' : $event->severity,
            'source_type' => 'config_drift',
            'source_id' => $event->id,
            'source_reference' => $event->drift_code,
            'owner_id' => $event->owner_id,
        ], $actor);
        $event->update(['issue_id' => $issue->id]);

        return $issue;
    }
}
