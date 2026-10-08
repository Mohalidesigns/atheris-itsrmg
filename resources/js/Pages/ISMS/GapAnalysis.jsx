import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head } from '@inertiajs/react';

// Gaps use the platform severity scale; ISMS shows it in ISO audit non-conformity terms.
const severities = ['critical', 'high', 'medium', 'low'];
const NC_LABEL = { critical: 'Critical', high: 'Major', medium: 'Moderate', low: 'Minor' };
const severityTone = (s) => ({ critical: 'critical', high: 'high', medium: 'moderate', low: 'low' })[s] || 'moderate';

export default function IsmsGapAnalysis({ gaps = [], severityBuckets = {}, statusBuckets = {}, heat = {} }) {
    const themes = ['A.5', 'A.6', 'A.7', 'A.8'];
    const cellColor = (n) => n === 0 ? 'bg-[#2D7D46]/20' : n <= 2 ? 'bg-[#E5A100]/30' : n <= 5 ? 'bg-[#B3261E]/40' : 'bg-[#B3261E]/70';

    return (
        <AuthenticatedLayout header="Gap Analysis">
            <Head title="ISO 27001 Gap Analysis" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Gap Analysis' }]}
                title="ISO 27001:2022 Gap Analysis"
                subtitle="Per-control gaps with severity, owner, due date and remediation plan. Heat grid groups by Annex A theme × severity."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Total gaps" value={gaps.length} tone="navy" />
                <KpiCard label="Critical" value={severityBuckets.critical || 0} tone="red" />
                <KpiCard label="Major" value={severityBuckets.high || 0} tone="amber" />
                <KpiCard label="Moderate" value={severityBuckets.medium || 0} tone="navy" />
                <KpiCard label="Minor" value={severityBuckets.low || 0} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4 overflow-x-auto">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Heat grid — severity × Annex A theme</h3>
                <table className="text-xs">
                    <thead>
                        <tr>
                            <th className="p-2"></th>
                            {severities.map((s) => <th key={s} className="p-2 text-[#718096]">{NC_LABEL[s]}</th>)}
                            <th className="p-2 text-[#718096]">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {themes.map((t) => {
                            const total = severities.reduce((a, s) => a + (heat[t]?.[s] || 0), 0);
                            return (
                                <tr key={t}>
                                    <td className="p-2 font-mono font-semibold text-[#0A1F44]">{t}</td>
                                    {severities.map((s) => {
                                        const n = heat[t]?.[s] || 0;
                                        return <td key={s} className={`p-3 text-center font-semibold text-[#0A1F44] ${cellColor(n)}`}>{n}</td>;
                                    })}
                                    <td className="p-2 text-right font-semibold text-[#0A1F44]">{total}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">All gaps</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">ID</th>
                            <th className="px-3 py-2">Annex A</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Assignee</th>
                            <th className="px-3 py-2">Due</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {gaps.map((g) => (
                            <tr key={g.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{g.gap_code}</td>
                                <td className="px-3 py-2 font-mono text-xs text-[#C9A86A]">{g.requirement_code || '—'}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{g.title}</td>
                                <td className="px-3 py-2"><StatusBadge status={severityTone(g.severity)} label={NC_LABEL[g.severity] || g.severity} /></td>
                                <td className="px-3 py-2"><StatusBadge status={g.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{g.assignee_name || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{g.due_date}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
