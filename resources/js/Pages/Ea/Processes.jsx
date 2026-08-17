import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function Processes({ procs = [], byLevel = {} }) {
    return (
        <AuthenticatedLayout header="Process Inventory">
            <Head title="Process Inventory" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Process Inventory' }]}
                title="Process Inventory"
                subtitle="L1 / L2 / L3 process hierarchy with RTO, RPO and linked applications (BCM read-through)."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.processes')} current="ea.processes" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Total processes" value={procs.length} tone="navy" />
                <KpiCard label="L1" value={(byLevel[1] || []).length} tone="gold" />
                <KpiCard label="L2" value={(byLevel[2] || []).length} tone="white" />
                <KpiCard label="L3" value={(byLevel[3] || []).length} tone="white" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Level</th>
                            <th className="px-3 py-2">Criticality</th>
                            <th className="px-3 py-2">RTO / RPO</th>
                            <th className="px-3 py-2">Linked apps</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {procs.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{p.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{p.name}</td>
                                <td className="px-3 py-2 text-xs">L{p.level}</td>
                                <td className="px-3 py-2"><StatusBadge status={p.criticality === 'critical' ? 'critical' : p.criticality === 'high' ? 'high' : 'moderate'} label={p.criticality} /></td>
                                <td className="px-3 py-2 text-xs">{p.rto_hours ?? '—'}h / {p.rpo_hours ?? '—'}h</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{(p.linked_applications || []).length} app(s)</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
