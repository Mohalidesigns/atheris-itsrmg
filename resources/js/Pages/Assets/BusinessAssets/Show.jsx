import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';
import { CriticalityBadge, AssetStatusBadge } from '@/Components/Assets/AssetBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon, TrashIcon, LinkIcon, XMarkIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

function Card({ title, count, action, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-[#2D3748]">{title}</h3>
                <div className="flex items-center gap-3">
                    {count !== undefined && <span className="text-xs text-[#718096]">{count}</span>}
                    {action}
                </div>
            </div>
            {children}
        </div>
    );
}

function AssetsModal({ show, onClose, service, allAssets }) {
    const form = useForm({ asset_ids: (service.assets || []).map((a) => a.id) });
    const [filter, setFilter] = useState('');
    const toggle = (id) => form.setData('asset_ids', form.data.asset_ids.includes(id) ? form.data.asset_ids.filter((x) => x !== id) : [...form.data.asset_ids, id]);
    const shown = allAssets.filter((a) => !filter || `${a.asset_id_code} ${a.name}`.toLowerCase().includes(filter.toLowerCase()));

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="p-6">
                <h3 className="text-base font-semibold text-[#2D3748]">Assets supporting {service.name}</h3>
                <p className="mt-1 text-xs text-[#718096]">Each ticked asset inherits this service's criticality ({service.criticality}) if it is rated lower.</p>
                <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Filter by code or name…" className="mt-3 w-full rounded-lg border-gray-300 text-sm" />
                <div className="mt-2 max-h-80 overflow-y-auto divide-y divide-gray-50">
                    {shown.map((a) => (
                        <label key={a.id} className="flex items-center gap-2 px-2 py-1.5 text-sm hover:bg-gray-50">
                            <input type="checkbox" checked={form.data.asset_ids.includes(a.id)} onChange={() => toggle(a.id)} className="rounded border-gray-300 text-[#0A1F44]" />
                            <span className="font-mono-data text-xs text-[#718096]">{a.asset_id_code}</span>
                            <span className="flex-1 text-[#2D3748]">{a.name}</span>
                            <CriticalityBadge criticality={a.criticality} />
                        </label>
                    ))}
                </div>
                <div className="mt-5 flex items-center justify-between">
                    <span className="text-xs text-[#718096]">{form.data.asset_ids.length} selected</span>
                    <div className="flex gap-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-[#718096]">Cancel</button>
                        <button type="button" disabled={form.processing} onClick={() => form.put(route('business-assets.assets', service.id), { preserveScroll: true, onSuccess: onClose })}
                            className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save</button>
                    </div>
                </div>
            </div>
        </Modal>
    );
}

function DependencyList({ items, empty, service, canEdit }) {
    const remove = (edgeId) => router.delete(route('business-assets.dependencies.destroy', [service.id, edgeId]), { preserveScroll: true });
    if (items.length === 0) return <p className="px-6 py-6 text-sm text-[#718096] text-center">{empty}</p>;
    return (
        <ul className="divide-y divide-gray-50">
            {items.map((d) => (
                <li key={d.edge_id} className="px-6 py-2 flex items-center justify-between">
                    <div>
                        <Link href={route('business-assets.show', d.id)} className="text-sm text-[#2D3748] hover:text-[#0A1F44] hover:underline">{d.name}</Link>
                        <span className="ml-2 font-mono-data text-[10px] text-[#718096]">{d.code}</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-[#718096]">
                        {d.rto_minutes != null && <span>RTO {d.rto_minutes}m</span>}
                        <CriticalityBadge criticality={d.criticality} />
                        {canEdit && <button type="button" onClick={() => remove(d.edge_id)} title="Remove dependency" className="text-[#C53030]"><XMarkIcon className="h-4 w-4" /></button>}
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default function ShowBusinessAsset({ service, owner, upstream = [], downstream = [], rtoConflicts = [], allServices = [], allAssets = [], can = {} }) {
    const [linking, setLinking] = useState(false);
    const dep = useForm({ provider_id: '' });

    const destroy = () => {
        if (confirm(`Delete business service "${service.name}"? Its dependency edges and asset links are removed too.`)) {
            router.delete(route('business-assets.destroy', service.id));
        }
    };
    const addDependency = (e) => {
        e.preventDefault();
        dep.post(route('business-assets.dependencies.store', service.id), { preserveScroll: true, onSuccess: () => dep.reset() });
    };
    const linkedIds = new Set(upstream.map((u) => u.id));

    return (
        <AuthenticatedLayout header={service.name}>
            <Head title={service.name} />

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Criticality</p>
                    <div className="mt-1"><CriticalityBadge criticality={service.criticality} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Capability</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{service.capability?.name || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Owner</p>
                    <p className={`mt-1 text-sm font-medium ${owner ? 'text-[#2D3748]' : 'text-[#B3261E]'}`}>{owner?.name || 'Unowned'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RTO</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">{service.recovery_time_objective_min != null ? `${service.recovery_time_objective_min}m` : '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RPO</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">{service.recovery_point_objective_min != null ? `${service.recovery_point_objective_min}m` : '--'}</p>
                </div>
            </div>

            {rtoConflicts.length > 0 && (
                <div className="mb-4 flex items-start gap-2 rounded-xl border border-[#E5A100]/40 bg-[#FFF8E6] p-3 text-sm text-[#8A6400]">
                    <ExclamationTriangleIcon className="h-5 w-5 flex-shrink-0" />
                    <span>RTO conflict: {rtoConflicts.join(', ')} recover{rtoConflicts.length === 1 ? 's' : ''} more slowly than this service's {service.recovery_time_objective_min}-minute RTO, so the RTO cannot be met if {rtoConflicts.length === 1 ? 'it fails' : 'they fail'}.</span>
                </div>
            )}

            <div className="flex gap-2 mb-4">
                {can.edit && (
                    <Link href={route('business-assets.edit', service.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <PencilIcon className="w-4 h-4" /> Edit
                    </Link>
                )}
                {can.delete && (
                    <button onClick={destroy} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                        <TrashIcon className="w-4 h-4" /> Delete
                    </button>
                )}
                <Link href={route('business-services.graph')} className="ml-auto text-xs text-[#0A1F44] underline self-center">View in dependency graph →</Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card title="Service Information">
                    <div className="px-6 py-2">
                        <DetailRow label="Code" value={service.code} />
                        <DetailRow label="Description" value={service.description} />
                        <DetailRow label="Capability" value={service.capability ? `${service.capability.code} — ${service.capability.name}` : null} />
                        <DetailRow label="Owner" value={owner ? `${owner.name}${owner.job_title ? ` (${owner.job_title})` : ''}` : null} />
                        <DetailRow label="Last Updated" value={new Date(service.updated_at).toLocaleDateString('en-GB')} />
                    </div>
                </Card>

                <Card title="Supporting Assets" count={`${(service.assets || []).length}`}
                    action={can.edit && <button type="button" onClick={() => setLinking(true)} className="inline-flex items-center gap-1 text-xs text-[#0A1F44] underline"><LinkIcon className="h-3 w-3" /> Manage</button>}>
                    {(service.assets || []).length === 0 ? (
                        <p className="px-6 py-8 text-sm text-[#B3261E] text-center">No physical asset is mapped to this service, so a BIA cannot say what it runs on.</p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {service.assets.map((a) => (
                                <li key={a.id} className="px-6 py-2 flex items-center justify-between">
                                    <div>
                                        <Link href={route('assets.show', a.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">{a.asset_id_code}</Link>
                                        <p className="text-sm text-[#2D3748]">{a.name}</p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs">
                                        {a.open_vulns_count > 0 && <span className="text-[#C53030]">{a.open_vulns_count} open vulns</span>}
                                        <AssetStatusBadge status={a.status} />
                                        <CriticalityBadge criticality={a.effective_criticality} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Depends on (upstream)" count={`${upstream.length}`}>
                    <DependencyList items={upstream} service={service} canEdit={can.edit} empty="This service depends on no other recorded service." />
                    {can.edit && (
                        <form onSubmit={addDependency} className="flex gap-2 border-t border-gray-100 px-6 py-3">
                            <select value={dep.data.provider_id} onChange={(e) => dep.setData('provider_id', e.target.value)} className="flex-1 rounded-lg border-gray-300 text-sm">
                                <option value="">Add a service this one depends on…</option>
                                {allServices.filter((s) => !linkedIds.has(s.id)).map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                            </select>
                            <button type="submit" disabled={!dep.data.provider_id || dep.processing} className="rounded-lg bg-[#0A1F44] px-3 text-sm font-semibold text-white disabled:opacity-50">Add</button>
                        </form>
                    )}
                    {dep.errors.provider_id && <p className="px-6 pb-2 text-xs text-[#C53030]">{dep.errors.provider_id}</p>}
                </Card>

                <Card title="Serves (downstream)" count={`${downstream.length}`}>
                    <DependencyList items={downstream} service={service} canEdit={can.edit} empty="No other service depends on this one." />
                </Card>

                <Card title="Business Processes" count={`${(service.processes || []).length}`}>
                    {(service.processes || []).length === 0 ? (
                        <p className="px-6 py-8 text-sm text-[#718096] text-center">No processes mapped to this service.</p>
                    ) : (
                        <ul className="divide-y divide-gray-50">
                            {service.processes.map(p => (
                                <li key={p.id} className="px-6 py-3 flex items-center justify-between">
                                    <span className="text-sm text-[#2D3748]">{p.name} <span className="font-mono-data text-[10px] text-[#718096]">{p.code}</span></span>
                                    <StatusBadge status={p.criticality} label={cap(p.criticality)} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {can.edit && <AssetsModal show={linking} onClose={() => setLinking(false)} service={service} allAssets={allAssets} />}
        </AuthenticatedLayout>
    );
}
