<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CoreBankingIntegration;
use App\Models\CoreBankingSnapshot;
use App\Models\Issue;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingConnector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Core Banking read-only adapters (ATH-VAL-017): register, sync, detail and snapshot history. */
class CoreBankingController extends Controller
{
    public function index(Request $request)
    {
        $integrations = CoreBankingIntegration::with(['asset:id,name,asset_id_code', 'owner:id,name'])->orderBy('name')->get();

        return Inertia::render('CoreBanking/Index', [
            'integrations' => $integrations,
            'products' => CoreBankingIntegration::PRODUCTS,
            'environments' => CoreBankingIntegration::ENVIRONMENTS,
            'statuses' => CoreBankingIntegration::STATUSES,
            'assets' => Asset::where('status', 'active')->whereIn('category', ['Core Banking', 'Payments', 'Third-Party'])->orderBy('name')->get(['id', 'name', 'asset_id_code']),
            'owners' => User::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $request->user()->can('create platform'), 'edit' => $request->user()->can('edit platform')],
        ]);
    }

    public function show(Request $request, $id)
    {
        $integration = CoreBankingIntegration::with(['asset:id,name,asset_id_code', 'owner:id,name'])->findOrFail($id);
        $issue = $integration->issue_id ? Issue::find($integration->issue_id, ['id', 'issue_code', 'title', 'status', 'severity']) : null;

        return Inertia::render('CoreBanking/Show', [
            'integration' => $integration,
            'latest' => $integration->snapshots()->latest('captured_at')->latest('id')->first(),
            'history' => $integration->snapshots()->latest('captured_at')->latest('id')->take(30)
                ->get(['id', 'captured_at', 'status', 'mode', 'findings', 'changes']),
            'issue' => $issue,
            'can' => ['edit' => $request->user()->can('edit platform')],
        ]);
    }

    public function snapshots(Request $request)
    {
        $integrationId = $request->integer('integration') ?: null;

        return Inertia::render('CoreBanking/Snapshots', [
            'snapshots' => CoreBankingSnapshot::with('integration:id,name,environment')
                ->when($integrationId, fn ($q) => $q->where('integration_id', $integrationId))
                ->orderByDesc('captured_at')->orderByDesc('id')->take(100)->get(),
            'integrations' => CoreBankingIntegration::orderBy('name')->get(['id', 'name']),
            'filters' => ['integration' => $integrationId],
        ]);
    }

    public function store(Request $request)
    {
        $integration = CoreBankingIntegration::create($this->validated($request) + ['mode' => 'sandbox', 'created_by' => $request->user()->id]);

        return redirect()->route('core-banking.show', $integration->id)->with('success', 'Integration added. It runs in sandbox mode until credentials are configured.');
    }

    public function update(Request $request, $id)
    {
        CoreBankingIntegration::findOrFail($id)->update($this->validated($request));

        return back()->with('success', 'Integration updated.');
    }

    public function sync(Request $request, $id, CoreBankingConnector $connector)
    {
        $integration = CoreBankingIntegration::findOrFail($id);
        $snapshot = $connector->sync($integration, $request->user());
        $n = count($snapshot->findings ?? []);

        return back()->with('success', "Technical metadata captured: {$n} finding(s), ".count($snapshot->embargoed_keys ?? []).' key(s) embargoed.');
    }

    private function validated(Request $request): array
    {
        $orgId = $request->user()->organization_id;

        return $request->validate([
            'product' => ['required', Rule::in(array_keys(CoreBankingIntegration::PRODUCTS))],
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::in(CoreBankingIntegration::ENVIRONMENTS)],
            'status' => ['required', Rule::in(CoreBankingIntegration::STATUSES)],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ]);
    }
}
