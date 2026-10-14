import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import RiskMatrix from '@/Components/Changes/RiskMatrix';
import { formatDate, humanize } from '@/Utils/risk';
import { CATEGORIES, CHANGE_TYPES, IMPACT_LABELS, LIKELIHOOD_LABELS, PRIORITIES, riskLevelFor } from '@/Utils/changes';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const SOURCE_WITH_RECORDS = ['risk', 'incident', 'vulnerability', 'issue'];

export default function ChangeCreate({ templates = [], assets = [], owners = [], sourceRecords = null, freezeWindows = [], defaults = {}, routingPreview = {}, categories = CATEGORIES, sources = {} }) {
    const form = useForm({
        change_type: defaults.change_type || 'normal',
        standard_template_id: '',
        title: '', description: '', justification: '',
        category: 'application', priority: defaults.priority || 'medium',
        impact: defaults.impact || 3, likelihood: defaults.likelihood || 3,
        impact_assessment: '', implementation_plan: '', backout_plan: '', test_plan: '', communication_plan: '',
        outage_required: false, expected_downtime_minutes: '',
        affected_asset_ids: [], affected_services: [],
        source_type: '', source_id: '', source_reference: '',
        change_owner_id: '', implementer_id: '', change_manager_id: '',
        scheduled_start_at: '', scheduled_end_at: '', freeze_override_reason: '',
        submit: false,
    });
    const d = form.data;
    const set = (k, v) => form.setData(k, v);
    const [assetQuery, setAssetQuery] = useState('');
    const [serviceInput, setServiceInput] = useState('');

    // Lazy-load pickable source records the first time a linked source type is chosen.
    useEffect(() => {
        if (SOURCE_WITH_RECORDS.includes(d.source_type) && !sourceRecords) router.reload({ only: ['sourceRecords'] });
    }, [d.source_type, sourceRecords]);

    const template = templates.find((t) => String(t.id) === String(d.standard_template_id));
    const applyTemplate = (id) => {
        const t = templates.find((x) => String(x.id) === String(id));
        form.setData((prev) => ({
            ...prev,
            standard_template_id: id,
            ...(t ? {
                title: prev.title || t.name, category: t.category, description: prev.description || t.description || '',
                implementation_plan: t.implementation_plan || '', backout_plan: t.backout_plan || '',
                expected_downtime_minutes: t.expected_downtime_minutes ?? '', outage_required: Number(t.expected_downtime_minutes) > 0,
                impact: t.risk_level === 'medium' ? 2 : 1, likelihood: t.risk_level === 'medium' ? 3 : 2,
            } : {}),
        }));
    };

    const selectedAssets = assets.filter((a) => d.affected_asset_ids.includes(a.id));
    const criticalAsset = selectedAssets.find((a) => a.criticality === 'critical');
    const risk = useMemo(() => riskLevelFor(d.impact, d.likelihood, { outage: d.outage_required, category: d.category, criticalAsset: criticalAsset?.name, type: d.change_type }),
        [d.impact, d.likelihood, d.outage_required, d.category, criticalAsset?.name, d.change_type]);
    const routing = routingPreview?.[d.change_type]?.[risk.level] || [];

    const filteredAssets = assets.filter((a) => !assetQuery || `${a.name} ${a.asset_id_code || ''} ${a.asset_type || ''}`.toLowerCase().includes(assetQuery.toLowerCase())).slice(0, 60);
    const toggleAsset = (id) => set('affected_asset_ids', d.affected_asset_ids.includes(id) ? d.affected_asset_ids.filter((x) => x !== id) : [...d.affected_asset_ids, id]);
    const addService = () => {
        const v = serviceInput.trim();
        if (v && !d.affected_services.includes(v)) set('affected_services', [...d.affected_services, v]);
        setServiceInput('');
    };

    const windowFreeze = useMemo(() => {
        if (!d.scheduled_start_at) return null;
        const s = new Date(d.scheduled_start_at).getTime();
        const e = d.scheduled_end_at ? new Date(d.scheduled_end_at).getTime() : s;
        return freezeWindows.find((w) => new Date(w.starts_at).getTime() <= e && new Date(w.ends_at).getTime() >= s && (w.scope === 'all' || (w.categories || []).includes(d.category))) || null;
    }, [d.scheduled_start_at, d.scheduled_end_at, d.category, freezeWindows]);

    const pickable = SOURCE_WITH_RECORDS.includes(d.source_type) ? sourceRecords?.[d.source_type] : undefined;

    const save = (submit) => {
        form.transform((data) => ({ ...data, submit, source_id: data.source_id || null, standard_template_id: data.standard_template_id || null, expected_downtime_minutes: data.expected_downtime_minutes === '' ? null : data.expected_downtime_minutes }));
        form.post(route('changes.store'), { preserveScroll: true });
    };

    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';

    return (
        <AuthenticatedLayout header="Raise Change">
            <Head title="Raise Change" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'Raise change' }]}
                title="Raise a Change"
                subtitle="Pick the change type, describe the change and its plans, and the risk score decides which approval stages it is routed through."
            />

            <form onSubmit={(e) => { e.preventDefault(); save(false); }} className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {/* Type selector */}
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">Change type</h3>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {CHANGE_TYPES.map((t) => (
                                <button key={t.key} type="button" onClick={() => set('change_type', t.key)}
                                    className={`rounded-xl border p-3 text-left transition ${d.change_type === t.key ? 'border-[#0A1F44] bg-[#0A1F44]/5 ring-1 ring-[#0A1F44]' : 'border-gray-200 hover:border-[#C9A86A]'}`}>
                                    <div className="flex items-center justify-between"><span className="text-sm font-semibold text-[#0A1F44]">{t.label}</span><StatusBadge status={t.key} label={t.label} /></div>
                                    <p className="mt-1 text-[11px] text-[#718096]">{t.meaning}</p>
                                </button>
                            ))}
                        </div>
                        <InputError message={form.errors.change_type} className="mt-1" />
                        {d.change_type === 'standard' && (
                            <div className="mt-3">
                                <label className={label}>Standard change template</label>
                                <select value={d.standard_template_id} onChange={(e) => applyTemplate(e.target.value)} className={field}>
                                    <option value="">— pick an approved template —</option>
                                    {templates.map((t) => <option key={t.id} value={t.id}>{t.name} · {humanize(t.category)} · {humanize(t.risk_level)} risk · used {t.usage_count}×</option>)}
                                </select>
                                <InputError message={form.errors.standard_template_id} className="mt-1" />
                                {templates.length === 0 && <p className="mt-1 text-xs text-[#8A6400]">No approved template is available. <Link href={route('changes.templates')} className="underline">Manage standard changes →</Link></p>}
                                {template && <p className="mt-1 text-[11px] text-[#718096]">{template.description}</p>}
                            </div>
                        )}
                        {d.change_type === 'emergency' && (
                            <p className="mt-3 rounded-lg bg-[#B3261E]/5 p-3 text-xs text-[#B3261E]">An emergency change must come from an incident, vulnerability or regulatory requirement, or carry a justification of at least 20 characters. ECAB and the Change Manager authorise it; a post-implementation review is mandatory.</p>
                        )}
                    </div>

                    {/* Details */}
                    <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Details</h3>
                        <div>
                            <label className={label}>Title</label>
                            <input value={d.title} onChange={(e) => set('title', e.target.value)} className={field} />
                            <InputError message={form.errors.title} className="mt-1" />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className={label}>Category</label>
                                <select value={d.category} onChange={(e) => set('category', e.target.value)} className={field}>
                                    {categories.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
                                </select>
                                <InputError message={form.errors.category} className="mt-1" />
                            </div>
                            <div>
                                <label className={label}>Priority</label>
                                <select value={d.priority} onChange={(e) => set('priority', e.target.value)} className={field}>
                                    {PRIORITIES.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
                                </select>
                                <InputError message={form.errors.priority} className="mt-1" />
                            </div>
                        </div>
                        <div>
                            <label className={label}>Description</label>
                            <textarea value={d.description} onChange={(e) => set('description', e.target.value)} rows={3} className={field} />
                            <InputError message={form.errors.description} className="mt-1" />
                        </div>
                        <div>
                            <label className={label}>Justification {d.change_type === 'emergency' && <span className="text-[#B3261E]">(required for an emergency without a linked source)</span>}</label>
                            <textarea value={d.justification} onChange={(e) => set('justification', e.target.value)} rows={2} className={field} />
                            <InputError message={form.errors.justification} className="mt-1" />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label className={label}>Source</label>
                                <select value={d.source_type} onChange={(e) => form.setData((p) => ({ ...p, source_type: e.target.value, source_id: '' }))} className={field}>
                                    <option value="">— none —</option>
                                    {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </select>
                                <InputError message={form.errors.source_type} className="mt-1" />
                            </div>
                            <div className="sm:col-span-2">
                                {pickable !== undefined ? (
                                    <>
                                        <label className={label}>Source record</label>
                                        {sourceRecords ? (
                                            <select value={d.source_id} onChange={(e) => set('source_id', e.target.value)} className={field}>
                                                <option value="">— none: cite a reference instead —</option>
                                                {(pickable || []).map((r) => <option key={r.id} value={r.id}>{r.code} · {r.title}</option>)}
                                            </select>
                                        ) : <p className="mt-2 text-xs text-[#718096]">Loading source records…</p>}
                                        <InputError message={form.errors.source_id} className="mt-1" />
                                    </>
                                ) : (
                                    <>
                                        <label className={label}>Source reference</label>
                                        <input value={d.source_reference} onChange={(e) => set('source_reference', e.target.value)} placeholder="Report, letter, project or finding reference" className={field} />
                                        <InputError message={form.errors.source_reference} className="mt-1" />
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Risk & impact */}
                    <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Risk assessment</h3>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className={label}>Impact if it goes wrong</label>
                                <select value={d.impact} onChange={(e) => set('impact', Number(e.target.value))} className={field}>
                                    {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} · {IMPACT_LABELS[n]}</option>)}
                                </select>
                                <InputError message={form.errors.impact} className="mt-1" />
                            </div>
                            <div>
                                <label className={label}>Likelihood of a problem</label>
                                <select value={d.likelihood} onChange={(e) => set('likelihood', Number(e.target.value))} className={field}>
                                    {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} · {LIKELIHOOD_LABELS[n]}</option>)}
                                </select>
                                <InputError message={form.errors.likelihood} className="mt-1" />
                            </div>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                                <input type="checkbox" checked={d.outage_required} onChange={(e) => set('outage_required', e.target.checked)} className="rounded border-gray-300" /> Outage required
                            </label>
                            <div>
                                <label className={label}>Expected downtime (minutes)</label>
                                <input type="number" min="0" value={d.expected_downtime_minutes} onChange={(e) => set('expected_downtime_minutes', e.target.value)} className={field} />
                                <InputError message={form.errors.expected_downtime_minutes} className="mt-1" />
                            </div>
                        </div>
                        <div>
                            <label className={label}>Impact assessment</label>
                            <textarea value={d.impact_assessment} onChange={(e) => set('impact_assessment', e.target.value)} rows={3} className={field} placeholder="Services, customers, regulatory reporting and controls affected; what happens if it fails." />
                            <InputError message={form.errors.impact_assessment} className="mt-1" />
                        </div>
                        <div>
                            <label className={label}>Affected assets {selectedAssets.length > 0 && <span className="text-[#718096]">({selectedAssets.length} selected)</span>}</label>
                            <input value={assetQuery} onChange={(e) => setAssetQuery(e.target.value)} placeholder="Search assets…" className={field} />
                            <div className="mt-2 max-h-44 overflow-y-auto rounded-lg border border-gray-100">
                                {filteredAssets.length === 0 && <p className="p-3 text-xs text-[#718096]">No asset matches.</p>}
                                {filteredAssets.map((a) => (
                                    <label key={a.id} className="flex cursor-pointer items-center gap-2 border-b border-gray-50 px-3 py-1.5 text-xs hover:bg-gray-50">
                                        <input type="checkbox" checked={d.affected_asset_ids.includes(a.id)} onChange={() => toggleAsset(a.id)} className="rounded border-gray-300" />
                                        <span className="flex-1 text-[#2D3748]">{a.name} <span className="font-mono text-[10px] text-[#718096]">{a.asset_id_code}</span></span>
                                        <span className="text-[10px] text-[#718096]">{humanize(a.asset_type)}</span>
                                        {a.criticality && <StatusBadge status={a.criticality} label={humanize(a.criticality)} />}
                                    </label>
                                ))}
                            </div>
                            {selectedAssets.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {selectedAssets.map((a) => <button key={a.id} type="button" onClick={() => toggleAsset(a.id)} className="rounded-lg bg-[#0A1F44]/10 px-2 py-0.5 text-[11px] text-[#0A1F44] whitespace-nowrap">{a.name} ×</button>)}
                                </div>
                            )}
                            <InputError message={form.errors.affected_asset_ids} className="mt-1" />
                        </div>
                        <div>
                            <label className={label}>Affected services</label>
                            <div className="mt-1 flex gap-2">
                                <input value={serviceInput} onChange={(e) => setServiceInput(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addService(); } }} placeholder="e.g. Internet banking, NIP transfers" className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                                <button type="button" onClick={addService} className="rounded-lg border border-gray-200 px-3 py-2 text-xs">Add</button>
                            </div>
                            {d.affected_services.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {d.affected_services.map((s) => <button key={s} type="button" onClick={() => set('affected_services', d.affected_services.filter((x) => x !== s))} className="rounded-lg bg-gray-100 px-2 py-0.5 text-[11px] text-[#2D3748] whitespace-nowrap">{s} ×</button>)}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Plans */}
                    <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Plans</h3>
                        {[['implementation_plan', 'Implementation plan', 'Steps, sequence, who does what.'], ['backout_plan', 'Back-out plan', 'How to restore service if the change fails.'], ['test_plan', 'Test plan', 'Required before scheduling a high or critical change.'], ['communication_plan', 'Communication plan', 'Who is told, when, and through which channel.']].map(([k, l, ph]) => (
                            <div key={k}>
                                <label className={label}>{l}</label>
                                <textarea value={d[k]} onChange={(e) => set(k, e.target.value)} rows={3} className={field} placeholder={ph} />
                                <InputError message={form.errors[k]} className="mt-1" />
                            </div>
                        ))}
                    </div>

                    {/* People & schedule */}
                    <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">People and proposed window</h3>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {[['change_owner_id', 'Change owner (business)'], ['implementer_id', 'Implementer'], ['change_manager_id', 'Change manager']].map(([k, l]) => (
                                <div key={k}>
                                    <label className={label}>{l}</label>
                                    <select value={d[k]} onChange={(e) => set(k, e.target.value)} className={field}>
                                        <option value="">—</option>
                                        {owners.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}
                                    </select>
                                    <InputError message={form.errors[k]} className="mt-1" />
                                </div>
                            ))}
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className={label}>Window start</label>
                                <input type="datetime-local" value={d.scheduled_start_at} onChange={(e) => set('scheduled_start_at', e.target.value)} className={field} />
                                <InputError message={form.errors.scheduled_start_at} className="mt-1" />
                            </div>
                            <div>
                                <label className={label}>Window end</label>
                                <input type="datetime-local" value={d.scheduled_end_at} onChange={(e) => set('scheduled_end_at', e.target.value)} className={field} />
                                <InputError message={form.errors.scheduled_end_at} className="mt-1" />
                            </div>
                        </div>
                        {windowFreeze && (
                            <div className="rounded-lg bg-[#B3261E]/5 p-3 text-xs text-[#B3261E]">
                                <p className="font-semibold">Inside freeze window "{windowFreeze.name}" ({formatDate(windowFreeze.starts_at)} – {formatDate(windowFreeze.ends_at)}).</p>
                                <p className="mt-1">{d.change_type === 'emergency' && windowFreeze.allow_emergency ? 'Emergency changes may proceed through this freeze.' : 'Submission will be refused unless you give an override reason; the Change Manager stage is then always required.'}</p>
                                {!(d.change_type === 'emergency' && windowFreeze.allow_emergency) && (
                                    <textarea value={d.freeze_override_reason} onChange={(e) => set('freeze_override_reason', e.target.value)} rows={2} placeholder="Freeze override reason" className="mt-2 w-full rounded-lg border border-[#B3261E]/30 px-3 py-2 text-xs text-[#2D3748]" />
                                )}
                                <InputError message={form.errors.freeze_override_reason} className="mt-1" />
                            </div>
                        )}
                        {freezeWindows.length > 0 && !windowFreeze && (
                            <p className="text-[11px] text-[#718096]">Upcoming freezes: {freezeWindows.slice(0, 3).map((w) => `${w.name} (${formatDate(w.starts_at)} – ${formatDate(w.ends_at)})`).join(' · ')}</p>
                        )}
                    </div>
                    <InputError message={form.errors.action || form.errors.note} />

                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Link href={route('changes.index')} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</Link>
                        <button type="submit" disabled={form.processing} className="rounded-lg border border-[#0A1F44]/20 px-4 py-2 text-sm text-[#0A1F44] disabled:opacity-50">Save draft</button>
                        <button type="button" disabled={form.processing} onClick={() => save(true)} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Save &amp; submit</button>
                    </div>
                </div>

                {/* Side panel: live risk + routing preview */}
                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Risk preview</h3>
                        <RiskMatrix impact={risk.impact} likelihood={risk.likelihood} />
                        <div className="mt-3 flex items-center justify-between">
                            <span className="text-xs text-[#718096]">Score {risk.score} / 25</span>
                            <StatusBadge status={risk.level} label={humanize(risk.level)} />
                        </div>
                        {risk.factors.length > 0 && (
                            <ul className="mt-2 list-disc space-y-0.5 pl-4 text-[11px] text-[#8A6400]">
                                {risk.factors.map((f) => <li key={f}>{f}</li>)}
                            </ul>
                        )}
                        <p className="mt-2 text-[10px] text-[#A0AEC0]">The server re-scores on submit; critical-asset uplift uses the assets you tick above.</p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Approval routing</h3>
                        {routing.length === 0 ? (
                            <p className="text-sm text-[#2D7D46]">This change will be approved immediately on submission{d.change_type === 'standard' ? ' (pre-approved standard change)' : ''}.</p>
                        ) : (
                            <>
                                <p className="text-xs text-[#718096]">This change will need:</p>
                                <ol className="mt-2 space-y-1">
                                    {routing.map((s, idx) => (
                                        <li key={s} className="flex items-center gap-2 text-sm text-[#2D3748]">
                                            <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#0A1F44] text-[10px] text-white">{idx + 1}</span>{s}
                                        </li>
                                    ))}
                                </ol>
                            </>
                        )}
                        <p className="mt-2 text-[11px] text-[#718096]">From the bank's <Link href={route('changes.policy')} className="underline">approval policy</Link> for a {humanize(d.change_type).toLowerCase()} change at {humanize(risk.level).toLowerCase()} risk.</p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-xs text-[#4A5568] shadow-sm">
                        <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">Before you submit</h3>
                        <ul className="list-disc space-y-1 pl-4">
                            <li>Normal and emergency changes need a description, impact assessment, implementation plan and back-out plan.</li>
                            <li>High and critical changes need a test plan before they can be scheduled.</li>
                            <li>Requester, implementer and change owner cannot approve their own change.</li>
                        </ul>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
