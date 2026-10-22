<?php

namespace App\Services\Finance;

use App\Models\Evidence;
use App\Models\Finance\FinanceDocument;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Documents on expenses, purchase orders, goods receipts and invoices (FIN-03.14): hashed in the evidence vault, served only by the finance routes. */
class FinanceDocuments
{
    use LogsFinanceEvents;

    /** @param array{kind?:string,title?:string} $data */
    public function attach(Model $subject, array $data, UploadedFile $file, User $by): FinanceDocument
    {
        in_array($data['kind'] ?? null, FinanceDocument::KINDS, true) || $this->fail('Unknown document kind.', 'kind');
        $title = trim((string) ($data['title'] ?? ''));
        ($title === '' || mb_strlen($title) > 200) && $this->fail('Give the document a title (max 200 characters).', 'title');
        in_array($subject::class, FinanceDocument::subjects(), true) || $this->fail('Documents cannot be attached to that record.', 'subject');
        (int) $subject->organization_id === (int) $by->organization_id || $this->fail('That record belongs to another bank.', 'subject');
        $orgId = (int) $subject->organization_id;

        return DB::transaction(function () use ($subject, $data, $file, $by, $title, $orgId) {
            $sha = hash_file('sha256', $file->getRealPath());
            $path = $file->storeAs("evidence/{$orgId}/finance", Str::random(40).'.'.($file->guessExtension() ?: 'bin'), 'local');
            $evidence = Evidence::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'evidenceable_type' => $subject::class, 'evidenceable_id' => $subject->getKey(), 'title' => $title,
                'description' => "Finance document ({$data['kind']})", 'type' => 'document', 'file_path' => $path, 'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(), 'mime_type' => $file->getClientMimeType(), 'status' => 'approved', 'uploaded_by' => $by->id,
            ]);
            $doc = FinanceDocument::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'documentable_type' => $subject::class, 'documentable_id' => $subject->getKey(),
                'evidence_id' => $evidence->id, 'kind' => $data['kind'], 'title' => $title, 'sha256' => $sha,
            ]);
            $this->audit($subject, $by, 'document_added', "Document added to {$subject->getTable()} #{$subject->getKey()}", ['title' => $title, 'sha256' => $sha]);

            return $doc;
        });
    }
}
