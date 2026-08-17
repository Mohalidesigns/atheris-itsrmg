import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { GlobeAltIcon } from '@heroicons/react/24/outline';

export default function DataFlows({ flows = [], crossBorder = 0 }) {
    return (
        <AuthenticatedLayout header="Data Flows">
            <Head title="Data Flows" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Data Flows' }]}
                title="Data Flows"
                subtitle="Entity-to-entity flows with NDPA classification and cross-border markers (DPO alerted on create)."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.data-flows')} current="ea.data-flows" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Total flows" value={flows.length} tone="navy" />
                <KpiCard label="Cross-border" value={crossBorder} tone="red" icon={GlobeAltIcon} />
                <KpiCard label="Internal flows" value={flows.length - crossBorder} tone="green" />
                <KpiCard label="PII carrying" value={flows.filter((f) => f.classification === 'Personal' || f.classification === 'Sensitive').length} tone="amber" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Flow</th>
                            <th className="px-3 py-2">From</th>
                            <th className="px-3 py-2">To</th>
                            <th className="px-3 py-2">Protocol</th>
                            <th className="px-3 py-2">Classification</th>
                            <th className="px-3 py-2">Cross-border</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {flows.map((f) => (
                            <tr key={f.id}>
                                <td className="px-3 py-2 text-[#2D3748]">{f.name}</td>
                                <td className="px-3 py-2 text-xs">{f.source?.name || '—'}</td>
                                <td className="px-3 py-2 text-xs">{f.target?.name || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{f.protocol}</td>
                                <td className="px-3 py-2"><StatusBadge status={f.classification === 'Sensitive' || f.classification === 'Personal' ? 'critical' : 'moderate'} label={f.classification} /></td>
                                <td className="px-3 py-2">{f.cross_border ? <StatusBadge status="critical" label="Cross-border" /> : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
