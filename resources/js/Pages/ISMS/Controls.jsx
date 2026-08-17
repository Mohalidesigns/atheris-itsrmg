import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const THEMES = [
    ['A.5', 'Organizational Controls'],
    ['A.6', 'People Controls'],
    ['A.7', 'Physical Controls'],
    ['A.8', 'Technological Controls'],
];

const statusTone = (s) => ({
    implemented: 'pass',
    partial: 'warn',
    planned: 'draft',
    not_started: 'fail',
    not_applicable: 'draft',
})[s] || 'draft';

export default function IsmsControls({ requirements = [], soa = {}, totals = {} }) {
    const [active, setActive] = useState('A.5');

    const themeReqs = requirements.filter((r) => r.level > 0 && r.requirement_code.startsWith(active + '.'));

    return (
        <AuthenticatedLayout header="Annex A Controls">
            <Head title="Annex A Controls" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Annex A Controls' }]}
                title="Annex A Controls — ISO/IEC 27001:2022"
                subtitle="All 93 Annex A controls across 4 themes, with SoA applicability and Kano Heritage implementation status."
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Annex A controls" value={requirements.filter((r) => r.level > 0).length} tone="navy" />
                <KpiCard label="Tenant controls" value={totals.controls || 0} tone="gold" />
                <KpiCard label="Effective" value={totals.effective || 0} tone="green" />
                <KpiCard label="SoA rows" value={Object.keys(soa).length} tone="white" />
            </div>

            <div className="flex gap-2 mb-4 flex-wrap">
                {THEMES.map(([code, name]) => (
                    <button key={code}
                        onClick={() => setActive(code)}
                        className={`px-3 py-2 rounded-lg text-sm ${active === code ? 'bg-[#0A1F44] text-white' : 'bg-white border border-gray-200 text-[#2D3748]'}`}>
                        {code} — {name}
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
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {themeReqs.map((r) => {
                            const s = soa[r.id] || {};
                            return (
                                <tr key={r.id}>
                                    <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{r.requirement_code}</td>
                                    <td className="px-3 py-2 text-[#2D3748]">{r.title}</td>
                                    <td className="px-3 py-2">
                                        {s.is_applicable === 1 || s.is_applicable === true
                                            ? <StatusBadge status="pass" label="Applicable" />
                                            : s.is_applicable === 0 || s.is_applicable === false
                                                ? <StatusBadge status="draft" label="Excluded" />
                                                : <StatusBadge status="draft" label="Pending" />}
                                    </td>
                                    <td className="px-3 py-2">
                                        {s.implementation_status
                                            ? <StatusBadge status={statusTone(s.implementation_status)} label={String(s.implementation_status).replace('_', ' ')} />
                                            : <StatusBadge status="draft" label="—" />}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
