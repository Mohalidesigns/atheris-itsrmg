import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EaAssetPanel from '@/Components/Ea/EaAssetPanel';
import Modal from '@/Components/Modal';
import { CriticalityBadge, AssetStatusBadge, typeLabels, sourceLabels } from '@/Components/Assets/AssetBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon, ArchiveBoxIcon, LinkIcon } from '@/Components/Material/Symbols';

const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null);
const fmtDateTime = (v) => (v ? new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : null);

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

function Panel({ title, count, action, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm mt-6">
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

function Empty({ children }) {
    return <p className="px-6 py-8 text-sm text-[#718096] text-center">{children}</p>;
}

function ReviewPanel({ asset, users, criticalities }) {
    const form = useForm({ decision: 'accept', owner_id: '', criticality: asset.criticality, reason: '' });
    const submit = (decision) => {
        form.transform((d) => ({ ...d, decision }));
        form.post(route('assets.review', asset.id), { preserveScroll: true });
    };

    return (
        <div className="mb-6 rounded-xl border border-[#E5A100]/40 bg-[#FFF8E6] p-4">
            <h3 className="text-sm font-semibold text-[#8A6400]">Found by {sourceLabels[asset.source] || asset.source} — awaiting review</h3>
            <p className="mt-1 text-xs text-[#8A6400]">Accept it into the governed register with an accountable owner, or reject it if it is not the bank's (it is retired, not deleted).</p>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className="rounded-lg border-gray-300 text-sm">
                    <option value="">Owner…</option>
                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
                <select value={form.data.criticality} onChange={(e) => form.setData('criticality', e.target.value)} className="rounded-lg border-gray-300 text-sm">
                    {criticalities.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Reason (needed to reject)" className="rounded-lg border-gray-300 text-sm" />
                <div className="flex gap-2">
                    <button type="button" disabled={form.processing} onClick={() => submit('accept')} className="flex-1 rounded-lg bg-[#2D7D46] px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Accept</button>
                    <button type="button" disabled={form.processing} onClick={() => submit('reject')} className="flex-1 rounded-lg border border-[#C53030]/40 px-3 py-2 text-sm text-[#C53030] disabled:opacity-50">Reject</button>
                </div>
            </div>
            {Object.values(form.errors).length > 0 && <p className="mt-2 text-xs text-[#C53030]">{Object.values(form.errors).join(' ')}</p>}
        </div>
    );
}

function ServicesModal({ show, onClose, asset, services }) {
    const form = useForm({ business_service_ids: (asset.business_services || []).map((s) => s.id) });
    const toggle = (id) => form.setData('business_service_ids', form.data.business_service_ids.includes(id)
        ? form.data.business_service_ids.filter((x) => x !== id) : [...form.data.business_service_ids, id]);
    const save = () => form.put(route('assets.services', asset.id), { preserveScroll: true, onSuccess: onClose });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="p-6">
                <h3 className="text-base font-semibold text-[#2D3748]">Business services supported by {asset.asset_id_code}</h3>
                <p className="mt-1 text-xs text-[#718096]">The asset inherits the criticality of the most critical service ticked here.</p>
                <div className="mt-4 grid max-h-80 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                    {services.length === 0 && <p className="text-sm text-[#718096]">This bank has no business services yet. Add them under Business Assets.</p>}
                    {services.map((s) => (
                        <label key={s.id} className="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50">
                            <input type="checkbox" checked={form.data.business_service_ids.includes(s.id)} onChange={() => toggle(s.id)} className="rounded border-gray-300 text-[#0A1F44]" />
                            <span className="font-mono-data text-xs text-[#718096]">{s.code}</span>
                            <span className="flex-1 text-[#2D3748]">{s.name}</span>
                            <CriticalityBadge criticality={s.criticality} />
                        </label>
                    ))}
                </div>
                {form.errors.business_service_ids && <p className="mt-2 text-xs text-[#C53030]">{form.errors.business_service_ids}</p>}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-[#718096]">Cancel</button>
                    <button type="button" onClick={save} disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save links</button>
                </div>
            </div>
        </Modal>
    );
}

function describeChange(entry) {
    const changed = Object.keys(entry.attribute_changes?.attributes || {}).filter((k) => !['updated_at', 'created_at'].includes(k));
    if (entry.event === 'created') return 'Registered';
    if (entry.event === 'deleted') return 'Archived';
    return changed.length ? `Changed ${changed.map((k) => k.replace(/_/g, ' ')).join(', ')}` : entry.description;
}

export default function ShowAsset({ asset, changes = [], trail = [], services = [], users = [], criticalities = ['critical', 'high', 'medium', 'low'], can = {}, ea }) {
    const [linking, setLinking] = useState(false);
    const inherited = asset.effective_criticality !== asset.criticality;
    const eolPast = asset.end_of_life && new Date(asset.end_of_life) < new Date();

    const archive = () => {
        if (confirm(`Archive ${asset.asset_id_code}? It leaves the register but its history and links are kept.`)) {
            router.delete(route('assets.destroy', asset.id));
        }
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{asset.asset_id_code}</span>
                <span>{asset.name}</span>
            </div>
        }>
            <Head title={`${asset.asset_id_code} - ${asset.name}`} />

            {asset.status === 'under_review' && can.edit && <ReviewPanel asset={asset} users={users} criticalities={criticalities} />}

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{typeLabels[asset.asset_type] || asset.asset_type}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Criticality</p>
                    <div className="mt-1"><CriticalityBadge criticality={asset.effective_criticality} /></div>
                    {inherited && <p className="mt-1 text-[11px] text-[#8A6400]">Owner rates it {asset.criticality}; inherits {asset.criticality_inherited} from a supported service.</p>}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><AssetStatusBadge status={asset.status} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Owner</p>
                    <p className={`mt-1 text-sm font-medium ${asset.owner ? 'text-[#2D3748]' : 'text-[#B3261E]'}`}>{asset.owner?.name || 'Unassigned'}</p>
                    {asset.owner?.job_title && <p className="text-[11px] text-[#718096]">{asset.owner.job_title}</p>}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">End of life</p>
                    <p className={`mt-1 text-sm font-medium ${eolPast ? 'text-[#B3261E]' : 'text-[#2D3748]'}`}>{fmtDate(asset.end_of_life) || '--'}</p>
                    {eolPast && <p className="text-[11px] text-[#B3261E]">Out of support</p>}
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                {can.edit && (
                    <Link href={route('assets.edit', asset.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <PencilIcon className="w-4 h-4" /> Edit
                    </Link>
                )}
                {can.delete && (
                    <button type="button" onClick={archive} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                        <ArchiveBoxIcon className="w-4 h-4" /> Archive
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100"><h3 className="text-sm font-semibold text-[#2D3748]">Asset Information</h3></div>
                    <div className="px-6 py-2">
                        <DetailRow label="Description" value={asset.description} />
                        <DetailRow label="Category" value={asset.category} />
                        <DetailRow label="Department" value={asset.department} />
                        <DetailRow label="Custodian" value={asset.custodian?.name} />
                        <DetailRow label="Location" value={asset.location} />
                        <DetailRow label="Data Classification" value={asset.data_classification ? asset.data_classification.replace(/^./, (c) => c.toUpperCase()) : <span className="text-[#B3261E]">Not classified</span>} />
                        <DetailRow label="Purchase Date" value={fmtDate(asset.purchase_date)} />
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100"><h3 className="text-sm font-semibold text-[#2D3748]">Technical Details &amp; Provenance</h3></div>
                    <div className="px-6 py-2">
                        <DetailRow label="Hostname" value={asset.hostname} />
                        <DetailRow label="IP Address" value={asset.ip_address} />
                        <DetailRow label="Vendor / Version" value={[asset.vendor, asset.version].filter(Boolean).join(' · ')} />
                        <DetailRow label="License Type" value={asset.license_type} />
                        <DetailRow label="Source" value={`${sourceLabels[asset.source] || asset.source || 'Manual entry'}${asset.external_id ? ` · ${asset.external_id}` : ''}`} />
                        <DetailRow label="Last seen by connector" value={fmtDateTime(asset.last_seen_at)} />
                        <DetailRow label="Reviewed" value={asset.reviewed_at ? `${fmtDateTime(asset.reviewed_at)}${asset.reviewer ? ` by ${asset.reviewer.name}` : ''}` : null} />
                    </div>
                </div>
            </div>

            <Panel title="Business Services Supported" count={`${(asset.business_services || []).length} linked`}
                action={can.edit && (
                    <button type="button" onClick={() => setLinking(true)} className="inline-flex items-center gap-1 text-xs text-[#0A1F44] underline">
                        <LinkIcon className="h-3 w-3" /> Manage
                    </button>
                )}>
                {(asset.business_services || []).length === 0 ? (
                    <Empty>Not mapped to any business service, so nothing tells the bank what stops if this asset fails.</Empty>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {asset.business_services.map((s) => (
                            <li key={s.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                <div>
                                    <Link href={route('business-assets.show', s.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">{s.code}</Link>
                                    <p className="text-sm text-[#2D3748]">{s.name}</p>
                                </div>
                                <div className="flex items-center gap-3 text-xs text-[#718096]">
                                    {s.rto_minutes != null && <span>RTO {s.rto_minutes} min</span>}
                                    <CriticalityBadge criticality={s.criticality} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Panel title="Linked Risks" count={`${(asset.risks || []).length} linked`}>
                    {(asset.risks || []).length === 0 ? <Empty>No risks linked. Link assets from a risk's edit form.</Empty> : (
                        <ul className="divide-y divide-gray-50">
                            {asset.risks.map((risk) => (
                                <li key={risk.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                    <div>
                                        <Link href={route('risks.show', risk.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">{risk.risk_id_code}</Link>
                                        <p className="text-sm text-[#2D3748]">{risk.title}</p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs">
                                        {risk.inherent_rating && <span className="px-2 py-0.5 rounded-lg bg-gray-100 text-[#2D3748] capitalize">{String(risk.inherent_rating).replace('_', ' ')}</span>}
                                        <span className="px-2 py-0.5 rounded-lg bg-[#1A365D]/5 text-[#1A365D] capitalize">{risk.status}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Linked Vulnerabilities" count={`${(asset.vulnerabilities || []).length} linked`}>
                    {(asset.vulnerabilities || []).length === 0 ? <Empty>No vulnerabilities linked. Link assets from a vulnerability's edit form.</Empty> : (
                        <ul className="divide-y divide-gray-50">
                            {asset.vulnerabilities.map((vuln) => (
                                <li key={vuln.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                    <div>
                                        <Link href={route('vulnerabilities.show', vuln.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">{vuln.vuln_id_code}</Link>
                                        {vuln.cve_id && <span className="ml-2 font-mono-data text-[11px] text-[#718096]">{vuln.cve_id}</span>}
                                        <p className="text-sm text-[#2D3748]">{vuln.title}</p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs">
                                        <span className="px-2 py-0.5 rounded-lg bg-gray-100 text-[#2D3748] capitalize">{vuln.severity}</span>
                                        <span className="px-2 py-0.5 rounded-lg bg-[#1A365D]/5 text-[#1A365D] capitalize">{String(vuln.status || '').replace('_', ' ')}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <Panel title="Change Requests Affecting This Asset" count={`${changes.length}`}>
                {changes.length === 0 ? <Empty>No change request names this asset.</Empty> : (
                    <ul className="divide-y divide-gray-50">
                        {changes.map((c) => (
                            <li key={c.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                <div>
                                    <Link href={route('changes.show', c.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">{c.change_code}</Link>
                                    <p className="text-sm text-[#2D3748]">{c.title}</p>
                                </div>
                                <div className="flex items-center gap-2 text-xs text-[#718096]">
                                    {c.scheduled_start_at && <span>{fmtDate(c.scheduled_start_at)}</span>}
                                    <span className="px-2 py-0.5 rounded-lg bg-gray-100 capitalize">{c.risk_level} risk</span>
                                    <span className="px-2 py-0.5 rounded-lg bg-[#1A365D]/5 text-[#1A365D] capitalize">{String(c.status).replace(/_/g, ' ')}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {/* ATH-EAR-002 §7.4 (I-3) — the logical architecture view of this
                physical asset: capability, criticality, TIME, zone, and what
                is running on it that has gone out of support. */}
            <div className="mt-6">
                <EaAssetPanel ea={ea} />
            </div>

            <Panel title="Audit Trail" count={`${trail.length} recent`}>
                {trail.length === 0 ? <Empty>No recorded changes.</Empty> : (
                    <ul className="divide-y divide-gray-50 text-sm">
                        {trail.map((t) => (
                            <li key={t.id} className="px-6 py-2 flex items-center justify-between">
                                <span className="text-[#2D3748]">{describeChange(t)}</span>
                                <span className="text-xs text-[#718096]">{t.causer?.name || 'System'} · {fmtDateTime(t.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {can.edit && <ServicesModal show={linking} onClose={() => setLinking(false)} asset={asset} services={services} />}
        </AuthenticatedLayout>
    );
}
