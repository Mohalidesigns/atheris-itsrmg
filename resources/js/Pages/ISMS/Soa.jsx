import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { DocumentArrowDownIcon } from '@heroicons/react/24/outline';

const implStatusTone = (s) => ({
    implemented: 'pass', partial: 'warn', planned: 'draft', not_started: 'fail', not_applicable: 'draft',
})[s] || 'draft';

export default function IsmsSoa({ rows = [], summary = {} }) {
    const [filter, setFilter] = useState('all');
    const filtered = rows.filter((r) => {
        if (filter === 'applicable') return !!r.is_applicable;
        if (filter === 'excluded') return r.is_applicable === 0 || r.is_applicable === false;
        if (filter === 'implemented') return r.implementation_status === 'implemented';
        return true;
    });

    return (
        <AuthenticatedLayout header="Statement of Applicability">
            <Head title="SoA — ISO 27001:2022" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Statement of Applicability' }]}
                title="Statement of Applicability (SoA)"
                subtitle="Per-control applicability + justification for every Annex A item. Signed annually by the CISO and filed as an ISMS core artefact."
                actions={<button className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold"><DocumentArrowDownIcon className="w-4 h-4" /> Export SoA (PDF)</button>}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Total Annex A" value={summary.total || 0} tone="navy" />
                <KpiCard label="Applicable" value={summary.applicable || 0} tone="gold" />
                <KpiCard label="Excluded" value={summary.excluded || 0} tone="white" />
                <KpiCard label="Pending" value={summary.pending || 0} tone="amber" />
                <KpiCard label="Implemented" value={summary.implemented || 0} tone="green" />
            </div>

            <div className="flex gap-2 mb-4">
                {['all', 'applicable', 'excluded', 'implemented'].map((f) => (
                    <button key={f} onClick={() => setFilter(f)}
                        className={`px-3 py-1.5 rounded-lg text-xs capitalize ${filter === f ? 'bg-[#0A1F44] text-white' : 'bg-white border border-gray-200 text-[#2D3748]'}`}>
                        {f}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Control</th>
                            <th className="px-3 py-2">Applicable</th>
                            <th className="px-3 py-2">Implementation</th>
                            <th className="px-3 py-2">Justification</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {filtered.map((r, idx) => (
                            <tr key={idx}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{r.requirement_code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{r.title}</td>
                                <td className="px-3 py-2">
                                    {r.is_applicable === 1 || r.is_applicable === true
                                        ? <StatusBadge status="pass" label="Applicable" />
                                        : r.is_applicable === 0 || r.is_applicable === false
                                            ? <StatusBadge status="draft" label="Excluded" />
                                            : <StatusBadge status="draft" label="Pending" />}
                                </td>
                                <td className="px-3 py-2">
                                    {r.implementation_status
                                        ? <StatusBadge status={implStatusTone(r.implementation_status)} label={String(r.implementation_status).replace('_', ' ')} />
                                        : <span className="text-xs text-[#718096]">—</span>}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#2D3748] max-w-md">{r.justification || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
