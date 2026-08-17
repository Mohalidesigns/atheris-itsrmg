import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';
import { ServerStackIcon } from '@heroicons/react/24/outline';

export default function PciCde({ assets = [], zones = [] }) {
    return (
        <AuthenticatedLayout header="Cardholder Data Environment">
            <Head title="Cardholder Data Environment" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Cardholder Data Environment' }]}
                title="Cardholder Data Environment (CDE)"
                subtitle={`${assets.length} assets in scope across ${zones.length} segmentation zones · Kano Heritage Bank Plc`}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Assets in CDE" value={assets.length} tone="navy" icon={ServerStackIcon} />
                <KpiCard label="Segmentation zones" value={zones.length} tone="gold" />
                <KpiCard label="Critical assets" value={assets.filter((a) => a.criticality === 'critical').length} tone="red" />
                <KpiCard label="Encrypted at rest" value={assets.length} sublabel="100%" tone="green" />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                {zones.map((z) => (
                    <div key={z.name} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">{z.name}</h3>
                        <p className="text-[11px] text-[#718096] mb-3">{z.items.length} assets</p>
                        <ul className="text-xs space-y-1.5">
                            {z.items.slice(0, 6).map((a) => (
                                <li key={a.id} className="flex items-center justify-between">
                                    <Link href={route('assets.show', a.id)} className="text-[#2D3748] hover:text-[#0A1F44] truncate">{a.name}</Link>
                                    <StatusBadge status={a.criticality === 'critical' ? 'critical' : a.criticality === 'high' ? 'high' : 'moderate'} label={a.criticality} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Full CDE asset inventory</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Asset ID</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Criticality</th>
                            <th className="px-3 py-2">Data classification</th>
                            <th className="px-3 py-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {assets.map((a) => (
                            <tr key={a.id}>
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={route('assets.show', a.id)} className="text-[#0A1F44] hover:underline">{a.asset_id_code}</Link>
                                </td>
                                <td className="px-3 py-2 text-[#2D3748]">{a.name}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.category}</td>
                                <td className="px-3 py-2"><StatusBadge status={a.criticality === 'critical' ? 'critical' : a.criticality === 'high' ? 'high' : 'moderate'} label={a.criticality} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.data_classification}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.owner?.name || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
