import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { ChartBarIcon, ArrowPathIcon } from '@heroicons/react/24/outline';

export default function MonitoringDashboard({ kpis = {}, byStatus = {}, trend = [], recent = [] }) {
    const maxTrend = Math.max(...trend.map((d) => (d.pass || 0) + (d.warn || 0) + (d.fail || 0)), 1);
    return (
        <AuthenticatedLayout header="Continuous Monitoring">
            <Head title="Monitoring Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Dashboard' }]}
                title="Continuous Monitoring — Dashboard"
                subtitle="Live CCM results, drift events, KRI states and access-review progress for Kano Heritage Bank."
                actions={<Link href={route('ccm.index')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open CCM Console</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
                <KpiCard label="CCM tests" value={kpis.tests_total || 0} tone="navy" icon={ArrowPathIcon} />
                <KpiCard label="Pass" value={kpis.tests_pass || 0} tone="green" />
                <KpiCard label="Warn" value={kpis.tests_warn || 0} tone="amber" />
                <KpiCard label="Fail" value={kpis.tests_fail || 0} tone="red" />
                <KpiCard label="Runs (24h)" value={kpis.runs_24h || 0} tone="white" />
                <KpiCard label="KRIs in red" value={kpis.kris_red || 0} tone="red" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">14-day CCM run trend</h3>
                    <div className="flex items-end gap-1 h-40">
                        {trend.map((d) => {
                            const total = (d.pass || 0) + (d.warn || 0) + (d.fail || 0);
                            return (
                                <div key={d.day} className="flex-1 flex flex-col-reverse h-full gap-[1px]" title={`${d.day}: ${total}`}>
                                    <div className="bg-[#2D7D46]" style={{ height: `${((d.pass || 0) / maxTrend) * 100}%` }} />
                                    <div className="bg-[#E5A100]" style={{ height: `${((d.warn || 0) / maxTrend) * 100}%` }} />
                                    <div className="bg-[#B3261E]" style={{ height: `${((d.fail || 0) / maxTrend) * 100}%` }} />
                                </div>
                            );
                        })}
                    </div>
                    <div className="flex items-center justify-between text-[10px] text-[#718096] mt-2">
                        <span>{trend[0]?.day}</span>
                        <span>{trend[trend.length - 1]?.day}</span>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Run status breakdown</h3>
                    <ul className="space-y-2 text-xs">
                        {['pass', 'warn', 'fail', 'error'].map((s) => (
                            <li key={s}>
                                <div className="flex items-center justify-between">
                                    <span className="capitalize text-[#2D3748]">{s}</span>
                                    <span className="font-semibold text-[#0A1F44]">{byStatus[s] || 0}</span>
                                </div>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-1">
                                    <div className="h-2 rounded"
                                        style={{
                                            width: `${((byStatus[s] || 0) / Math.max(...Object.values(byStatus || {1:1}), 1)) * 100}%`,
                                            background: s === 'pass' ? '#2D7D46' : s === 'warn' ? '#E5A100' : s === 'fail' ? '#B3261E' : '#718096',
                                        }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent CCM runs</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Test</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Latency</th>
                            <th className="px-3 py-2">Output summary</th>
                            <th className="px-3 py-2">Ran at</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {recent.map((r) => (
                            <tr key={r.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{r.tenant_test?.test?.code || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={r.status} /></td>
                                <td className="px-3 py-2 text-xs">{r.latency_ms}ms</td>
                                <td className="px-3 py-2 text-xs text-[#718096] truncate max-w-md">{r.output_summary}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.ran_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
