import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { TierBadge } from '@/Components/Vendors/VendorBadges';
import { Head, Link, useForm } from '@inertiajs/react';

function LinkForm({ entry, unlinked }) {
    const form = useForm({ vendor_id: '' });
    if (unlinked.length === 0) return null;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('shared-vendors.link', entry.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="mt-2 flex gap-1">
            <select value={form.data.vendor_id} onChange={(e) => form.setData('vendor_id', e.target.value)} className="min-w-0 flex-1 rounded-lg border-gray-300 text-xs">
                <option value="">Link one of my vendors…</option>
                {unlinked.map((v) => <option key={v.id} value={v.id}>{v.vendor_code} — {v.name}</option>)}
            </select>
            <button type="submit" disabled={!form.data.vendor_id || form.processing} className="rounded-lg border border-[#0A1F44] px-2 text-xs text-[#0A1F44] disabled:opacity-40">Link</button>
        </form>
    );
}

export default function SharedDirectory({ entries = [], unlinked = [], can = {} }) {
    return (
        <AuthenticatedLayout header="Shared Nigerian Vendor Directory">
            <Head title="Shared Vendor Directory" />
            <PageHeader
                breadcrumbs={[{ label: 'Vendor Management' }, { label: 'Shared Vendor Directory' }]}
                title="Shared Nigerian Vendor Directory"
                subtitle="Atheris-maintained reference entries for the market's shared infrastructure providers, with a baseline DDQ version. Link your own vendor record to an entry, or add the entry to your register. Your vendor data is never written to the directory."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {entries.map((e) => (
                    <div key={e.id} className="flex flex-col bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-[#0A1F44]">{e.legal_name}</h3>
                                <p className="text-xs text-[#718096]">{e.category} · {e.country}</p>
                            </div>
                            <span className="text-[10px] px-2 py-0.5 rounded-md bg-[#C9A86A]/15 text-[#0A1F44] whitespace-nowrap">DDQ {e.baseline_ddq_version}</span>
                        </div>
                        {e.description && <p className="text-xs text-[#2D3748] mt-2">{e.description}</p>}
                        <div className="mt-auto pt-3">
                            {e.linked.length > 0 ? (
                                <ul className="space-y-1 text-xs">
                                    {e.linked.map((v) => (
                                        <li key={v.id} className="flex items-center gap-2">
                                            <span className="text-[#2D7D46]">In your register:</span>
                                            <Link href={route('vendors.show', v.id)} className="text-[#0A1F44] underline">{v.vendor_code}</Link>
                                            <TierBadge tier={v.tier} />
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <div>
                                    <p className="text-xs text-[#718096]">Not in your register.</p>
                                    <div className="mt-1 flex gap-3">
                                        {can.create && <Link href={route('vendors.create', { directory: e.id })} className="text-xs font-semibold text-[#0A1F44] underline">Add to my register</Link>}
                                    </div>
                                    {can.edit && <LinkForm entry={e} unlinked={unlinked} />}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
