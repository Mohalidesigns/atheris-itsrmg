import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function IsmsRisks({ risks = [], distribution = {} }) {
    return (
        <AuthenticatedLayout header="ISMS Risks">
            <Head title="ISMS Risks" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Risks' }]}
                title="ISMS Risks"
                subtitle="Information-security risks within the ISMS scope, with residual score and treatment. Drilled through to the full Risk Register for edit actions."
                actions={<Link href={route('risks.index')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Full Risk Register →</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Critical" value={distribution.critical || 0} tone="red" />
                <KpiCard label="High" value={distribution.high || 0} tone="amber" />
                <KpiCard label="Medium" value={distribution.medium || 0} tone="navy" />
                <KpiCard label="Low" value={distribution.low || 0} tone="green" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Risk ID</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Owner</th>
                            <th className="px-3 py-2">Inherent</th>
                            <th className="px-3 py-2">Residual</th>
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {risks.map((r) => (
                            <tr key={r.id} className="hover:bg-gray-50">
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={route('risks.show', r.id)} className="text-[#0A1F44] hover:underline">{r.risk_id_code}</Link>
                                </td>
                                <td className="px-3 py-2 text-[#2D3748]">
                                    <Link href={route('risks.show', r.id)} className="hover:text-[#0A1F44]">{r.title}</Link>
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.category?.name || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.owner?.name || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={r.inherent_rating || 'low'} label={`I:${r.inherent_score}`} /></td>
                                <td className="px-3 py-2"><StatusBadge status={r.residual_rating || 'low'} label={`R:${r.residual_score}`} /></td>
                                <td className="px-3 py-2"><StatusBadge status={r.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
