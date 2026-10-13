<?php

namespace App\Services\Issues;

use App\Models\Issue;
use App\Models\ItsmConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes issues to a bank's ITSM tool and mirrors ticket status back.
 *
 * - Live mode calls the tool's REST API (Jira issue, ServiceNow incident, Freshservice ticket).
 * - Sandbox mode makes no call: it records a ticket reference in the tool's own format so the flow
 *   can be demonstrated and tested end to end. The UI labels sandbox tickets as such.
 * - Status comes back through POST /itsm/webhook/{connection}, signed like the SIEM webhook:
 *   X-Atheris-Signature = "sha256=" . HMAC-SHA256(secret, timestamp . "." . body).
 *   A ticket resolved in the tool marks the issue remediated (when its root cause and plan are
 *   recorded); verification stays a human step in Atheris.
 */
class ItsmConnector
{
    private const RESOLVED_STATES = ['done', 'resolved', 'closed', 'complete', 'completed', 'fixed', '4', '5', '6', '7'];

    public function __construct(private IssueWorkflow $workflow) {}

    /** @return array{ok: bool, message: string} */
    public function test(ItsmConnection $connection): array
    {
        if ($connection->mode === 'sandbox') {
            $result = ['ok' => true, 'message' => 'Sandbox connector: no call made. Tickets are recorded locally.'];
        } else {
            try {
                $path = ['jira' => '/rest/api/2/myself', 'servicenow' => '/api/now/table/sys_user?sysparm_limit=1', 'freshservice' => '/api/v2/agents/me'][$connection->system];
                $response = $this->client($connection)->get($path);
                $result = $response->successful()
                    ? ['ok' => true, 'message' => 'Connected: '.$connection->label().' answered HTTP '.$response->status().'.']
                    : ['ok' => false, 'message' => $connection->label().' answered HTTP '.$response->status().'. Check the URL and credentials.'];
            } catch (Throwable $e) {
                $result = ['ok' => false, 'message' => 'Could not reach '.$connection->base_url.': '.Str::limit($e->getMessage(), 140)];
            }
        }

        $connection->update([
            'last_tested_at' => now(),
            'last_test_ok' => $result['ok'],
            'last_test_message' => $result['message'],
            'status' => $connection->status === 'disabled' ? 'disabled' : ($result['ok'] ? 'active' : 'error'),
        ]);

        return $result;
    }

    /** Create the ticket for an issue. Returns the ticket reference, or throws with a readable message. */
    public function push(Issue $issue, ItsmConnection $connection, ?User $actor): string
    {
        if ($connection->status === 'disabled') {
            throw new \RuntimeException("{$connection->name} is disabled.");
        }
        if ($issue->external_ticket_id && $issue->itsm_connection_id) {
            throw new \RuntimeException("{$issue->issue_code} is already linked to {$issue->external_ticket_id}.");
        }

        [$reference, $url, $state] = $connection->mode === 'sandbox'
            ? $this->sandboxTicket($issue, $connection)
            : $this->liveTicket($issue, $connection);

        $issue->update([
            'itsm_connection_id' => $connection->id,
            'external_ticket_system' => $connection->system,
            'external_ticket_id' => $reference,
            'external_ticket_url' => $url,
            'external_ticket_status' => $state,
            'ticket_synced_at' => now(),
        ]);
        $connection->update(['last_synced_at' => now()]);
        $this->workflow->log($issue, 'ticket', "Ticket {$reference} created in {$connection->name}".($connection->mode === 'sandbox' ? ' (sandbox).' : '.'), $actor, [
            'connection_id' => $connection->id, 'reference' => $reference,
        ]);

        return $reference;
    }

    public function verifySignature(ItsmConnection $connection, string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        if (! $connection->webhook_secret || ! $timestamp || ! $signature || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals(self::sign($connection->webhook_secret, $timestamp, $rawBody), $signature);
    }

    public static function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Apply a status update from the tool. Accepts the tools' own shapes (Jira issue.key /
     * fields.status.name, ServiceNow number / state, Freshservice ticket.id / status) or a flat
     * {ticket, status, comment}.
     *
     * @return array{issue: ?Issue, changed: bool}
     */
    public function applyWebhook(ItsmConnection $connection, array $payload): array
    {
        $reference = (string) (Arr::get($payload, 'ticket') ?? Arr::get($payload, 'issue.key') ?? Arr::get($payload, 'number')
            ?? Arr::get($payload, 'ticket.id') ?? Arr::get($payload, 'key') ?? '');
        $state = (string) (Arr::get($payload, 'status') ?? Arr::get($payload, 'issue.fields.status.name')
            ?? Arr::get($payload, 'state') ?? Arr::get($payload, 'ticket.status') ?? '');

        $issue = Issue::withoutGlobalScopes()->where('organization_id', $connection->organization_id)
            ->where('itsm_connection_id', $connection->id)->where('external_ticket_id', $reference)->first();
        if (! $issue || $state === '') {
            return ['issue' => $issue, 'changed' => false];
        }

        $previous = $issue->external_ticket_status;
        $issue->update(['external_ticket_status' => $state, 'ticket_synced_at' => now()]);
        $connection->update(['last_synced_at' => now()]);
        if (strcasecmp((string) $previous, $state) === 0) {
            return ['issue' => $issue, 'changed' => false];
        }

        $comment = Str::limit((string) Arr::get($payload, 'comment', ''), 500);
        $this->workflow->log($issue, 'ticket', "{$connection->name} ticket {$reference} is now \"{$state}\"".($comment ? ": {$comment}" : '.'), null);

        if (in_array(strtolower($state), self::RESOLVED_STATES, true) && $issue->status === 'in_progress') {
            if (filled($issue->root_cause) && filled($issue->remediation_plan)) {
                $issue->update(['status' => 'remediated', 'remediated_at' => now(), 'remediated_by_id' => null]);
                $this->workflow->log($issue, 'status', "Marked remediated from {$connection->name}. Awaiting verification in Atheris.", null);
            } else {
                $this->workflow->log($issue, 'note', 'The ticket is resolved, but the issue has no root cause or remediation plan yet, so it stays in progress.', null);
            }
        }

        return ['issue' => $issue, 'changed' => true];
    }

    /** Deterministic ticket references in each tool's own format. */
    private function sandboxTicket(Issue $issue, ItsmConnection $connection): array
    {
        // Next number after the highest issued on this connector, so an unlinked ticket never frees its number.
        $n = Issue::withoutGlobalScopes()->where('itsm_connection_id', $connection->id)->pluck('external_ticket_id')
            ->map(fn ($ref) => preg_match('/(\d+)$/', (string) $ref, $m) ? (int) $m[1] : 0)
            ->map(fn ($num) => $num - ['jira' => 100, 'servicenow' => 10040, 'freshservice' => 5200][$connection->system])
            ->max() + 1;
        $base = rtrim($connection->base_url, '/');

        return match ($connection->system) {
            'jira' => [($key = ($connection->project_key ?: 'GRC').'-'.(100 + $n)), "{$base}/browse/{$key}", 'To Do'],
            'servicenow' => [($key = 'INC'.str_pad((string) (10040 + $n), 7, '0', STR_PAD_LEFT)), "{$base}/nav_to.do?uri=incident.do?number={$key}", 'New'],
            default => [($key = (string) (5200 + $n)), "{$base}/a/tickets/{$key}", 'Open'],
        };
    }

    private function liveTicket(Issue $issue, ItsmConnection $connection): array
    {
        $summary = "[{$issue->issue_code}] {$issue->title}";
        $description = trim(($issue->description ?? '')."\n\nRaised in Atheris ITSRM&G: ".route('issues.show', $issue->id)
            ."\nSeverity: {$issue->severity}. SLA due: ".$issue->sla_due_at?->toIso8601String());
        $base = rtrim($connection->base_url, '/');

        try {
            switch ($connection->system) {
                case 'jira':
                    $response = $this->client($connection)->post('/rest/api/2/issue', ['fields' => [
                        'project' => ['key' => $connection->project_key],
                        'summary' => Str::limit($summary, 250, ''),
                        'description' => $description,
                        'issuetype' => ['name' => 'Task'],
                        'priority' => ['name' => ['critical' => 'Highest', 'high' => 'High', 'moderate' => 'Medium', 'low' => 'Low'][$issue->severity]],
                        'duedate' => $issue->sla_due_at?->toDateString(),
                    ]])->throw();
                    $key = $response->json('key');

                    return [$key, "{$base}/browse/{$key}", 'To Do'];

                case 'servicenow':
                    $level = ['critical' => 1, 'high' => 2, 'moderate' => 2, 'low' => 3][$issue->severity];
                    $response = $this->client($connection)->post('/api/now/table/incident', [
                        'short_description' => Str::limit($summary, 160, ''),
                        'description' => $description,
                        'urgency' => $level, 'impact' => $level,
                        'correlation_id' => $issue->issue_code,
                    ])->throw();
                    $key = $response->json('result.number');

                    return [$key, "{$base}/nav_to.do?uri=incident.do?sys_id=".$response->json('result.sys_id'), 'New'];

                default:
                    $response = $this->client($connection)->post('/api/v2/tickets', [
                        'subject' => $summary,
                        'description' => nl2br(e($description)),
                        'email' => $issue->owner?->email ?? $issue->creator?->email,
                        'priority' => ['critical' => 4, 'high' => 3, 'moderate' => 2, 'low' => 1][$issue->severity],
                        'status' => 2,
                    ])->throw();
                    $key = (string) $response->json('ticket.id');

                    return [$key, "{$base}/a/tickets/{$key}", 'Open'];
            }
        } catch (Throwable $e) {
            $connection->update(['status' => 'error', 'last_test_ok' => false, 'last_test_message' => 'Ticket push failed: '.Str::limit($e->getMessage(), 140)]);
            throw new \RuntimeException("{$connection->name} rejected the ticket: ".Str::limit($e->getMessage(), 160));
        }
    }

    private function client(ItsmConnection $connection): PendingRequest
    {
        $request = Http::baseUrl(rtrim($connection->base_url, '/'))->acceptJson()->timeout(10);

        // Jira Cloud and ServiceNow take user + token as basic auth; Freshservice takes the API key as the user.
        return $connection->system === 'freshservice'
            ? $request->withBasicAuth((string) $connection->api_token, 'X')
            : $request->withBasicAuth((string) $connection->username, (string) $connection->api_token);
    }
}
