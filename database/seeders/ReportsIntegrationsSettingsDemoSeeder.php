<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\IntegrationConnection;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\TenantTheme;
use App\Models\User;
use App\Services\Reports\ReportFigures;
use Illuminate\Database\Seeder;

/** Demo data for Reports (scheduled), Integrations hub and Settings (ATH-VAL-018), per bank. */
class ReportsIntegrationsSettingsDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([2 => 'kano', 3 => 'fbn'] as $orgId => $tag) {
            $user = User::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->first();
            if (! $user) {
                continue;
            }
            $this->theme($orgId, $tag);
            $this->customFields($orgId);
            $this->schedules($orgId, $user);
            $this->connections($orgId, $user);
        }
        auth()->forgetUser();
    }

    private function theme(int $orgId, string $tag): void
    {
        if (TenantTheme::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }
        $gold = $tag === 'fbn' ? '#B8935A' : '#C9A86A';
        TenantTheme::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'status' => 'active', 'logo_path' => null,
            'tokens' => ['navy' => '#0A1F44', 'gold' => $gold, 'green' => '#2D7D46', 'charcoal' => '#2D3748'],
        ]);
    }

    private function customFields(int $orgId): void
    {
        $fields = [
            ['Risk', 'cbn_clause', 'CBN RBCSF Clause', 'text', 10],
            ['Risk', 'regulator_reportable', 'Regulator Reportable?', 'text', 20],
            ['Control', 'aucs_code', 'AUCS Code', 'text', 10],
            ['Incident', 'ncert_reference', 'ngCERT Reference', 'text', 10],
            ['Vendor', 'nibss_interchange', 'NIBSS Interchange Member?', 'text', 10],
            ['Policy', 'approving_board_date', 'Approving Board Date', 'date', 10],
        ];
        foreach ($fields as [$subj, $key, $label, $type, $order]) {
            CustomField::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $orgId, 'subject_type' => $subj, 'key' => $key],
                ['label' => $label, 'data_type' => $type, 'order_index' => $order, 'required' => false],
            );
        }
    }

    private function schedules(int $orgId, User $user): void
    {
        if (ScheduledReport::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }
        auth()->setUser($user);
        $defs = [
            ['Weekly executive summary', 'executive', 'weekly', true, 2],
            ['Monthly risk analytics', 'risks', 'monthly', true, 1],
            ['Daily compliance posture', 'compliance', 'daily', false, 0],
        ];
        foreach ($defs as [$name, $type, $freq, $active, $runs]) {
            $s = ScheduledReport::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'name' => $name, 'report_type' => $type, 'frequency' => $freq,
                'recipients' => [$user->email], 'active' => $active, 'created_by' => $user->id,
                'next_run_at' => ScheduledReport::advance($freq), 'last_run_at' => $runs ? now()->subDay() : null,
            ]);
            for ($i = $runs; $i >= 1; $i--) {
                ScheduledReportRun::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'scheduled_report_id' => $s->id,
                    'ran_at' => now()->subDays($i), 'status' => 'recorded',
                    'summary' => ReportFigures::summary($type, $orgId),
                ]);
            }
        }
    }

    private function connections(int $orgId, User $user): void
    {
        $defs = [
            'entra-id' => ['connected', 'pass', '[Sandbox] Configuration for Microsoft Entra ID is complete; sandbox probe passed. No live call was made to the vendor.', 'https://graph.microsoft.com'],
            'tenable' => ['configured', null, null, 'https://cloud.tenable.com'],
            'interswitch' => ['failed', 'fail', '[Sandbox] Interswitch test failed: missing API key / token.', 'https://api.interswitchng.com'],
        ];
        foreach ($defs as $key => [$status, $result, $msg, $url]) {
            $config = ['base_url' => $url, 'scope' => 'read-only', 'sync_frequency' => 'hourly'];
            if ($status !== 'failed') {
                $config['access_key'] = 'demo-'.$key.'-key';
            }
            IntegrationConnection::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $orgId, 'connector_key' => $key],
                ['config' => $config, 'status' => $status, 'last_test_result' => $result, 'last_test_message' => $msg,
                    'last_tested_at' => $result ? now()->subHours(3) : null, 'updated_by' => $user->id],
            );
        }
    }
}
