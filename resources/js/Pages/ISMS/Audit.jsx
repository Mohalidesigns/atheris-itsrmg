import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head } from '@inertiajs/react';

const severityTone = (s) => ({ critical: 'critical', major: 'high', moderate: 'moderate', minor: 'low' })[s] || 'moderate';

export default function IsmsAudit({ audits = [], nonConformities = [] }) {
    const openNC = nonConformities.filter((n) => ['open', 'in_progress'].includes(n.status)).length;
    const resolvedNC = nonConformities.filter((n) => n.status === 'resolved').length;
    return (
        <AuthenticatedLayout header="ISMS Audit">
            <Head title="ISMS Audit" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Audit' }]}
                title="ISMS Audit — Internal ISO 27001 Audits"
                subtitle="Planned + completed internal audits with non-conformity tracking per Clause 9.2."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Audits" value={audits.length} tone="navy" />
                <KpiCard label="Non-conformities" value={nonConformities.length} tone="gold" />
                <KpiCard label="Open" value={openNC} tone="red" />
                <KpiCard label="Resolved" value={resolvedNC} tone="green" />
                <KpiCard label="Avg findings / audit" value={audits.length ? Math.round(nonConformities.length / audits.length) : 0} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-6">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Internal audit cycles</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Lead Assessor</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Score</th>
                            <th className="px-3 py-2">Compliant</th>
                            <th className="px-3 py-2">Non-compliant</th>
                            <th className="px-3 py-2">End</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {audits.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-xs text-[#718096]">No ISO 27001 audits seeded for Kano Heritage yet.</td></tr>}
                        {audits.map((a) => (
                            <tr key={a.id}>
                                <td className="px-3 py-2 text-[#2D3748]">{a.title}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.lead_assessor?.name || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={a.status} /></td>
                                <td className="px-3 py-2 text-[#0A1F44] font-semibold">{a.overall_score}%</td>
                                <td className="px-3 py-2 text-[#2D7D46]">{a.compliant_count}</td>
                                <td className="px-3 py-2 text-[#B3261E]">{a.non_compliant_count}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.end_date}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Non-conformities (CAPA)</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Gap ID</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Assignee</th>
                            <th className="px-3 py-2">Due</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {nonConformities.map((n) => (
                            <tr key={n.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{n.gap_code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{n.title}</td>
                                <td className="px-3 py-2"><StatusBadge status={severityTone(n.severity)} label={n.severity} /></td>
                                <td className="px-3 py-2"><StatusBadge status={n.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{n.assignee_name || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{n.due_date}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
