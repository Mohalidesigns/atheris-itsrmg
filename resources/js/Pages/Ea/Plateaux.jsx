import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function Plateaux({ plateaux = [], initiatives = [] }) {
    return (
        <AuthenticatedLayout header="Plateaux">
            <Head title="Plateaux" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Plateaux' }]}
                title="Plateaux — Current / Target / Transition"
                subtitle="Plateaux model the architecture's shape at points in time; initiatives move the estate from one to the next."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.plateaux')} current="ea.plateaux" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Plateaux" value={plateaux.length} tone="navy" />
                <KpiCard label="Current" value={plateaux.filter((p) => p.plateau_type === 'current').length} tone="white" />
                <KpiCard label="Target" value={plateaux.filter((p) => p.plateau_type === 'target').length} tone="gold" />
                <KpiCard label="Transition" value={plateaux.filter((p) => p.plateau_type === 'transition').length} tone="amber" />
            </div>
            <div className="space-y-4">
                {plateaux.map((p) => {
                    const inits = initiatives.filter((i) => i.plateau_id === p.id);
                    return (
                        <div key={p.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-xs font-mono text-[#0A1F44]">{p.code}</p>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{p.name}</h3>
                                    <p className="text-xs text-[#718096]">{p.effective_from} → {p.effective_to}</p>
                                </div>
                                <StatusBadge status={p.plateau_type === 'current' ? 'active' : p.plateau_type === 'target' ? 'moderate' : 'amber'} label={p.plateau_type} />
                            </div>
                            {p.description && <p className="text-xs text-[#718096] mt-2">{p.description}</p>}
                            {inits.length > 0 && (
                                <div className="mt-3 pt-3 border-t border-gray-100">
                                    <p className="text-xs text-[#718096] font-semibold uppercase">{inits.length} initiative(s)</p>
                                    <ul className="text-xs text-[#2D3748] mt-1 space-y-0.5">
                                        {inits.slice(0, 6).map((i) => <li key={i.id}>· {i.name} <span className="text-[#718096]">({i.status})</span></li>)}
                                    </ul>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
