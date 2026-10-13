import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { formatDate, humanize } from '@/Utils/risk';
import { CATEGORIES, CHANGE_TYPES, RISK_COLOUR, RISK_LEVELS, windowLabel } from '@/Utils/changes';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const COLUMNS = ['draft', 'in_approval', 'approved', 'scheduled', 'implementing', 'review'];
const COLUMN_LABEL = { draft: 'Draft', in_approval: 'Awaiting approval', approved: 'Approved', scheduled: 'Scheduled', implementing: 'Implementing', review: 'In review (PIR)' };

export default function ChangesIndex({ changes = [], counts = {}, stats = {}, filters = {} }) {
    const { auth } = usePage().props;
    const can = (p) => (auth?.user?.permissions || []).includes(p) || (auth?.user?.roles || []).includes('Super Admin');
    const view = filters.view === 'list' ? 'list' : 'board';
    const [q, setQ] = useState(filters.q || '');

    const apply = (next) => router.get(route('changes.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const sortBy = (col) => apply({ sort: col, dir: filters.sort === col && filters.dir !== 'desc' ? 'desc' : 'asc' });

    const grouped = COLUMNS.reduce((acc, c) => ({ ...acc, [c]: changes.filter((i) => i.status === c || (c === 'review' && ['implemented', 'backed_out', 'failed'].includes(i.status))) }), {});
    const other = changes.filter((i) => !COLUMNS.includes(i.status) && !['implemented', 'backed_out', 'failed'].includes(i.status));
    const filtered = ['status', 'type', 'risk', 'category', 'q', 'mine'].some((k) => filters[k]);
    const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);

    return (
        <AuthenticatedLayout header="Change Register">
            <Head title="Change Register" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register' }]}
                title="Change Register"
                subtitle="Every standard, normal and emergency change in the bank: risk-scored, routed through the approval policy, placed on the forward schedule and reviewed after implementation."
                actions={(
                    <>
                        {can('export changes') && <a href={route('changes.export')} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">Export CSV</a>}
                        {can('create changes') && <Link href={route('changes.create')} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Raise change</Link>}
                    </>
                )}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <button type="button" onClick={() => apply({ status: 'open' })} className="text-left"><KpiCard label="Open" value={stats.open ?? 0} sublabel={`${stats.implementing ?? 0} implementing now`} tone="navy" /></button>
                <button type="button" onClick={() => apply({ status: 'in_approval' })} className="text-left"><KpiCard label="Awaiting approval" value={stats.awaiting_approval ?? 0} sublabel={`${stats.cab_pending ?? 0} at CAB / ECAB`} tone={stats.awaiting_approval ? 'gold' : 'white'} /></button>
                <button type="button" onClick={() => apply({ status: 'upcoming' })} className="text-left"><KpiCard label="Scheduled (7 d)" value={stats.scheduled_7d ?? 0} tone="white" /></button>
                <button type="button" onClick={() => apply({ type: 'emergency' })} className="text-left"><KpiCard label="Emergency (30 d)" value={stats.emergency_30d ?? 0} sublabel={stats.emergency_ratio_pct !== null && stats.emergency_ratio_pct !== undefined ? `${stats.emergency_ratio_pct}% of changes, 90 d` : undefined} tone={stats.unauthorised_emergency ? 'red' : 'white'} /></button>
                <KpiCard label="Success rate (90 d)" value={pct(stats.success_rate_pct)} sublabel={`${stats.failed_or_backed_out_90d ?? 0} failed / backed out`} tone={stats.success_rate_pct !== null && stats.success_rate_pct !== undefined && stats.success_rate_pct < 90 ? 'amber' : 'green'} />
                <Link href={route('changes.approvals')} className="text-left"><KpiCard label="Overdue approvals" value={stats.overdue_approvals ?? 0} sublabel={stats.mean_lead_time_days !== null && stats.mean_lead_time_days !== undefined ? `lead time ${stats.mean_lead_time_days} d` : undefined} tone={stats.overdue_approvals ? 'red' : 'white'} /></Link>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                <form onSubmit={(e) => { e.preventDefault(); apply({ q }); }} className="min-w-[180px] flex-1">
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search title, code or description…" className="w-full rounded-lg border border-gray-200 px-3 py-1.5 text-sm" />
                </form>
                <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    <option value="open">Open (any)</option>
                    <option value="upcoming">Upcoming window</option>
                    {['draft', 'in_approval', 'approved', 'scheduled', 'implementing', 'implemented', 'review', 'closed', 'rejected', 'cancelled', 'backed_out', 'failed'].map((c) => (
                        <option key={c} value={c}>{humanize(c)} ({counts[c] || 0})</option>
                    ))}
                </select>
                <select value={filters.type || ''} onChange={(e) => apply({ type: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All types</option>
                    {CHANGE_TYPES.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                </select>
                <select value={filters.risk || ''} onChange={(e) => apply({ risk: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All risk levels</option>
                    {RISK_LEVELS.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                </select>
                <select value={filters.category || ''} onChange={(e) => apply({ category: e.target.value })} className="rounded-lg border border-gray-200 py-1.5 text-sm">
                    <option value="">All categories</option>
                    {CATEGORIES.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
                </select>
                <label className="flex items-center gap-1 text-xs text-[#4A5568]">
                    <input type="checkbox" checked={!!filters.mine} onChange={(e) => apply({ mine: e.target.checked ? 1 : '' })} className="rounded border-gray-300" /> Mine
                </label>
                {filtered && <button type="button" onClick={() => { setQ(''); router.get(route('changes.index'), { view }); }} className="text-xs text-[#0A1F44] underline">Clear</button>}
                <div className="ml-auto flex overflow-hidden rounded-lg border border-gray-200 text-xs">
                    {['board', 'list'].map((v) => (
                        <button key={v} type="button" onClick={() => apply({ view: v })} className={`px-3 py-1.5 ${view === v ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748]'}`}>{humanize(v)}</button>
                    ))}
                </div>
            </div>

            {changes.length === 0 && (
                <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">No changes match these filters.</p>
            )}

            {changes.length > 0 && view === 'board' && (
                <>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        {COLUMNS.map((c) => (
                            <div key={c} className="min-h-[200px] rounded-xl bg-[#F7FAFC] p-3">
                                <h3 className="mb-2 flex justify-between text-xs font-semibold uppercase text-[#718096]">
                                    <span>{COLUMN_LABEL[c]}</span><span>{grouped[c].length}</span>
                                </h3>
                                <div className="space-y-2">
                                    {grouped[c].map((i) => <ChangeCard key={i.id} change={i} />)}
                                </div>
                            </div>
                        ))}
                    </div>
                    {other.length > 0 && (
                        <div className="mt-4 rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                            <h3 className="mb-2 text-xs font-semibold uppercase text-[#718096]">Closed, rejected and cancelled ({other.length})</h3>
                            <div className="grid grid-cols-1 gap-2 md:grid-cols-3 xl:grid-cols-6">
                                {other.map((i) => <ChangeCard key={i.id} change={i} />)}
                            </div>
                        </div>
                    )}
                </>
            )}

            {changes.length > 0 && view === 'list' && (
                <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                {[['change_code', 'Code'], ['title', 'Title'], [null, 'Type'], [null, 'Category'], ['risk_level', 'Risk'], ['priority', 'Priority'], ['status', 'Status'], [null, 'Stage'], [null, 'Owner / Implementer'], ['scheduled_start_at', 'Window'], ['created_at', 'Raised']].map(([col, label]) => (
                                    <th key={label} className="px-3 py-2">
                                        {col ? <button type="button" onClick={() => sortBy(col)} className="uppercase">{label}{(filters.sort || 'created_at') === col ? (filters.dir === 'asc' ? ' ↑' : ' ↓') : ''}</button> : label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {changes.map((i) => (
                                <tr key={i.id} className="hover:bg-gray-50">
                                    <td className="whitespace-nowrap px-3 py-2 font-mono text-xs"><Link href={route('changes.show', i.id)} className="text-[#0A1F44] underline">{i.change_code}</Link></td>
                                    <td className="max-w-xs px-3 py-2 text-[#2D3748]"><span className="line-clamp-1">{i.title}</span></td>
                                    <td className="px-3 py-2"><StatusBadge status={i.change_type} label={humanize(i.change_type)} /></td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{humanize(i.category)}</td>
                                    <td className="px-3 py-2"><StatusBadge status={i.risk_level} label={humanize(i.risk_level)} /></td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{humanize(i.priority)}</td>
                                    <td className="px-3 py-2"><StatusBadge status={i.status} label={humanize(i.status)} /></td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{i.current_stage || (i.approvals_pending ? `${i.approvals_pending} pending` : '—')}</td>
                                    <td className="px-3 py-2 text-xs text-[#4A5568]">{i.owner || <span className="text-[#B3261E]">No owner</span>}{i.implementer ? ` / ${i.implementer}` : ''}</td>
                                    <td className="whitespace-nowrap px-3 py-2 text-xs text-[#4A5568]">{windowLabel(i.scheduled_start_at, i.scheduled_end_at)}</td>
                                    <td className="whitespace-nowrap px-3 py-2 text-xs text-[#4A5568]">{formatDate(i.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function ChangeCard({ change: i }) {
    return (
        <Link href={route('changes.show', i.id)} className="block rounded-lg border-l-4 bg-white p-3 shadow-sm hover:shadow" style={{ borderLeftColor: RISK_COLOUR[i.risk_level] || '#A0AEC0' }}>
            <p className="flex items-center justify-between font-mono text-[10px] text-[#718096]">
                <span>{i.change_code}</span>
                {i.change_type !== 'normal' && <span className={`rounded px-1 font-sans text-[9px] font-semibold uppercase ${i.change_type === 'emergency' ? 'bg-[#B3261E]/10 text-[#B3261E]' : 'bg-[#2D7D46]/10 text-[#2D7D46]'}`}>{i.change_type}</span>}
            </p>
            <p className="line-clamp-2 text-xs font-medium text-[#2D3748]">{i.title}</p>
            <div className="mt-1 flex flex-wrap items-center justify-between gap-1">
                <StatusBadge status={i.risk_level} label={humanize(i.risk_level)} />
                <span className="text-[10px] text-[#718096]">{humanize(i.category)}</span>
            </div>
            <p className="mt-1 truncate text-[10px] text-[#718096]">
                {i.current_stage ? <span className="font-semibold text-[#8A6400]">{i.current_stage}</span> : i.scheduled_start_at ? windowLabel(i.scheduled_start_at, i.scheduled_end_at) : i.owner || 'No owner'}
            </p>
        </Link>
    );
}
