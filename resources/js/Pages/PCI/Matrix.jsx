import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

const colorFor = (status) => ({
    implemented: 'bg-[#2D7D46]',
    partial: 'bg-[#E5A100]',
    planned: 'bg-[#1D4ED8]',
    gap: 'bg-[#B3261E]',
})[status] || 'bg-gray-300';

export default function PciMatrix({ requirements = [], grid = [] }) {
    const byReq = {};
    grid.forEach((c) => { (byReq[c.req] ||= []).push(c); });

    return (
        <AuthenticatedLayout header="PCI-DSS Control Matrix">
            <Head title="PCI Control Matrix" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Control Matrix' }]}
                title="PCI-DSS Control Maturity Matrix"
                subtitle="12 requirements × 6 sub-requirements = 72 cells. Hover for maturity score."
            />

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr>
                            <th className="p-2 text-left text-[#718096]">Requirement</th>
                            {[1,2,3,4,5,6].map((i) => <th key={i} className="p-2 text-center text-[#718096]">.{i}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {requirements.map((r) => (
                            <tr key={r.code} className="border-t border-gray-100">
                                <td className="p-2">
                                    <div className="font-mono font-semibold text-[#0A1F44]">{r.code}</div>
                                    <div className="text-[10px] text-[#718096] max-w-xs truncate">{r.title}</div>
                                </td>
                                {(byReq[r.code] || []).map((c) => (
                                    <td key={c.sub} className="p-1">
                                        <div
                                            className={`h-12 rounded text-white text-[10px] font-bold flex items-center justify-center ${colorFor(c.status)}`}
                                            title={`${c.sub} — ${c.level}% (${c.status})`}>
                                            {c.level}%
                                        </div>
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="flex flex-wrap gap-3 text-[10px] text-[#718096] mt-4">
                    <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#2D7D46]" /> Implemented ≥ 90%</span>
                    <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#E5A100]" /> Partial ≥ 75%</span>
                    <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#1D4ED8]" /> Planned ≥ 50%</span>
                    <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#B3261E]" /> Gap &lt; 50%</span>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
