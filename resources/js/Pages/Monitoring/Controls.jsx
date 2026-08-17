import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

const effTone = (e) => ({ effective: 'pass', partially_effective: 'warn', ineffective: 'fail', not_assessed: 'draft' })[e] || 'draft';

export default function MonitoringControls({ controls = [], buckets = {} }) {
    return (
        <AuthenticatedLayout header="Controls Monitoring">
            <Head title="Controls Monitoring" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Controls Monitoring' }]}
                title="Controls Monitoring — Effectiveness"
                subtitle="Every tenant control with its current effectiveness rating, last-tested date, and owner."
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Effective" value={buckets.effective || 0} tone="green" />
                <KpiCard label="Partially effective" value={buckets.partially_effective || 0} tone="amber" />
                <KpiCard label="Ineffective" value={buckets.ineffective || 0} tone="red" />
                <KpiCard label="Not assessed" value={buckets.not_assessed || 0} tone="white" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Domain</th>
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2">Frequency</th>
                            <th className="px-3 py-2">Effectiveness</th>
                            <th className="px-3 py-2">Last tested</th>
                            <th className="px-3 py-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {controls.map((c) => (
                            <tr key={c.id}>
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={route('controls.show', c.id)} className="text-[#0A1F44] hover:underline">{c.control_code}</Link>
                                </td>
                                <td className="px-3 py-2 text-[#2D3748]">{c.title}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.domain}</td>
                                <td className="px-3 py-2 text-xs text-[#718096] capitalize">{c.type}</td>
                                <td className="px-3 py-2 text-xs text-[#718096] capitalize">{c.frequency}</td>
                                <td className="px-3 py-2">
                                    <StatusBadge status={effTone(c.effectiveness)} label={String(c.effectiveness || 'n/a').replace('_', ' ')} />
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.last_tested || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.owner?.name || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
