<?php

namespace App\Services\Monitoring;

use App\Models\AccessReviewCampaign;
use App\Models\AccessReviewItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User-access recertification (ATH-VAL-013).
 *
 * A campaign is launched over one system and populated from a source: Atheris' own users and
 * roles (a real extract), an Active Directory extract (sandbox: no bank has connected AD, so the
 * bank's users are mapped to AD groups deterministically, plus the service and leaver accounts
 * an AD review typically surfaces), or entries added by hand.
 *
 * Segregation of duties: nobody decides on their own access, and a revocation is confirmed as
 * done by someone other than the person who decided it. A campaign completes only when every
 * entitlement has a decision; revocations still to be confirmed are tracked by CCM-IAM-PRIV-003.
 */
class AccessReviewService
{
    public const PRIVILEGED_ROLES = ['Super Admin', 'Organization Admin'];

    /** Atheris role => AD group for the sandbox extract, and whether that group is privileged. */
    private const AD_GROUPS = [
        'Super Admin' => ['Domain Admins', true],
        'Organization Admin' => ['Domain Admins', true],
        'Security Analyst' => ['SOC-Analysts', false],
        'Change Manager' => ['Server Operators', true],
        'Risk Manager' => ['Risk-Share-RW', false],
        'Compliance Officer' => ['Compliance-Share-RW', false],
        'Auditor' => ['Audit-ReadOnly', false],
        'Viewer' => ['Staff-Users', false],
    ];

    public function launch(array $data, User $actor): AccessReviewCampaign
    {
        $orgId = (int) $actor->organization_id;

        return DB::transaction(function () use ($data, $actor, $orgId) {
            $campaign = AccessReviewCampaign::create($data + [
                'organization_id' => $orgId,
                'campaign_code' => AccessReviewCampaign::generateNextCode($orgId),
                'status' => 'in_progress',
                'owner_id' => $actor->id,
                'started_at' => now(),
            ]);
            foreach ($this->extract($campaign) as $row) {
                $campaign->items()->create($row + ['organization_id' => $orgId, 'reviewer_id' => $campaign->reviewer_id]);
            }

            return $campaign;
        });
    }

    /** Entitlements for a campaign's source. */
    public function extract(AccessReviewCampaign $campaign): array
    {
        if ($campaign->source === 'manual') {
            return [];
        }
        $users = User::where('organization_id', $campaign->organization_id)->with('roles:id,name')->orderBy('id')->get();
        $rows = [];
        foreach ($users as $u) {
            foreach ($u->roles as $role) {
                [$entitlement, $privileged] = $campaign->source === 'ad'
                    ? (self::AD_GROUPS[$role->name] ?? ['Staff-Users', false])
                    : [$role->name, in_array($role->name, self::PRIVILEGED_ROLES, true)];
                $rows[] = [
                    'user_id' => $u->id,
                    'account_name' => $campaign->source === 'ad' ? Str::before($u->email, '@') : $u->email,
                    'entitlement' => $entitlement,
                    'privileged' => $privileged,
                    'last_login_at' => $u->last_login_at,
                ];
            }
        }
        if ($campaign->source === 'ad') {
            $rows[] = ['user_id' => null, 'account_name' => 'svc_backup', 'entitlement' => 'Backup Operators', 'privileged' => true, 'last_login_at' => now()->subDays(212)];
            $rows[] = ['user_id' => null, 'account_name' => 'leaver.finance01', 'entitlement' => 'Finance-Share-RW', 'privileged' => false, 'last_login_at' => now()->subDays(143)];
        }

        return collect($rows)->unique(fn ($r) => $r['account_name'].'|'.$r['entitlement'])->values()->all();
    }

    public function decide(AccessReviewItem $item, string $decision, ?string $comment, User $actor): void
    {
        $campaign = $item->campaign;
        $campaign->status === 'in_progress' || $this->fail('This campaign is closed.');
        (int) $item->user_id === (int) $actor->id && $this->fail('You cannot certify your own access; another reviewer must decide it.');
        in_array($decision, ['revoke', 'modify'], true) && blank($comment) && $this->fail('Say why the access should be removed or changed.', 'comment');

        $item->update(['decision' => $decision, 'comment' => $comment, 'decided_by' => $actor->id, 'decided_at' => now(),
            'confirmed_by' => null, 'revocation_confirmed_at' => null]);
    }

    public function confirm(AccessReviewItem $item, User $actor): void
    {
        in_array($item->decision, ['revoke', 'modify'], true) || $this->fail('Only a revoke or modify decision needs confirming.');
        $item->revocation_confirmed_at && $this->fail('This change has already been confirmed.');
        (int) $item->decided_by === (int) $actor->id && $this->fail('The person who decided the revocation cannot confirm it was carried out.');

        $item->update(['confirmed_by' => $actor->id, 'revocation_confirmed_at' => now()]);
    }

    public function complete(AccessReviewCampaign $campaign, User $actor): void
    {
        $campaign->status === 'in_progress' || $this->fail('This campaign is already complete.');
        $open = $campaign->items()->whereNull('decision')->count();
        $campaign->items()->exists() || $this->fail('Add the entitlements to review first.');
        $open && $this->fail("{$open} entitlement(s) still need a decision.");

        $campaign->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id]);
    }

    private function fail(string $message, string $key = 'review'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
