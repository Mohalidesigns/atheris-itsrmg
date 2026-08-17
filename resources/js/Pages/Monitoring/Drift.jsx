import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { ArrowPathIcon } from '@heroicons/react/24/outline';

export default function MonitoringDrift({ drift = [], buckets = {} }) {
    return (
        <AuthenticatedLayout header="Drift Detection">
            <Head title="Drift Detection" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Drift Detection' }]}
                title="Configuration Drift Detection"
                subtitle="Drift events detected by AWS Config, Defender for Cloud, Wazuh FIM and Tenable SC. Investigated or accepted per change control."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Events" value={drift.length} tone="navy" icon={ArrowPathIcon} />
                <KpiCard label="Open" value={buckets.open || 0} tone="red" />
                <KpiCard label="Investigating" value={buckets.investigating || 0} tone="amber" />
                <KpiCard label="Remediated" value={buckets.remediated || 0} tone="green" />
                <KpiCard label="Accepted" value={buckets.accepted || 0} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Detected</th>
                            <th className="px-3 py-2">System</th>
                            <th className="px-3 py-2">Drift type</th>
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">Source</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {drift.map((d) => (
                            <tr key={d.id}>
                                <td className="px-3 py-2 text-xs text-[#718096]">{d.detected_at}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{d.system}</td>
                                <td className="px-3 py-2 text-xs">{d.type}</td>
                                <td className="px-3 py-2"><StatusBadge status={d.severity} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{d.source}</td>
                                <td className="px-3 py-2"><StatusBadge status={d.status === 'remediated' ? 'active' : d.status === 'open' ? 'critical' : 'draft'} label={d.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{d.owner}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
