<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinanceDocument;
use App\Services\Finance\FinanceDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Upload and download of documents on expenses, purchase orders, goods receipts and invoices (FIN-03.14). */
class FinanceDocumentController extends Controller
{
    public function __construct(private readonly FinanceDocuments $documents) {}

    public function store(Request $request)
    {
        $d = $request->validate([
            'subject' => ['required', Rule::in(array_keys(FinanceDocument::subjects()))], 'subject_id' => ['required', 'integer'], 'kind' => ['required', Rule::in(FinanceDocument::KINDS)],
            'title' => ['required', 'string', 'max:200'], 'file' => ['required', 'file', 'max:'.config('finance.contracts.document_max_kb'), 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,msg,eml,zip'],
        ]);
        $class = FinanceDocument::subjects()[$d['subject']];
        $subject = $class::query()->findOrFail($d['subject_id']); // the global scope makes another bank's record a 404
        Gate::authorize(in_array($d['subject'], ['purchase_order', 'goods_receipt'], true) ? 'procure' : 'updateExpense', $subject);
        $this->documents->attach($subject, $d, $request->file('file'), $request->user());

        return back()->with('success', 'Document attached.');
    }

    public function download(Request $request, FinanceDocument $document)
    {
        $subject = $document->documentable()->withoutGlobalScopes()->first();
        abort_unless($subject && (int) $subject->organization_id === (int) $request->user()->organization_id, 404);
        Gate::authorize('view', $subject);
        $evidence = $document->evidence()->withoutGlobalScopes()->first();
        abort_unless($evidence && $evidence->file_path && Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->download($evidence->file_path, $evidence->file_name ?: basename($evidence->file_path));
    }
}
