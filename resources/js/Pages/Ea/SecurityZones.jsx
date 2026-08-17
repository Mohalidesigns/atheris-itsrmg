import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function SecurityZones({ zones = [], assignments = [] }) {
    const byZone = assignments.reduce((a, x) => { (a[x.zone_id] ||= []).push(x); return a; }, {});
    return (
        <AuthenticatedLayout header="Security Zones">
            <Head title="Security Zones" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Security Zones' }]}
                title="Security Architecture — Zones"
                subtitle="Trust zones with application assignment. Transition between zones requires ARB approval."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.security-zones')} current="ea.security-zones" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Zones" value={zones.length} tone="navy" />
                <KpiCard label="Assigned apps" value={assignments.length} tone="gold" />
                <KpiCard label="High-trust zones" value={zones.filter((z) => z.trust_level >= 4).length} tone="green" />
                <KpiCard label="Low-trust zones" value={zones.filter((z) => z.trust_level <= 2).length} tone="red" />
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {zones.map((z) => (
                    <div key={z.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-xs font-mono text-[#0A1F44]">{z.code}</p>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{z.name}</h3>
                            </div>
                            <StatusBadge status={z.trust_level >= 4 ? 'green' : z.trust_level >= 3 ? 'amber' : 'red'} label={`Trust ${z.trust_level}/5`} />
                        </div>
                        <p className="text-xs text-[#718096] mt-2 line-clamp-3">{z.description}</p>
                        <p className="text-xs text-[#718096] mt-2 font-semibold">{(byZone[z.id] || []).length} application(s) assigned</p>
                        <ul className="mt-2 text-[11px] text-[#2D3748] space-y-0.5 max-h-40 overflow-y-auto">
                            {(byZone[z.id] || []).slice(0, 10).map((a) => (
                                <li key={a.id} className="truncate">· {a.application?.name || `App #${a.application_id}`}</li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
