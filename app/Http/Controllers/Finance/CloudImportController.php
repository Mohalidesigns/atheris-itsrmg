<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CloudImportRequest;
use App\Models\Finance\CloudAccount;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\FinanceImportNotifier;
use Illuminate\Support\Facades\Gate;

/** Upload of a provider cost export (FIN-03.09). */
class CloudImportController extends Controller
{
    public function __construct(private readonly CloudCostService $cloud) {}

    public function store(CloudImportRequest $request)
    {
        Gate::authorize('manageCloud', CloudAccount::class);
        $account = CloudAccount::query()->findOrFail((int) $request->validated('cloud_account_id')); // the global scope makes another bank's account a 404
        Gate::authorize('view', $account);
        $batch = $this->cloud->import($account, $request->file('file'), $request->validated('format'), $request->user());

        if (class_exists(FinanceImportNotifier::class)) {
            app(FinanceImportNotifier::class)->completed($batch);
        }
        $r = (array) $batch->report;
        $report = [
            'type' => 'cloud', 'file_name' => $batch->file_name, 'row_count' => $batch->row_count, 'accepted_count' => $batch->accepted_count, 'rejected_count' => $batch->rejected_count,
            'errors' => collect($r['rejected'] ?? [])->map(fn ($e) => ['row' => $e['line'] ?: '', 'message' => $e['reason']])->take(200)->values()->all(),
            'replaced_dates' => $r['replaced_dates'] ?? [], 'appended_dates' => $r['appended_dates'] ?? [], 'refused_dates' => $r['refused_dates'] ?? [],
        ];
        if ($batch->status === 'failed') {
            return back()->with('error', 'Nothing was imported. See the rejected rows.')->with('import_report', $report);
        }

        return back()->with('success', "Import complete: {$batch->accepted_count} daily lines, {$batch->rejected_count} rejected.")->with('import_report', $report);
    }
}
