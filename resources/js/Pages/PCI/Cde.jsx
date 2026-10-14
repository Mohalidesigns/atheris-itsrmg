import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const tone = { cde: 'bg-[#FEE2E2] text-[#C53030]', connected: 'bg-[#FEF3C7] text-[#8A6400]', out_of_scope: 'bg-gray-100 text-[#718096]' };

function ScopeModal({ asset, scopes, onClose }) {
    const form = useForm({ pci_scope: asset.pci_scope || 'cde', pci_scope_note: asset.pci_scope_note || '' });
    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={(e) => { e.preventDefault(); form.put(route('pci.cde.scope', asset.id), { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3 p-6">
                <h3 className="text-base font-semibold text-[#2D3748]">PCI scope: {asset.asset_id_code} {asset.name}</h3>
                {Object.entries(scopes).map(([k, v]) => (
                    <label key={k} className="flex items-center gap-2 text-sm"><input type="radio" checked={form.data.pci_scope === k} onChange={() => form.setData('pci_scope', k)} /> {v}</label>
                ))}
                <label className="block text-xs text-[#718096]">Rationale *
                    <textarea rows={3} value={form.data.pci_scope_note} onChange={(e) => form.setData('pci_scope_note', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm" placeholder="e.g. Stores PAN for card issuance; segmented from the corporate LAN by the HQ firewall." />
                    {form.errors.pci_scope_note && <span className="text-[11px] text-[#C53030]">{form.errors.pci_scope_note}</span>}
                </label>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-[#718096]">Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white">Save</button>
                </div>
            </form>
        </Modal>
    );
}

export default function PciCde({ assets = [], scoping = {}, scopes = {}, filters = {}, canEdit = false }) {
    const [editing, setEditing] = useState(null);
    const filter = (scope) => router.get(route('pci.cde'), { scope: filters.scope === scope ? undefined : scope }, { preserveState: true });

    return (
        <AuthenticatedLayout header="Cardholder Data Environment">
            <Head title="PCI CDE" />
            <PageHeader breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Cardholder Data Env' }]}
                title="Cardholder data environment (CDE) scope"
                subtitle="Every asset's PCI DSS scope with its rationale (PCI DSS 12.5.2). Connected-to assets can affect the security of the CDE and are in scope for the relevant requirements." />
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                {[['cde', 'In the CDE', 'red'], ['connected', 'Connected-to / security-impacting', 'amber'], ['out_of_scope', 'Out of scope', 'white'], ['unscoped', 'Not yet scoped', scoping.unscoped ? 'amber' : 'white']].map(([k, l, t]) => (
                    <button key={k} type="button" onClick={() => filter(k)} className={`text-left ${filters.scope === k ? 'ring-2 ring-[#0A1F44] rounded-xl' : ''}`}><KpiCard label={l} value={scoping[k] ?? 0} tone={t} /></button>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]">
                        <th className="px-3 py-2">Asset</th><th className="px-3 py-2">Category</th><th className="px-3 py-2">Scope</th><th className="px-3 py-2">Rationale</th><th className="px-3 py-2" />
                    </tr></thead>
                    <tbody className="divide-y divide-gray-100">
                        {assets.map((a) => (
                            <tr key={a.id} className="align-top">
                                <td className="px-3 py-2"><Link href={route('assets.show', a.id)} className="font-mono text-xs text-[#1A365D] hover:underline">{a.asset_id_code}</Link><p className="text-[#2D3748]">{a.name}</p></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.category} · {a.asset_type?.replace('_', ' ')}</td>
                                <td className="px-3 py-2">{a.pci_scope ? <span className={`rounded-full px-2 py-0.5 text-xs ${tone[a.pci_scope]}`}>{scopes[a.pci_scope]}</span> : <span className="text-xs text-[#8A6400]">Not scoped</span>}</td>
                                <td className="px-3 py-2 text-xs text-[#4A5568] max-w-md">{a.pci_scope_note || '—'}</td>
                                <td className="px-3 py-2">{canEdit && <button type="button" onClick={() => setEditing(a)} className="text-xs text-[#0A1F44] underline">Scope…</button>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {editing && <ScopeModal asset={editing} scopes={scopes} onClose={() => setEditing(null)} />}
        </AuthenticatedLayout>
    );
}
