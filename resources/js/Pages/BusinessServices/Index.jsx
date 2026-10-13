import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { CriticalityBadge } from '@/Components/Assets/AssetBadges';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function BusinessServicesIndex({ services = [], capabilities = [], totals = {} }) {
    const [capability, setCapability] = useState(null);
    const shown = capability ? services.filter((s) => s.capability === capability) : services;

    return (
        <AuthenticatedLayout header="Business Services">
            <Head title="Business Services" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Business Services' }]}
                title="Business Services"
                subtitle="The bank's services by capability, with recovery objectives, the physical assets that support each one, and how services depend on each other. Read from the EA business architecture."
                actions={<Link href={route('business-services.graph')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open dependency graph</Link>}
            />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Business services" value={totals.services ?? 0} tone="navy" />
                <KpiCard label="Critical" value={totals.critical ?? 0} tone="white" />
                <KpiCard label="Without an owner" value={totals.unowned ?? 0} tone={totals.unowned ? 'amber' : 'white'} />
                <KpiCard label="No supporting asset" value={totals.without_assets ?? 0} tone={totals.without_assets ? 'amber' : 'white'} />
                <KpiCard label="Dependencies" value={totals.dependencies ?? 0} sublabel="service → service" tone="white" />
            </div>

            {services.length === 0 ? (
                <div className="rounded-xl border border-gray-100 bg-white p-10 text-center text-sm text-[#718096]">
                    No business services are registered for this bank yet. <Link href={route('business-assets.create')} className="text-[#0A1F44] underline">Add the first one</Link>.
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Capabilities</h3>
                        <ul className="mt-2 divide-y divide-gray-100">
                            <li>
                                <button type="button" onClick={() => setCapability(null)} className={`w-full py-2 text-left text-sm ${!capability ? 'font-semibold text-[#0A1F44]' : 'text-[#2D3748]'}`}>
                                    All capabilities <span className="float-right text-xs text-[#718096]">{services.length}</span>
                                </button>
                            </li>
                            {capabilities.map((c) => (
                                <li key={c.id}>
                                    <button type="button" onClick={() => setCapability(c.name)} className={`w-full py-2 text-left text-sm ${capability === c.name ? 'font-semibold text-[#0A1F44]' : 'text-[#2D3748]'}`}>
                                        <span className="font-mono-data text-[10px] text-[#718096]">{c.code}</span> {c.name}
                                        <span className="float-right text-xs text-[#718096]">{c.services_count}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="lg:col-span-3 bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Service</th>
                                    <th className="px-3 py-2">Capability</th>
                                    <th className="px-3 py-2">Criticality</th>
                                    <th className="px-3 py-2">Owner</th>
                                    <th className="px-3 py-2 text-right">RTO / RPO (min)</th>
                                    <th className="px-3 py-2 text-center">Assets</th>
                                    <th className="px-3 py-2 text-center">Depends on</th>
                                    <th className="px-3 py-2 text-center">Serves</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {shown.map((s) => (
                                    <tr key={s.id} className="hover:bg-gray-50/50">
                                        <td className="px-3 py-2">
                                            <Link href={route('business-assets.show', s.id)} className="font-medium text-[#0A1F44] hover:underline">{s.name}</Link>
                                            <p className="font-mono-data text-[10px] text-[#718096]">{s.code}</p>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{s.capability || '—'}</td>
                                        <td className="px-3 py-2"><CriticalityBadge criticality={s.criticality} /></td>
                                        <td className={`px-3 py-2 text-xs ${s.owner ? 'text-[#718096]' : 'text-[#B3261E]'}`}>{s.owner || 'Unowned'}</td>
                                        <td className="px-3 py-2 text-right text-xs font-mono-data">{s.rto_minutes ?? '—'} / {s.rpo_minutes ?? '—'}</td>
                                        <td className={`px-3 py-2 text-center text-xs ${s.assets_count ? '' : 'font-semibold text-[#B3261E]'}`}>{s.assets_count}</td>
                                        <td className="px-3 py-2 text-center text-xs">{s.depends_on}</td>
                                        <td className="px-3 py-2 text-center text-xs">{s.dependents}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
