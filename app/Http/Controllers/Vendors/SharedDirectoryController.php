<?php

namespace App\Http\Controllers\Vendors;

use App\Http\Controllers\Controller;
use App\Models\SharedVendorDirectory;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Shared Nigerian Vendor Directory: platform-level reference entries. A bank sees which of its own
 * vendors are linked to each entry, links an existing vendor, or adds the entry to its register
 * (Vendors/Create pre-filled). The directory itself is never written by a bank.
 */
class SharedDirectoryController extends Controller
{
    public function index()
    {
        $linked = Vendor::query()->whereNotNull('shared_directory_id')
            ->get(['id', 'vendor_code', 'name', 'shared_directory_id', 'tier', 'status'])->groupBy('shared_directory_id');

        return Inertia::render('Tprm/SharedDirectory', [
            'entries' => SharedVendorDirectory::orderBy('legal_name')->get()->map(fn ($e) => $e->toArray() + [
                'linked' => ($linked[$e->id] ?? collect())->values(),
            ]),
            'unlinked' => Vendor::query()->whereNull('shared_directory_id')->where('status', '!=', 'terminated')
                ->orderBy('name')->get(['id', 'vendor_code', 'name']),
            'can' => [
                'create' => request()->user()->can('create vendors'),
                'edit' => request()->user()->can('edit vendors'),
            ],
        ]);
    }

    public function link(Request $request, SharedVendorDirectory $entry)
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $vendor->update(['shared_directory_id' => $entry->id]);

        return back()->with('success', "{$vendor->name} linked to the directory entry for {$entry->legal_name}.");
    }
}
