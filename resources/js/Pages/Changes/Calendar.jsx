import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, humanize } from '@/Utils/risk';
import { RISK_COLOUR, STATUS_COLOUR, windowLabel } from '@/Utils/changes';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const DAY = 86400000;
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parseYmd = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);

export default function ChangeCalendar({ from, to, changes = [], freezeWindows = [], conflictIds = [] }) {
    const start = parseYmd(from);
    const end = parseYmd(to);
    const [typeFilter, setTypeFilter] = useState('');
    const spanDays = Math.round((end - start) / DAY) + 1;
    const mode = spanDays <= 7 ? 'week' : 'month';

    const go = (f, t) => router.get(route('changes.calendar'), { from: ymd(f), to: ymd(t) }, { preserveState: true, replace: true });
    const shift = (dir) => {
        if (mode === 'week') return go(addDays(start, 7 * dir), addDays(end, 7 * dir));
        const f = new Date(start.getFullYear(), start.getMonth() + dir, 1);
        return go(f, new Date(f.getFullYear(), f.getMonth() + 1, 0));
    };
    const thisMonth = () => { const n = new Date(); const f = new Date(n.getFullYear(), n.getMonth(), 1); go(f, new Date(f.getFullYear(), f.getMonth() + 1, 0)); };
    const thisWeek = () => { const n = new Date(); const dow = (n.getDay() + 6) % 7; const f = addDays(n, -dow); go(f, addDays(f, 6)); };

    const visible = changes.filter((c) => !typeFilter || c.change_type === typeFilter);
    const conflictSet = new Set(conflictIds);

    // Grid cells: pad to full weeks starting Monday.
    const cells = useMemo(() => {
        const lead = (start.getDay() + 6) % 7;
        const first = addDays(start, -lead);
        const total = Math.ceil((spanDays + lead) / 7) * 7;
        return Array.from({ length: total }, (_, i) => addDays(first, i));
    }, [from, to]); // eslint-disable-line react-hooks/exhaustive-deps

    const dayItems = (d) => {
        const ds = d.getTime();
        const de = ds + DAY - 1;
        return visible.filter((c) => {
            const s = new Date(c.scheduled_start_at).getTime();
            const e = c.scheduled_end_at ? new Date(c.scheduled_end_at).getTime() : s;
            return s <= de && e >= ds;
        });
    };
    const dayFreeze = (d) => freezeWindows.filter((w) => new Date(w.starts_at).getTime() <= d.getTime() + DAY - 1 && new Date(w.ends_at).getTime() >= d.getTime());
    const today = ymd(new Date());

    return (
        <AuthenticatedLayout header="Change Calendar">
            <Head title="Change Calendar" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'Change Calendar' }]}
                title="Forward Schedule of Change"
                subtitle="Approved, scheduled and in-flight changes on the calendar, with the bank's freeze windows shaded and clashes flagged."
                actions={<Link href={route('changes.freeze.index')} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">Freeze windows</Link>}
            />

            <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                <button type="button" onClick={() => shift(-1)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs">‹ Previous</button>
                <span className="text-sm font-semibold text-[#0A1F44]">{formatDate(from)} – {formatDate(to)}</span>
                <button type="button" onClick={() => shift(1)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs">Next ›</button>
                <div className="ml-2 flex overflow-hidden rounded-lg border border-gray-200 text-xs">
                    <button type="button" onClick={thisWeek} className={`px-3 py-1.5 ${mode === 'week' ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748]'}`}>Week</button>
                    <button type="button" onClick={thisMonth} className={`px-3 py-1.5 ${mode === 'month' ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748]'}`}>Month</button>
                </div>
                <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="rounded-lg border border-gray-200 py-1.5 text-xs">
                    <option value="">All types</option>
                    {['standard', 'normal', 'emergency'].map((t) => <option key={t} value={t}>{humanize(t)}</option>)}
                </select>
                <div className="ml-auto flex flex-wrap items-center gap-3 text-[11px] text-[#4A5568]">
                    {Object.entries(RISK_COLOUR).map(([k, v]) => <span key={k} className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: v }} />{humanize(k)}</span>)}
                    <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm bg-[#B3261E]/15 ring-1 ring-[#B3261E]/40" />Freeze</span>
                    <span className="flex items-center gap-1"><span className="text-[#E5A100]">▲</span>Conflict</span>
                    <span className="flex items-center gap-1"><span className="text-[#B3261E]">⚡</span>Outage</span>
                </div>
            </div>

            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                <div className="grid min-w-[700px] grid-cols-7 gap-1">
                    {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="px-1 text-[11px] font-semibold uppercase text-[#718096]">{d}</div>)}
                    {cells.map((d) => {
                        const key = ymd(d);
                        const inRange = d >= start && d <= end;
                        const items = inRange ? dayItems(d) : [];
                        const frozen = inRange ? dayFreeze(d) : [];
                        return (
                            <div key={key} className={`relative rounded-lg border p-1 ${mode === 'week' ? 'min-h-[220px]' : 'min-h-[96px]'} ${inRange ? (frozen.length ? 'border-[#B3261E]/30 bg-[#B3261E]/5' : 'border-gray-100 bg-white') : 'border-transparent bg-gray-50/60'} ${key === today ? 'ring-2 ring-[#C9A86A]' : ''}`}
                                title={frozen.map((w) => `Freeze: ${w.name}`).join('\n')}>
                                <div className="flex items-center justify-between">
                                    <span className={`text-[11px] ${inRange ? 'text-[#2D3748]' : 'text-[#CBD5E0]'} ${key === today ? 'font-bold text-[#0A1F44]' : ''}`}>{d.getDate()}{d.getDate() === 1 || key === from ? ` ${d.toLocaleString('en-GB', { month: 'short' })}` : ''}</span>
                                    {frozen.length > 0 && <span className="truncate text-[9px] font-semibold uppercase text-[#B3261E]" title={frozen.map((w) => w.name).join(', ')}>{frozen[0].scope === 'all' ? 'Freeze' : 'Partial freeze'}</span>}
                                </div>
                                <div className="mt-1 space-y-0.5">
                                    {items.slice(0, mode === 'week' ? 12 : 4).map((c) => (
                                        <Link key={c.id} href={route('changes.show', c.id)}
                                            className="block truncate rounded px-1 py-0.5 text-[10px] text-white hover:opacity-90"
                                            style={{ backgroundColor: RISK_COLOUR[c.risk_level] || '#718096', borderLeft: `3px solid ${STATUS_COLOUR[c.status] || '#fff'}` }}
                                            title={`${c.change_code} ${c.title}\n${windowLabel(c.scheduled_start_at, c.scheduled_end_at)}\n${humanize(c.status)} · ${humanize(c.change_type)}`}>
                                            {conflictSet.has(c.id) && <span className="mr-0.5 text-[#FFE08A]">▲</span>}{c.outage_required && <span className="mr-0.5">⚡</span>}
                                            {c.change_type === 'emergency' && <span className="mr-0.5 font-bold">E</span>}
                                            {new Date(c.scheduled_start_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })} {c.change_code.split('-').pop()} {c.title}
                                        </Link>
                                    ))}
                                    {items.length > (mode === 'week' ? 12 : 4) && <p className="text-[10px] text-[#718096]">+{items.length - (mode === 'week' ? 12 : 4)} more</p>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {freezeWindows.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2 text-[11px]">
                    {freezeWindows.map((w) => (
                        <span key={w.id} className="rounded-full border border-[#B3261E]/30 bg-[#B3261E]/5 px-2 py-0.5 text-[#B3261E]">
                            {w.name}: {formatDate(w.starts_at)} – {formatDate(w.ends_at)} · {w.scope === 'all' ? 'all changes' : (w.categories || []).map(humanize).join(', ')}{w.allow_emergency ? ' · emergencies allowed' : ''}
                        </span>
                    ))}
                </div>
            )}

            <div className="mt-4 overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#2D3748]">Changes in this period ({visible.length})</h3>
                {visible.length === 0 ? <p className="p-4 text-xs text-[#718096]">No change has a window in this period.</p> : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]"><th className="px-4 py-2">Window</th><th className="px-4 py-2">Change</th><th className="px-4 py-2">Type</th><th className="px-4 py-2">Category</th><th className="px-4 py-2">Risk</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Flags</th></tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {visible.map((c) => (
                                <tr key={c.id} className="hover:bg-gray-50">
                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{windowLabel(c.scheduled_start_at, c.scheduled_end_at)}</td>
                                    <td className="px-4 py-2"><Link href={route('changes.show', c.id)} className="font-mono text-xs text-[#0A1F44] underline">{c.change_code}</Link> <span className="text-xs text-[#2D3748]">{c.title}</span></td>
                                    <td className="px-4 py-2"><StatusBadge status={c.change_type} label={humanize(c.change_type)} /></td>
                                    <td className="px-4 py-2 text-xs text-[#4A5568]">{humanize(c.category)}</td>
                                    <td className="px-4 py-2"><StatusBadge status={c.risk_level} label={humanize(c.risk_level)} /></td>
                                    <td className="px-4 py-2"><StatusBadge status={c.status} label={humanize(c.status)} /></td>
                                    <td className="px-4 py-2 text-xs">
                                        {conflictSet.has(c.id) && <span className="mr-2 font-semibold text-[#8A6400]">▲ Conflict</span>}
                                        {c.outage_required && <span className="text-[#B3261E]">⚡ Outage</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
