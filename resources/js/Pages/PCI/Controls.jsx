import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { XMarkIcon } from '@heroicons/react/24/outline';

const eff = { effective: 'text-[#2D7D46]', partially_effective: 'text-[#8A6400]', ineffective: 'font-semibold text-[#B3261E]', not_assessed: 'text-[#718096]' };

function MapForm({ sub, controls }) {
    const form = useForm({ requirement_id: sub.id, control_id: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('pci.controls.map'), { preserveScroll: true, onSuccess: () => form.reset('control_id') }); }} className="mt-1 flex gap-1">
            <select value={form.data.control_id} onChange={(e) => form.setData('control_id', e.target.value)} className="min-w-0 flex-1 rounded border-gray-300 text-xs">
                <option value="">Map a control…</option>
                {controls.filter((c) => !sub.controls.some((m) => m.id === c.id)).map((c) => <option key={c.id} value={c.id}>{c.control_code} — {c.title}</option>)}
            </select>
            <button type="submit" disabled={!form.data.control_id} className="rounded border border-[#0A1F44] px-2 text-xs text-[#0A1F44] disabled:opacity-40">Map</button>
        </form>
    );
}

export default function PciControls({ requirements = [], controls = [], canEdit = false }) {
    const [open, setOpen] = useState(requirements[0]?.code);
    return (
        <AuthenticatedLayout header="PCI Controls">
            <Head title="PCI Controls" />
            <PageHeader breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'PCI Controls' }]}
                title="Controls mapped to PCI DSS"
                subtitle="The bank's controls that meet each PCI DSS v4.0.1 sub-requirement, with their tested effectiveness. A sub-requirement with no control has nothing to show an assessor." />
            <div className="space-y-2">
                {requirements.map((r) => (
                    <div key={r.code} className="bg-white rounded-xl border border-gray-100 shadow-sm">
                        <button type="button" onClick={() => setOpen(open === r.code ? null : r.code)} className="flex w-full items-center justify-between px-4 py-3 text-left">
                            <span className="text-sm text-[#2D3748]"><span className="font-mono text-xs text-[#0A1F44]">Req {r.code}</span> {r.title}</span>
                            <span className={`text-xs ${r.unmapped ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>{r.subs.length - r.unmapped}/{r.subs.length} mapped</span>
                        </button>
                        {open === r.code && (
                            <ul className="divide-y divide-gray-50 border-t border-gray-100">
                                {r.subs.map((s) => (
                                    <li key={s.id} className="grid grid-cols-1 gap-2 px-4 py-2 md:grid-cols-2">
                                        <p className="text-sm text-[#2D3748]"><span className="font-mono text-xs text-[#0A1F44]">{s.code}</span> {s.title}</p>
                                        <div className="text-xs">
                                            {s.controls.length === 0 && <p className="font-semibold text-[#B3261E]">No control mapped</p>}
                                            {s.controls.map((c) => (
                                                <p key={c.mapping_id} className="flex items-center justify-between gap-2">
                                                    <span><Link href={route('controls.show', c.id)} className="font-mono text-[#1A365D] hover:underline">{c.control_code}</Link> {c.title} <span className={eff[c.effectiveness] || eff.not_assessed}>· {(c.effectiveness || 'not assessed').replace('_', ' ')}</span></span>
                                                    {canEdit && <button type="button" onClick={() => router.delete(route('pci.controls.unmap', c.mapping_id), { preserveScroll: true })} className="text-[#C53030]" title="Remove mapping"><XMarkIcon className="h-3 w-3" /></button>}
                                                </p>
                                            ))}
                                            {canEdit && <MapForm sub={s} controls={controls} />}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
