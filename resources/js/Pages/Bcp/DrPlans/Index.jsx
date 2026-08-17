import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { ServerStackIcon } from '@heroicons/react/24/outline';

export default function DrPlansIndex({ drPlans = [], runbooks = [], exercises = [], kpis = {} }) {
    return (
        <AuthenticatedLayout header="DR Plans">
            <Head title="DR Plans" />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Plans' }]}
                title="Disaster Recovery Plans"
                subtitle="Nigerian DMB DR plans — core banking, payments, channels, crisis and pandemic response."
                actions={<Link href={route('dr.runbooks')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open DR Runbooks</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="DR plans" value={kpis.dr_plans || 0} tone="navy" icon={ServerStackIcon} />
                <KpiCard label="Runbooks" value={kpis.runbooks || 0} tone="gold" />
                <KpiCard label="Exercises YTD" value={kpis.exercises_ytd || 0} tone="green" />
                <KpiCard label="Avg RTO" value={`${kpis.avg_rto_hours || 0}h`} tone="white" />
                <KpiCard label="Avg RPO" value={`${kpis.avg_rpo_hours || 0}h`} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">DR plans</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Owner</th>
                            <th className="px-3 py-2">RTO</th>
                            <th className="px-3 py-2">RPO</th>
                            <th className="px-3 py-2">Last tested</th>
                            <th className="px-3 py-2">Next test</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {drPlans.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={route('bcp.plans.show', p.id)} className="text-[#0A1F44] hover:underline">{p.plan_code}</Link>
                                </td>
                                <td className="px-3 py-2 text-[#2D3748]">{p.title}</td>
                                <td className="px-3 py-2"><StatusBadge status={p.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{p.owner?.name || '—'}</td>
                                <td className="px-3 py-2 text-xs">{p.rto_hours}h</td>
                                <td className="px-3 py-2 text-xs">{p.rpo_hours}h</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{p.last_tested || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{p.next_test_date || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Nigerian DR runbooks</h3>
                    <ul className="divide-y divide-gray-100">
                        {runbooks.map((r) => (
                            <li key={r.id} className="p-3">
                                <Link href={route('dr.runbook.show', r.id)} className="block hover:bg-gray-50 -mx-3 px-3 py-1 rounded">
                                    <p className="text-sm font-semibold text-[#2D3748]">{r.name}</p>
                                    <p className="text-xs text-[#718096]">{r.category} · ~{r.estimated_duration_min} min</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent exercises</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Runbook</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Scheduled</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {exercises.map((e) => (
                                <tr key={e.id}>
                                    <td className="px-3 py-2 text-[#2D3748]">{e.runbook_name || '—'}</td>
                                    <td className="px-3 py-2"><StatusBadge status={e.status === 'completed' ? 'active' : e.status} label={e.status} /></td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{e.scheduled_at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
