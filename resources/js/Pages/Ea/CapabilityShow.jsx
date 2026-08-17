import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import ImpactTab from '@/Components/Ea/ImpactTab';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import useEaPermissions from '@/Components/Ea/useEaPermissions';

// Appendix A #5: "Inline rename + criticality" on the capability detail page.
const FIELDS = [
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Name', type: 'text', required: true },
    { name: 'description', label: 'Description', type: 'textarea', width: 'full' },
    { name: 'level', label: 'Level (1-5)', type: 'number', min: 1, max: 5 },
    { name: 'criticality', label: 'Criticality', type: 'select', options: ['critical', 'high', 'medium', 'low'] },
    { name: 'maturity', label: 'Maturity (1-5)', type: 'number', min: 1, max: 5 },
    { name: 'owner_role', label: 'Owner role', type: 'text' },
    { name: 'last_verified_at', label: 'Last verified', type: 'date' },
];

export default function CapabilityShow({ capability, linkedApps = [] }) {
    const perms = useEaPermissions();
    const [showForm, setShowForm] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const childCount = (capability.children || []).length;

    return (
        <AuthenticatedLayout header={capability.name}>
            <Head title={capability.name} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Capability Map', href: route('ea.capabilities') },
                    { label: capability.code },
                ]}
                title={`${capability.code} — ${capability.name}`}
                subtitle={`Level ${capability.level} · Owner: ${capability.owner_role || '—'} · Source: ${capability.source.toUpperCase()}`}
                actions={
                    <>
                        {perms.canEdit && (
                            <button
                                type="button"
                                onClick={() => setShowForm(true)}
                                className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                            >
                                Edit capability
                            </button>
                        )}
                        {perms.canDelete && (
                            <button
                                type="button"
                                onClick={() => setDeleting(true)}
                                className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#B3261E] hover:bg-[#B3261E]/5"
                            >
                                Delete
                            </button>
                        )}
                    </>
                }
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Criticality" value={capability.criticality} tone={capability.criticality === 'critical' ? 'red' : 'navy'} />
                <KpiCard label="Maturity" value={`${capability.maturity}/5`} tone="gold" />
                <KpiCard label="Sub-capabilities" value={(capability.children || []).length} tone="white" />
                <KpiCard label="Linked apps" value={linkedApps.length} tone="white" />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-2">Description</h3>
                    <p className="text-sm text-[#2D3748]">{capability.description || '—'}</p>
                    {capability.last_verified_at && (
                        <p className="text-xs text-[#718096] mt-3">Last verified: {capability.last_verified_at}</p>
                    )}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-2">Realising applications</h3>
                    <ul className="divide-y divide-gray-100 text-sm">
                        {linkedApps.length === 0 && <li className="py-2 text-xs text-[#718096]">No applications linked.</li>}
                        {linkedApps.map((a) => (
                            <li key={a.id} className="py-2 flex items-center justify-between">
                                <Link href={route('ea.applications.show', a.id)} className="text-[#0A1F44] hover:underline">{a.name}</Link>
                                <StatusBadge status={a.criticality === 'critical' ? 'critical' : a.criticality === 'high' ? 'high' : 'moderate'} label={a.criticality} />
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {/* WS 4.2 (B14) — "a change-impact tab on every entity", not only
                applications. Retiring or re-scoping a capability is exactly the
                change nobody traces today. */}
            <div className="mt-5">
                <ImpactTab
                    entityType="Capability"
                    entityId={capability.id}
                    title={`Change impact — ${capability.name}`}
                />
            </div>

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={capability}
                fields={FIELDS}
                title={`Edit ${capability.code}`}
                storeRoute={() => ''}
                updateRoute={(r) => route('ea.capabilities.update', r.id)}
            />

            <ConfirmDialog
                show={deleting}
                onClose={() => setDeleting(false)}
                title={`Delete ${capability.code}?`}
                body="Applications mapped to this capability lose the link, and it disappears from every heat-map overlay and maturity rollup."
                blockers={[
                    ...(childCount ? [`${childCount} sub-capability(ies) would be orphaned.`] : []),
                    ...(linkedApps.length ? [`${linkedApps.length} application(s) are mapped to it.`] : []),
                ]}
                url={route('ea.capabilities.destroy', capability.id)}
            />
        </AuthenticatedLayout>
    );
}
