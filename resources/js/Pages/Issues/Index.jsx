import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
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

export default function IssuesIndex({ issues = [], counts = {}, stats = {}, filters = {}, sources = {}, owners = [], sourceRecords = null }) {
    const { auth } = usePage().props;
    const can = (p) => (auth?.user?.permissions || []).includes(p) || (auth?.user?.roles || []).includes('Super Admin');
    const view = filters.view === 'list' ? 'list' : 'board';
    const [creating, setCreating] = useState(false);
    const [q, setQ] = useState(filters.q || '');

    const apply = (next) => router.get(route('issues.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const sortBy = (col) => apply({ sort: col, dir: filters.sort === col && filters.dir !== 'desc' ? 'desc' : 'asc' });

    const form = useForm({ title: '', description: '', severity: 'high', source_type: 'risk', source_id: '', source_reference: '', owner_id: '', root_cause: '', remediation_plan: '' });
    const openCreate = () => {
        setCreating(true);
        if (!sourceRecords) router.reload({ only: ['sourceRecords'] });
    };
    const submit = (e) => {
        e.preventDefault();
        form.post(route('issues.store'), { onSuccess: () => { setCreating(false); form.reset(); } });
    };
    const pickable = sourceRecords?.[form.data.source_type];
    const grouped = COLUMNS.reduce((acc, c) => ({ ...acc, [c]: issues.filter((i) => i.status === c) }), {});
    const filtered = ['status', 'severity', 'source', 'owner', 'q'].some((k) => filters[k]);

    return (
        <AuthenticatedLayout header="Issues Console">
            <Head title="Issues Console" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation' }, { label: 'Issues Console' }]}
                title="Unified Issues Console"
                subtitle="One corrective-action register for audit, control-test, risk, incident, vulnerability, compliance and ARB findings, on the bank's SLA policy with escalation."
                actions={can('create issues') && (
                    <button type="button" onClick={openCreate} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Raise issue</button>
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
                    {['critical', 'high', 'moderate', 'low'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                </select>
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
                                            <StatusBadge status={i.severity} />
                                            <SlaTag issue={i} />
                                        </div>
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
                                {[['issue_code', 'Ref'], [null, 'Title'], ['severity', 'Severity'], ['status', 'Status'], [null, 'Source'], [null, 'Owner'], ['sla_due_at', 'SLA due'], [null, 'Ticket']].map(([col, label]) => (
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
                                    <td className="px-3 py-2"><StatusBadge status={i.severity} /></td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={i.status} label={humanize(i.status)} />
                                        {i.escalation_level > 0 && <span className="ml-1 text-[10px] font-semibold text-[#8A6400]">L{i.escalation_level}</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{i.source_label}</td>
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
                                {['critical', 'high', 'moderate', 'low'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
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
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setCreating(false)} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>
                        <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Raise issue</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
