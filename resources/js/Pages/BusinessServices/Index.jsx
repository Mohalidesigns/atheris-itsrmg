import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function BusinessServicesIndex({ services = [], capabilities = [], dependencies = [] }) {
    return (
        <AuthenticatedLayout header="Business Services">
            <Head title="Business Services" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Business Services' }]}
                title="Business Services"
                subtitle="Nigerian DMB reference model — channels, services, processes and their dependencies."
                actions={<Link href={route('business-services.graph')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open graph view</Link>}
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Capabilities</h3>
                    <ul className="mt-2 divide-y divide-gray-100">
                        {capabilities.map((c) => (
                            <li key={c.id} className="py-2 text-sm text-[#2D3748]">{c.name}</li>
                        ))}
                    </ul>
                </div>
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Service</th>
                                <th className="px-3 py-2">Capability</th>
                                <th className="px-3 py-2">Criticality</th>
                                <th className="px-3 py-2">RTO (min)</th>
                                <th className="px-3 py-2">RPO (min)</th>
                                <th className="px-3 py-2">Processes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {services.map((s) => (
                                <tr key={s.id}>
                                    <td className="px-3 py-2 font-medium text-[#0A1F44]">{s.name}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{s.capability?.name || '—'}</td>
                                    <td className="px-3 py-2"><StatusBadge status={s.criticality} /></td>
                                    <td className="px-3 py-2 text-xs">{s.recovery_time_objective_min}</td>
                                    <td className="px-3 py-2 text-xs">{s.recovery_point_objective_min}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{s.processes?.length || 0}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
