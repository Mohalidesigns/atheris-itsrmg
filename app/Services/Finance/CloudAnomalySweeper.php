<?php

namespace App\Services\Finance;

use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\User;
use App\Notifications\Finance\CloudAnomalyNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Daily cloud anomaly detection and notice (fin:cloud-anomalies, plan P6-4). One send per new anomaly through fin_notification_logs. */
class CloudAnomalySweeper
{
    public function __construct(private readonly CloudAnomalyService $anomalies, private readonly ContractService $contracts, private readonly ContractSweeper $sweeper) {}

    public function sweep(?CarbonInterface $today = null): int
    {
        $today ??= FinanceClock::today();
        $sent = 0;
        $orgIds = CloudAccount::withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')->distinct()->orderBy('organization_id')->pluck('organization_id');
        foreach ($orgIds as $orgId) {
            try {
                $sent += $this->sweepOrg((int) $orgId, $today);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function sweepOrg(int $orgId, CarbonInterface $today): int
    {
        $sent = 0;
        $controllers = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->role('IT Finance Controller')->get();
        $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($accounts as $account) {
            try { // one failing account must not stop the others
                $this->anomalies->detect($account, $today);
                // Every open anomaly not yet claimed is notified, not only the ones created in this run (claim() keeps it idempotent).
                $open = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->where('cloud_account_id', $account->id)->where('status', 'open')->orderBy('id')->get();
                foreach ($open as $x) {
                    $recipients = $controllers->values();
                    $x->owner_id && $recipients->push(User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->find($x->owner_id));
                    $recipients = $recipients->filter(fn ($u) => $u && $u->can('view it-finance'))->unique('id')->values();
                    $users = $this->sweeper->claim($x, 'cloud_anomaly', 0, $recipients);
                    if ($users->isEmpty()) {
                        continue;
                    }
                    Notification::send($users, new CloudAnomalyNotification(
                        (string) $account->name, $x->scope_label, $x->detected_on->format('d M Y'), (string) $x->deviation_pct, (string) $x->method,
                        route('finance.cloud.anomalies.index', ['status' => 'open', 'highlight' => $x->id])));
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
