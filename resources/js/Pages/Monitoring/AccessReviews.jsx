import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { UserGroupIcon } from '@heroicons/react/24/outline';

export default function MonitoringAccessReviews({ reviews = [], summary = {} }) {
    return (
        <AuthenticatedLayout header="Access Reviews">
            <Head title="Access Reviews" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Access Reviews' }]}
                title="User Access Reviews"
                subtitle="Periodic recertification of privileged and sensitive system access. ISO A.8.3 / A.8.18, PCI R7, CBN RBCSF 2.4."
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Review cycles" value={summary.cycles || 0} tone="navy" icon={UserGroupIcon} />
                <KpiCard label="In progress" value={summary.in_progress || 0} tone="amber" />
                <KpiCard label="Completed" value={summary.completed || 0} tone="green" />
                <KpiCard label="Overdue" value={summary.overdue || 0} tone="red" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Scope</th>
                            <th className="px-3 py-2">Cadence</th>
                            <th className="px-3 py-2">Reviewer</th>
                            <th className="px-3 py-2">Coverage</th>
                            <th className="px-3 py-2">Progress</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Due</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {reviews.map((r) => (
                            <tr key={r.id}>
                                <td className="px-3 py-2 text-[#2D3748]">{r.scope}</td>
                                <td className="px-3 py-2 text-xs text-[#718096] capitalize">{r.cadence}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.reviewer}</td>
                                <td className="px-3 py-2 text-xs">{r.reviewed_entitlements} / {r.total_entitlements}</td>
                                <td className="px-3 py-2">
                                    <div className="h-2 bg-gray-100 rounded overflow-hidden w-24">
                                        <div className={`h-2 rounded ${r.coverage_percent >= 90 ? 'bg-[#2D7D46]' : r.coverage_percent >= 50 ? 'bg-[#E5A100]' : 'bg-[#B3261E]'}`} style={{ width: `${r.coverage_percent}%` }} />
                                    </div>
                                    <span className="text-[10px] text-[#718096]">{r.coverage_percent}%</span>
                                </td>
                                <td className="px-3 py-2"><StatusBadge status={r.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.due_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
