import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

const covTone = { full: 'pass', partial: 'warn', planned: 'draft', gap: 'fail' };

export default function ControlMappings({ mappings = [], coverage = {} }) {
    return (
        <AuthenticatedLayout header="Control Mappings">
            <Head title="Control Mappings" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Control Mappings' }]}
                title="Control-to-Component Mapping"
                subtitle="Every component tagged as processing sensitive data has controls mapped from ISMS / PCI / CBN / NDPA catalogues."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.control-mappings')} current="ea.control-mappings" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Mapped controls" value={mappings.length} tone="navy" />
                <KpiCard label="Full coverage" value={coverage.full || 0} tone="green" />
                <KpiCard label="Partial / planned" value={(coverage.partial || 0) + (coverage.planned || 0)} tone="amber" />
                <KpiCard label="Gaps" value={coverage.gap || 0} tone="red" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Component</th>
                            <th className="px-3 py-2">Control ID</th>
                            <th className="px-3 py-2">Framework</th>
                            <th className="px-3 py-2">Coverage</th>
                            <th className="px-3 py-2">Notes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {mappings.map((m) => (
                            <tr key={m.id}>
                                <td className="px-3 py-2 text-xs">{m.component_type} #{m.component_id}</td>
                                <td className="px-3 py-2 font-mono text-xs">{m.control_id ? 'CTL-'+m.control_id : '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{m.framework}</td>
                                <td className="px-3 py-2"><StatusBadge status={covTone[m.coverage] || 'warn'} label={m.coverage} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096] max-w-md">{m.notes || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
