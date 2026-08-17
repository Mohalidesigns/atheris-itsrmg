import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function CbnMaturity({ assessment, domains = [], responses = {}, byDomain = [] }) {
    return (
        <AuthenticatedLayout header="CBN EA Maturity">
            <Head title="CBN EA Maturity" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'CBN EA Maturity' }]}
                title="CBN EA Maturity Self-Assessment"
                subtitle="Five-level maturity rating per CBN EA Framework domain. Phase 1 gate criterion: overall ≥ 3.0."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.cbn-maturity')} current="ea.cbn-maturity" />
            {assessment && (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <KpiCard label="Overall score" value={Number(assessment.overall_score || 0).toFixed(2)} sublabel="/ 5.00" tone="navy" />
                    <KpiCard label="Status" value={assessment.status} tone="white" />
                    <KpiCard label="Year" value={assessment.year} tone="gold" />
                    <KpiCard label="Answered" value={Object.keys(responses || {}).length} tone="white" />
                </div>
            )}

            <div className="space-y-4">
                {byDomain.map((row) => (
                    <div key={row.domain.id} className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                        <div className="p-4 border-b border-gray-100 flex items-center justify-between">
                            <div>
                                <p className="text-xs font-mono text-[#C9A86A]">{row.domain.code}</p>
                                <h3 className="text-sm font-semibold text-[#0A1F44]">{row.domain.name}</h3>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-bold text-[#0A1F44]">{Number(row.avg).toFixed(1)}</p>
                                <p className="text-[10px] text-[#718096]">{row.answered}/{row.total} answered</p>
                            </div>
                        </div>
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Code</th>
                                    <th className="px-3 py-2">Question</th>
                                    <th className="px-3 py-2">Level</th>
                                    <th className="px-3 py-2">Comment</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {(row.domain.questions || []).map((q) => {
                                    const r = responses[q.id];
                                    return (
                                        <tr key={q.id}>
                                            <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{q.code}</td>
                                            <td className="px-3 py-2 text-[#2D3748]">{q.question}</td>
                                            <td className="px-3 py-2">
                                                {r
                                                    ? <StatusBadge status={r.selected_level >= 4 ? 'pass' : r.selected_level >= 3 ? 'warn' : 'fail'} label={`L${r.selected_level}`} />
                                                    : <span className="text-xs text-[#718096]">—</span>}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">{r?.comment || '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
