import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Icon from '@/Components/Material/Icon';
import Chip from '@/Components/Material/Chip';
import { formatDate, humanize } from '@/Utils/risk';
import { CATEGORIES, CATEGORY_ICON, CHANGE_TYPES, RISK_LEVELS, windowLabel } from '@/Utils/changes';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const COLUMNS = ['draft', 'in_approval', 'approved', 'scheduled', 'implementing', 'review'];
const COLUMN_LABEL = { draft: 'Draft', in_approval: 'Awaiting approval', approved: 'Approved', scheduled: 'Scheduled', implementing: 'Implementing', review: 'In review (PIR)' };
const COLUMN_ICON = { draft: 'edit_note', in_approval: 'hourglass_top', approved: 'verified', scheduled: 'event', implementing: 'construction', review: 'rate_review' };

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
                            <div key={c} className="min-h-[200px] rounded-2xl bg-[#F0F1F4] p-2">
                                <h3 className="mb-2 flex items-center gap-1.5 px-1.5 pt-1 text-sm font-medium text-[#1B1B1F]">
                                    <Icon name={COLUMN_ICON[c]} size={18} className="text-[#43474E]" />
                                    <span className="truncate">{COLUMN_LABEL[c]}</span>
                                    <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-[#DDE3F0] px-1.5 text-[11px] font-semibold text-[#0A1F44]">{grouped[c].length}</span>
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
    const when = i.scheduled_start_at ? windowLabel(i.scheduled_start_at, i.scheduled_end_at) : null;
    return (
        <Link href={route('changes.show', i.id)}
            className="block rounded-xl bg-white p-3 shadow-[0_1px_2px_rgba(0,0,0,0.08),0_1px_3px_1px_rgba(0,0,0,0.04)] transition hover:shadow-[0_1px_2px_rgba(0,0,0,0.1),0_2px_6px_2px_rgba(0,0,0,0.08)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0A1F44]">
            <span className="block truncate font-mono text-[11px] text-[#74777F]">{i.change_code}</span>
            <p className="mt-1 line-clamp-2 text-sm font-medium leading-snug text-[#1B1B1F]">{i.title}</p>
            <div className="mt-2 flex flex-wrap items-center gap-1">
                {i.change_type !== 'normal' && <StatusBadge status={i.change_type} label={humanize(i.change_type)} size="xs" />}
                <StatusBadge status={i.risk_level} label={humanize(i.risk_level)} size="xs" />
                <Chip tone="neutral" variant="outlined" icon={CATEGORY_ICON[i.category] || 'category'} iconFill={false} size="xs">{humanize(i.category)}</Chip>
            </div>
            <div className="mt-2 flex items-center gap-1 border-t border-[#E3E4E8] pt-2 text-[11px] text-[#43474E]">
                {i.current_stage ? (
                    <><Icon name="how_to_reg" size={16} className="text-[#8A6400]" /><span className="truncate font-medium text-[#6B5420]">{i.current_stage}</span></>
                ) : when ? (
                    <><Icon name="schedule" size={16} /><span className="truncate tabular-nums">{when}</span></>
                ) : (
                    <><Icon name="person" size={16} /><span className={`truncate ${i.owner ? '' : 'text-[#B3261E]'}`}>{i.owner || 'No owner'}</span></>
                )}
            </div>
        </Link>
    );
}
