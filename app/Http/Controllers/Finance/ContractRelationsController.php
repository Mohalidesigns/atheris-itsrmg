<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ContractLinkRequest;
use App\Http\Requests\Finance\ContractTeamRequest;
use App\Http\Requests\Finance\StoreContractDocumentRequest;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Services\Finance\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Team, links and documents of a contract (FIN-06.01, FIN-06.08). Nested bindings are scoped to the contract. */
class ContractRelationsController extends Controller
{
    public function __construct(private readonly ContractService $contracts) {}

    private function writable(Contract $contract): void
    {
        Gate::authorize('updateContract', $contract);
        $contract->is_terminal && abort(403, 'Renewed, terminated and replaced contracts are read-only.');
    }

    public function teamStore(ContractTeamRequest $request, Contract $contract)
    {
        $this->writable($contract);
        $this->contracts->addTeamMember($contract, (int) $request->validated('user_id'), $request->validated('role'), $request->user());

        return back()->with('success', 'Team member added.');
    }

    public function teamDestroy(Request $request, Contract $contract, ContractTeamMember $member)
    {
        $this->writable($contract);
        $this->contracts->removeTeamMember($member, $request->user());

        return back()->with('success', 'Team member removed.');
    }

    public function linkStore(ContractLinkRequest $request, Contract $contract)
    {
        $this->writable($contract);
        $d = $request->validated();
        $this->contracts->addLink($contract, $d['type'], (int) $d['id'], $d['note'] ?? null, $request->user());

        return back()->with('success', 'Link added.');
    }

    public function linkDestroy(Request $request, Contract $contract, ContractLink $link)
    {
        $this->writable($contract);
        $this->contracts->removeLink($link, $request->user());

        return back()->with('success', 'Link removed.');
    }

    public function documentStore(StoreContractDocumentRequest $request, Contract $contract)
    {
        $this->writable($contract);
        $this->contracts->addDocument($contract, $request->validated(), $request->file('file'), $request->user());

        return back()->with('success', 'Document added.');
    }

    public function documentDownload(Request $request, Contract $contract, ContractDocument $document)
    {
        Gate::authorize('view', $contract);
        $evidence = $document->evidence()->withoutGlobalScopes()->first();
        abort_unless($evidence && $evidence->file_path && Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->download($evidence->file_path, $evidence->file_name ?: basename($evidence->file_path));
    }
}
