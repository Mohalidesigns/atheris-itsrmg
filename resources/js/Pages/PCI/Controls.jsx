import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function PciControls({ requirements = [], byReq = {} }) {
    const [active, setActive] = useState(requirements[0]?.code || 'R1');
    const current = byReq[active] || [];
    return (
        <AuthenticatedLayout header="PCI Controls">
            <Head title="PCI Controls" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Controls' }]}
                title="PCI-DSS Controls Mapping"
                subtitle="Tenant controls mapped to each of the 12 PCI-DSS v4.0.1 requirements."
            />
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-3 h-fit">
                    <h3 className="text-xs font-semibold uppercase text-[#718096] mb-2">Requirements</h3>
                    <ul className="space-y-1">
                        {requirements.map((r) => (
                            <li key={r.code}>
                                <button onClick={() => setActive(r.code)}
                                    className={`w-full text-left px-2 py-1.5 rounded-md text-xs ${active === r.code ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748] hover:bg-gray-50'}`}>
                                    <span className="font-mono font-semibold">{r.code}</span>
                                    <span className={`block text-[10px] mt-0.5 ${active === r.code ? 'text-white/80' : 'text-[#718096]'}`}>{r.title}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </aside>
                <section className="lg:col-span-4 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="p-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">{active} — {requirements.find((r) => r.code === active)?.title}</h3>
                        <p className="text-xs text-[#718096]">{current.length} tenant control(s) mapped</p>
                    </div>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Control code</th>
                                <th className="px-3 py-2">Title</th>
                                <th className="px-3 py-2">Domain</th>
                                <th className="px-3 py-2">Frequency</th>
                                <th className="px-3 py-2">Effectiveness</th>
                                <th className="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {current.map((c, idx) => (
                                <tr key={idx}>
                                    <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{c.control_code}</td>
                                    <td className="px-3 py-2 text-[#2D3748]">{c.title}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.domain}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.frequency}</td>
                                    <td className="px-3 py-2">
                                        {c.effectiveness
                                            ? <StatusBadge status={c.effectiveness === 'effective' ? 'pass' : c.effectiveness === 'partially_effective' ? 'warn' : 'fail'} label={c.effectiveness?.replace('_', ' ')} />
                                            : <span className="text-xs text-[#718096]">—</span>}
                                    </td>
                                    <td className="px-3 py-2"><StatusBadge status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
