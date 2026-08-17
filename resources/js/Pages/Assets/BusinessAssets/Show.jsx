import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowBusinessAsset({ service, owner }) {
    const destroy = () => {
        if (confirm(`Delete business service "${service.name}"? This cannot be undone.`)) {
            router.delete(route('business-assets.destroy', service.id));
        }
    };

    return (
        <AuthenticatedLayout header={service.name}>
            <Head title={service.name} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Criticality</p>
                    <div className="mt-1"><StatusBadge status={service.criticality} label={cap(service.criticality)} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Capability</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{service.capability?.name || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RTO</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">
                        {service.recovery_time_objective_min != null ? `${service.recovery_time_objective_min}m` : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RPO</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">
                        {service.recovery_point_objective_min != null ? `${service.recovery_point_objective_min}m` : '--'}
                    </p>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('business-assets.edit', service.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Service Information</h3>
                    </div>
                    <div className="px-6 py-2">
                        <DetailRow label="Description" value={service.description} />
                        <DetailRow label="Capability" value={service.capability?.name} />
                        <DetailRow label="Owner" value={owner?.name} />
                        <DetailRow label="Created" value={new Date(service.created_at).toLocaleDateString()} />
                        <DetailRow label="Last Updated" value={new Date(service.updated_at).toLocaleDateString()} />
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Business Processes</h3>
                        <span className="text-xs text-[#718096]">{(service.processes || []).length}</span>
                    </div>
                    {(service.processes || []).length === 0 ? (
                        <p className="px-6 py-8 text-sm text-[#718096] text-center">No processes mapped to this service.</p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {service.processes.map(p => (
                                <li key={p.id} className="px-6 py-3 flex items-center justify-between">
                                    <span className="text-sm text-[#2D3748]">{p.name}</span>
                                    <StatusBadge status={p.criticality} label={cap(p.criticality)} />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
