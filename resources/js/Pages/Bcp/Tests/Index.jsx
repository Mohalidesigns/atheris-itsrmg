import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { BeakerIcon } from '@heroicons/react/24/outline';

const passFailTone = (p) => ({ pass: 'pass', partial: 'warn', fail: 'fail' })[p] || 'draft';

export default function TestsIndex({ plans = [], tests = [], kpis = {} }) {
    return (
        <AuthenticatedLayout header="BCP/DR Tests & Exercises">
            <Head title="BCP Tests & Exercises" />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'Tests & Exercises' }]}
                title="BCP / DR Tests & Exercises"
                subtitle="Scheduled and completed tabletop, walkthrough, simulation, parallel, and full-interruption exercises."
                actions={<Link href={route('bcp.plans')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">View BCP Plans</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Total exercises" value={kpis.total || 0} tone="navy" icon={BeakerIcon} />
                <KpiCard label="Passed" value={kpis.passed || 0} tone="green" />
                <KpiCard label="Passed w/ issues" value={kpis.passed_with_issues || 0} tone="amber" />
                <KpiCard label="Failed" value={kpis.failed || 0} tone="red" />
                <KpiCard label="Pass rate" value={`${kpis.pass_rate || 0}%`} tone="gold" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Test history</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Plan</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2">Scheduled</th>
                            <th className="px-3 py-2">Conductor</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Outcome</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tests.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-xs text-[#718096]">No BCP test runs recorded.</td></tr>}
                        {tests.map((t) => (
                            <tr key={t.id}>
                                <td className="px-3 py-2 text-xs text-[#0A1F44]">{t.plan_title}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{t.title}</td>
                                <td className="px-3 py-2 text-xs text-[#718096] capitalize">{String(t.test_type || '').replace('_', ' ')}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.scheduled_date}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.conductor_name || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={t.status || 'draft'} /></td>
                                <td className="px-3 py-2">{t.pass_fail ? <StatusBadge status={passFailTone(t.pass_fail)} label={t.pass_fail} /> : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Plans in scope</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">RTO</th>
                            <th className="px-3 py-2">RPO</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {plans.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={route('bcp.plans.show', p.id)} className="text-[#0A1F44] hover:underline">{p.plan_code}</Link>
                                </td>
                                <td className="px-3 py-2 text-[#2D3748]">{p.title}</td>
                                <td className="px-3 py-2 text-xs uppercase text-[#718096]">{p.plan_type}</td>
                                <td className="px-3 py-2"><StatusBadge status={p.status} /></td>
                                <td className="px-3 py-2 text-xs">{p.rto_hours}h</td>
                                <td className="px-3 py-2 text-xs">{p.rpo_hours}h</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
