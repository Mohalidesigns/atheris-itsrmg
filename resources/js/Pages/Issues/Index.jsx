import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import CapaFields from '@/Components/Capa/CapaFields';
import { SeverityBadge, SEVERITIES, severityLabel, inputCls, labelCls } from '@/Components/Capa/shared';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const COLUMNS = ['open', 'in_progress', 'blocked', 'remediated', 'verified', 'closed'];
const SEVERITY_COLOUR = { critical: '#B3261E', high: '#E5A100', moderate: '#1D4ED8', low: '#A0AEC0' };
const SLA_LABEL = {
    on_track: ['On track', 'text-[#2D7D46]'], at_risk: ['At risk', 'text-[#8A6400]'], breached: ['Past SLA', 'text-[#B3261E] font-semibold'],
    paused: ['Paused', 'text-[#718096]'], met: ['Met SLA', 'text-[#2D7D46]'], missed: ['Missed SLA', 'text-[#B3261E]'], none: ['—', 'text-[#718096]'],
};

function SlaTag({ issue }) {
    const [label, klass] = SLA_LABEL[issue.sla_state] || SLA_LABEL.none;
    return <span className={`text-[10px] ${klass}`}>{label}{issue.sla_due_at && ['on_track', 'at_risk', 'breached'].includes(issue.sla_state) ? ` · ${formatDate(issue.sla_due_at)}` : ''}</span>;
}

export default function IssuesIndex({ issues = [], counts = {}, stats = {}, filters = {}, sources = {}, owners = [], sourceRecords = null, capaKpis = null, capaConfig = null }) {
    const { auth } = usePage().props;
    const can = (p) => (auth?.user?.permissions || []).includes(p) || (auth?.user?.roles || []).includes('Super Admin');
    const view = filters.view === 'list' ? 'list' : 'board';
    const [creating, setCreating] = useState(false);
    const [q, setQ] = useState(filters.q || '');

    const apply = (next) => router.get(route('issues.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const sortBy = (col) => apply({ sort: col, dir: filters.sort === col && filters.dir !== 'desc' ? 'desc' : 'asc' });

    const capaOn = !!capaConfig?.enabled;
    const stageLabel = (k) => (capaConfig?.stages || []).find((s) => s.key === k)?.label || humanize(k || '');
    const fwShort = (k) => (capaConfig?.frameworks || []).find((f) => f.key === k)?.label || k;
    const form = useForm({
        title: '', description: '', severity: 'high', source_type: 'risk', source_id: '', source_reference: '', owner_id: '', root_cause: '', remediation_plan: '',
        capa_framework: '', capa_classification: '', framework_reference: '', cbn_provisions: [], settling_days: '',
    });
    const openCreate = () => {
        setCreating(true);
        if (!sourceRecords) router.reload({ only: ['sourceRecords'] });
    };
    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => (d.capa_framework ? (d.settling_days === '' ? { ...d, settling_days: undefined } : d) : { ...d, capa_framework: null, capa_classification: null, framework_reference: null, cbn_provisions: [], settling_days: null }));
        form.post(route('issues.store'), { onSuccess: () => { setCreating(false); form.reset(); } });
    };
    const pickable = sourceRecords?.[form.data.source_type];
    const grouped = COLUMNS.reduce((acc, c) => ({ ...acc, [c]: issues.filter((i) => i.status === c) }), {});
    const filtered = ['status', 'severity', 'source', 'owner', 'q', 'framework', 'stage'].some((k) => filters[k]);
    const exportParams = {};
    if (capaConfig?.frameworks?.some((f) => f.key === filters.framework)) exportParams.framework = filters.framework;
    if (filters.stage) exportParams.stage = filters.stage;
    const targets = capaConfig?.kpiTargets || { overdue_rate_pct: 5, recurrence_rate_pct: 0 };
    const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);

    return (
        <AuthenticatedLayout header="CAPA Register">
            <Head title="CAPA Register" />
            <PageHeader
                breadcrumbs={[{ label: 'CAPA & Remediation' }, { label: 'CAPA Register' }]}
                title="CAPA Register"
                subtitle="One corrective and preventive action register for audit, control-test, risk, incident, vulnerability, compliance and ARB findings, on the bank's SLA policy with escalation and framework-driven CAPA workflow."
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {capaOn && can('export issues') && (
                            <a href={route('issues.capa.export', exportParams)} className="inline-flex items-center gap-1 rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                                <Icon name="download" size={18} />Export CSV
                            </a>
                        )}
                        {can('create issues') && <button type="button" onClick={openCreate} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Raise issue</button>}
                    </div>
                )}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <button type="button" onClick={() => apply({ status: 'active' })} className="text-left"><KpiCard label="Open" value={stats.open ?? 0} sublabel={`${stats.open_critical ?? 0} critical`} tone="navy" /></button>
                <button type="button" onClick={() => apply({ status: 'overdue' })} className="text-left"><KpiCard label="Past SLA" value={stats.overdue ?? 0} tone={stats.overdue ? 'red' : 'white'} /></button>
                <button type="button" onClick={() => apply({ status: 'escalated' })} className="text-left"><KpiCard label="Escalated" value={stats.escalated ?? 0} tone={stats.escalated ? 'amber' : 'white'} /></button>
                <button type="button" onClick={() => apply({ status: 'remediated' })} className="text-left"><KpiCard label="Awaiting verification" value={stats.awaiting_verification ?? 0} tone="white" /></button>
                <KpiCard label="Fixed within SLA" value={stats.sla_compliance_pct === null || stats.sla_compliance_pct === undefined ? '—' : `${stats.sla_compliance_pct}%`} sublabel="last 180 days" tone={stats.sla_compliance_pct !== null && stats.sla_compliance_pct < 80 ? 'amber' : 'green'} />
                <KpiCard label="Mean time to remediate" value={stats.mean_days_to_remediate === null || stats.mean_days_to_remediate === undefined ? '—' : `${stats.mean_days_to_remediate} d`} sublabel={`${stats.ticketed ?? 0} open issues ticketed`} tone="white" />
            </div>

            {capaOn && capaKpis && (
                <div className="mb-4">
                    <h2 className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase text-[#718096]"><Icon name="monitoring" size={16} className="text-[#C9A86A]" fill />CAPA performance</h2>
                    <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <KpiCard label="Overdue action rate" value={pct(capaKpis.overdue_rate_pct)}
                            sublabel={`Target under ${targets.overdue_rate_pct}% · ${capaKpis.actions_overdue ?? 0} of ${capaKpis.actions_total ?? 0} actions`}
                            tone={capaKpis.overdue_rate_pct === null || capaKpis.overdue_rate_pct === undefined ? 'white' : capaKpis.overdue_rate_pct < targets.overdue_rate_pct ? 'green' : 'red'} />
                        <KpiCard label="12-month recurrence" value={pct(capaKpis.recurrence_rate_pct)}
                            sublabel={`Target ${targets.recurrence_rate_pct}% · ${capaKpis.recurred_12m ?? 0} of ${capaKpis.closed_12m ?? 0} closed`}
                            tone={capaKpis.recurrence_rate_pct === null || capaKpis.recurrence_rate_pct === undefined ? 'white' : capaKpis.recurrence_rate_pct <= targets.recurrence_rate_pct ? 'green' : 'red'} />
                        <KpiCard label="CAPA MTTR" value={capaKpis.mttr_days === null || capaKpis.mttr_days === undefined ? '—' : `${capaKpis.mttr_days} d`} sublabel="Stage 1 to 5: raised to implemented" tone="white" />
                        <button type="button" onClick={() => apply({ stage: 'verification' })} className="text-left">
                            <KpiCard label="Verifications due" value={capaKpis.verifications_due ?? 0} sublabel={`${capaKpis.in_settling ?? 0} still settling`} tone={capaKpis.verifications_due ? 'amber' : 'white'} />
                        </button>
                    </div>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                <form onSubmit={(e) => { e.preventDefault(); apply({ q }); }} className="flex-1 min-w-[180px]">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search title, reference or ticket…" className="w-full rounded-lg border border-gray-200 px-3 py-1.5 text-sm" />
                </form>
                <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    <option value="active">Open (any)</option>
                    <option value="overdue">Past SLA</option>
                    <option value="escalated">Escalated</option>
                    {COLUMNS.map((c) => <option key={c} value={c}>{humanize(c)} ({counts[c] || 0})</option>)}
                </select>
                <select value={filters.severity || ''} onChange={(e) => apply({ severity: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All severities</option>
                    {SEVERITIES.map((s) => <option key={s} value={s}>{severityLabel(s, capaConfig)}</option>)}
                </select>
                {capaOn && (
                    <>
                        <select value={filters.framework || ''} onChange={(e) => apply({ framework: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm" aria-label="Framework">
                            <option value="">All frameworks</option>
                            <option value="any">Any CAPA framework</option>
                            {capaConfig.frameworks.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                            <option value="none">Legacy (no framework)</option>
                        </select>
                        <select value={filters.stage || ''} onChange={(e) => apply({ stage: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm" aria-label="CAPA stage">
                            <option value="">All stages</option>
                            {capaConfig.stages.map((s) => <option key={s.key} value={s.key}>{s.label}{capaKpis?.by_stage ? ` (${capaKpis.by_stage[s.key] ?? 0})` : ''}</option>)}
                        </select>
                    </>
                )}
                <select value={filters.source || ''} onChange={(e) => apply({ source: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All sources</option>
                    {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
                <select value={filters.owner || ''} onChange={(e) => apply({ owner: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">Any owner</option>
                    <option value="me">Mine</option>
                    <option value="none">Unassigned</option>
                </select>
                {filtered && <button type="button" onClick={() => { setQ(''); router.get(route('issues.index'), { view }); }} className="text-xs text-[#0A1F44] underline">Clear</button>}
                <div className="ml-auto flex overflow-hidden rounded-lg border border-gray-200 text-xs">
                    {['board', 'list'].map((v) => (
                        <button key={v} type="button" onClick={() => apply({ view: v })} className={`px-3 py-1.5 ${view === v ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748]'}`}>{humanize(v)}</button>
                    ))}
                </div>
            </div>

            {issues.length === 0 && (
                <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">No issues match these filters.</p>
            )}

            {issues.length > 0 && view === 'board' && (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {COLUMNS.map((c) => (
                        <div key={c} className="min-h-[200px] rounded-xl bg-[#F7FAFC] p-3">
                            <h3 className="mb-2 flex justify-between text-xs font-semibold uppercase text-[#718096]">
                                <span>{humanize(c)}</span><span>{grouped[c].length}</span>
                            </h3>
                            <div className="space-y-2">
                                {grouped[c].map((i) => (
                                    <Link key={i.id} href={route('issues.show', i.id)}
                                        className="block rounded-lg border-l-4 bg-white p-3 shadow-sm hover:shadow"
                                        style={{ borderLeftColor: SEVERITY_COLOUR[i.severity] }}>
                                        <p className="font-mono text-[10px] text-[#718096]">{i.issue_code}{i.external_ticket_id ? ` · ${i.external_ticket_id}` : ''}</p>
                                        <p className="line-clamp-2 text-xs font-medium text-[#2D3748]">{i.title}</p>
                                        <div className="mt-1 flex flex-wrap items-center justify-between gap-1">
                                            <SeverityBadge severity={i.severity} config={capaConfig} />
                                            <SlaTag issue={i} />
                                        </div>
                                        {i.capa_framework && <div className="mt-1"><Chip tone="primary" size="xs" icon="account_tree" title={fwShort(i.capa_framework)}>{stageLabel(i.capa_stage).split(' ')[0]}</Chip></div>}
                                        <p className="mt-1 truncate text-[10px] text-[#718096]">
                                            {i.owner || 'Unassigned'}{i.escalation_level > 0 && <span className="ml-1 font-semibold text-[#8A6400]">· Escalated L{i.escalation_level}</span>}
                                        </p>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {issues.length > 0 && view === 'list' && (
                <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                {[['issue_code', 'Ref'], [null, 'Title'], ['severity', 'Severity'], ['status', 'Status'], [null, 'Source'], ...(capaOn ? [[null, 'CAPA stage']] : []), [null, 'Owner'], ['sla_due_at', 'SLA due'], [null, 'Ticket']].map(([col, label]) => (
                                    <th key={label} className="px-3 py-2">
                                        {col ? <button type="button" onClick={() => sortBy(col)} className="uppercase">{label}{(filters.sort || 'sla_due_at') === col ? (filters.dir === 'desc' ? ' ↓' : ' ↑') : ''}</button> : label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {issues.map((i) => (
                                <tr key={i.id} className="hover:bg-gray-50">
                                    <td className="whitespace-nowrap px-3 py-2 font-mono text-xs"><Link href={route('issues.show', i.id)} className="text-[#0A1F44] underline">{i.issue_code}</Link></td>
                                    <td className="max-w-xs px-3 py-2 text-[#2D3748]"><span className="line-clamp-1">{i.title}</span></td>
                                    <td className="px-3 py-2"><SeverityBadge severity={i.severity} config={capaConfig} /></td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={i.status} label={humanize(i.status)} />
                                        {i.escalation_level > 0 && <span className="ml-1 text-[10px] font-semibold text-[#8A6400]">L{i.escalation_level}</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{i.source_label}</td>
                                    {capaOn && (
                                        <td className="px-3 py-2 text-xs text-[#4A5568]">
                                            {i.capa_framework ? (
                                                <span className="flex flex-col gap-1">
                                                    <Chip tone="primary" size="xs" icon="account_tree">{stageLabel(i.capa_stage)}</Chip>
                                                    <span className="text-[10px] text-[#718096]">{fwShort(i.capa_framework)}{i.capa_stage === 'verification' && i.verification_due_at ? ` · verify from ${formatDate(i.verification_due_at)}` : ''}</span>
                                                </span>
                                            ) : <span className="text-[#A0AEC0]">Legacy</span>}
                                        </td>
                                    )}
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{i.owner || <span className="text-[#B3261E]">Unassigned</span>}</td>
                                    <td className="whitespace-nowrap px-3 py-2"><SlaTag issue={i} /></td>
                                    <td className="px-3 py-2 font-mono text-xs text-[#4A5568]">{i.external_ticket_id || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-3 p-6">
                    <h2 className="text-lg font-semibold text-[#0A1F44]">Raise an issue</h2>
                    <p className="text-xs text-[#718096]">The SLA due time comes from the bank's severity policy and starts now.</p>
                    <div>
                        <label className="block text-xs font-medium text-[#4A5568]">Title</label>
                        <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        <InputError message={form.errors.title} className="mt-1" />
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Severity</label>
                            <select value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                {SEVERITIES.map((s) => <option key={s} value={s}>{severityLabel(s, capaConfig)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Source</label>
                            <select value={form.data.source_type} onChange={(e) => form.setData((d) => ({ ...d, source_type: e.target.value, source_id: '' }))} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Owner</label>
                            <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                <option value="">Unassigned</option>
                                {owners.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}
                            </select>
                            <InputError message={form.errors.owner_id} className="mt-1" />
                        </div>
                    </div>
                    {sourceRecords && pickable !== undefined && (
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Source record</label>
                            <select value={form.data.source_id} onChange={(e) => form.setData('source_id', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                <option value="">— none: cite a reference instead —</option>
                                {pickable.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
                            </select>
                            <InputError message={form.errors.source_id} className="mt-1" />
                        </div>
                    )}
                    {!sourceRecords && <p className="text-xs text-[#718096]">Loading source records…</p>}
                    <div>
                        <label className="block text-xs font-medium text-[#4A5568]">Source reference {pickable === undefined ? '' : '(optional)'}</label>
                        <input value={form.data.source_reference} onChange={(e) => form.setData('source_reference', e.target.value)}
                            placeholder={form.data.source_type === 'audit' ? 'e.g. Internal Audit report IA-2026-14, finding 3' : 'Report, letter or finding reference'}
                            className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        <InputError message={form.errors.source_reference} className="mt-1" />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-[#4A5568]">Description</label>
                        <textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={3} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Root cause (if known)</label>
                            <textarea value={form.data.root_cause} onChange={(e) => form.setData('root_cause', e.target.value)} rows={2} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Remediation plan</label>
                            <textarea value={form.data.remediation_plan} onChange={(e) => form.setData('remediation_plan', e.target.value)} rows={2} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        </div>
                    </div>
                    {capaOn && (
                        <div className="space-y-3 rounded-lg border border-[#C9A86A]/50 bg-[#C9A86A]/5 p-3">
                            <h3 className="flex items-center gap-1.5 text-sm font-semibold text-[#0A1F44]"><Icon name="account_tree" size={18} fill className="text-[#C9A86A]" />CAPA path (optional)</h3>
                            <CapaFields config={capaConfig} data={form.data} setData={form.setData} errors={form.errors} allowNone />
                            {form.data.capa_framework && (
                                <div className="max-w-xs">
                                    <label className={labelCls}>Settling period (days)</label>
                                    <input type="number" min={capaConfig.settling?.min} max={capaConfig.settling?.max} value={form.data.settling_days}
                                        onChange={(e) => form.setData('settling_days', e.target.value)} placeholder={String(capaConfig.settling?.default ?? 30)} className={inputCls} />
                                    <InputError message={form.errors.settling_days} className="mt-1" />
                                </div>
                            )}
                        </div>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setCreating(false)} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>
                        <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Raise issue</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
