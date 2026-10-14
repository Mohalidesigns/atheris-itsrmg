import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head } from '@inertiajs/react';

const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function PciEvidence({ items = [], byRequirement = [] }) {
    const expired = items.filter((i) => i.expired).length;
    return (
        <AuthenticatedLayout header="PCI Evidence">
            <Head title="PCI Evidence" />
            <PageHeader breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Evidence Repository' }]}
                title="PCI evidence"
                subtitle="Evidence attached to PCI assessment results and to the controls mapped to PCI DSS requirements. Attach evidence from the assessment or the control." />
            <div className="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
                <KpiCard label="Evidence items" value={items.length} tone="navy" />
                <KpiCard label="Expired" value={expired} tone={expired ? 'red' : 'white'} />
                <KpiCard label="Sub-requirements with evidence" value={byRequirement.reduce((a, r) => a + r.with, 0)} sublabel={`of ${byRequirement.reduce((a, r) => a + r.total, 0)}`} tone="white" />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-2">Coverage by requirement</h3>
                    <ul className="space-y-1 text-xs">
                        {byRequirement.map((r) => (
                            <li key={r.code} className="flex justify-between"><span className="text-[#2D3748]">Req {r.code}</span><span className={r.with === 0 ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}>{r.with}/{r.total}</span></li>
                        ))}
                    </ul>
                </div>
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Evidence</th><th className="px-3 py-2">Supports</th><th className="px-3 py-2">Valid until</th></tr></thead>
                        <tbody className="divide-y divide-gray-100">
                            {items.length === 0 && <tr><td colSpan={3} className="px-3 py-8 text-center text-[#718096]">No PCI evidence yet.</td></tr>}
                            {items.map((i) => (
                                <tr key={i.id}>
                                    <td className="px-3 py-2"><p className="text-[#2D3748]">{i.title}</p><p className="text-[11px] text-[#718096]">{i.type} · {i.file_name || 'no file attached'} · {i.evidenceable_type.endsWith('Control') ? 'on a control' : 'on an assessment result'}</p></td>
                                    <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{i.requirements.join(', ') || '—'}</td>
                                    <td className={`px-3 py-2 text-xs ${i.expired ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>{fmt(i.valid_until)}{i.expired ? ' · expired' : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
