import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

const classTone = { Sensitive: 'critical', Personal: 'high', Confidential: 'warn', Internal: 'moderate', Public: 'low' };

export default function LogicalEntities({ entities = [], byClassification = {} }) {
    return (
        <AuthenticatedLayout header="Logical Entities">
            <Head title="Logical Entities" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Logical Entities' }]}
                title="Logical Entities"
                subtitle="Business-level data entities with NDPA classification, PII flags, and attribute lists."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.logical-entities')} current="ea.logical-entities" />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                {['Sensitive','Personal','Confidential','Internal','Public'].map((k) => (
                    <KpiCard key={k} label={k} value={(byClassification[k] || []).length || 0} tone={classTone[k] === 'critical' ? 'red' : classTone[k] === 'high' ? 'amber' : classTone[k] === 'warn' ? 'gold' : 'white'} />
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Domain</th>
                            <th className="px-3 py-2">Classification</th>
                            <th className="px-3 py-2">PII</th>
                            <th className="px-3 py-2">Attributes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {entities.map((e) => (
                            <tr key={e.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{e.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{e.name}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.domain?.name || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={classTone[e.classification] || 'moderate'} label={e.classification} /></td>
                                <td className="px-3 py-2">{e.pii_flag ? <StatusBadge status="critical" label="PII" /> : '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{(e.attributes || []).length} attrs</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
