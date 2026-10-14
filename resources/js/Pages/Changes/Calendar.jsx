import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import Icon from '@/Components/Material/Icon';
import Chip, { TONES } from '@/Components/Material/Chip';
import { formatDate, humanize } from '@/Utils/risk';
import { RISK_TONE, windowLabel } from '@/Utils/changes';
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

            <div className="mb-3 flex flex-wrap items-center gap-2 rounded-2xl bg-white p-2 shadow-sm ring-1 ring-black/5">
                <button type="button" onClick={mode === 'week' ? thisWeek : thisMonth} className="h-9 rounded-full px-4 text-sm font-medium text-[#0A1F44] ring-1 ring-[#74777F]/50 hover:bg-[#0A1F44]/[0.06]">Today</button>
                <div className="flex">
                    <IconButton icon="chevron_left" label={`Previous ${mode}`} onClick={() => shift(-1)} />
                    <IconButton icon="chevron_right" label={`Next ${mode}`} onClick={() => shift(1)} />
                </div>
                <span className="text-base font-medium text-[#1B1B1F]">{formatDate(from)} – {formatDate(to)}</span>
                <div role="radiogroup" aria-label="Calendar view" className="ml-2 flex h-9 overflow-hidden rounded-full ring-1 ring-[#74777F]/50">
                    {[['week', 'Week', thisWeek], ['month', 'Month', thisMonth]].map(([k, l, fn], idx) => (
                        <button key={k} type="button" role="radio" aria-checked={mode === k} onClick={fn}
                            className={`flex items-center gap-1 px-4 text-sm font-medium ${idx ? 'border-l border-[#74777F]/50' : ''} ${mode === k ? 'bg-[#DDE3F0] text-[#0A1F44]' : 'text-[#43474E] hover:bg-[#0A1F44]/[0.06]'}`}>
                            {mode === k && <Icon name="check" size={18} />}{l}
                        </button>
                    ))}
                </div>
                <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="h-9 rounded-lg border-[#74777F]/50 py-0 text-sm">
                    <option value="">All types</option>
                    {['standard', 'normal', 'emergency'].map((t) => <option key={t} value={t}>{humanize(t)}</option>)}
                </select>
                <div className="ml-auto flex flex-wrap items-center gap-x-3 gap-y-1 px-2 text-xs text-[#43474E]">
                    {Object.entries(RISK_TONE).map(([k, t]) => <span key={k} className="flex items-center gap-1"><span className="h-3 w-3 rounded" style={{ backgroundColor: TONES[t].container, boxShadow: `inset 2px 0 0 ${TONES[t].rail}` }} />{humanize(k)}</span>)}
                    <span className="flex items-center gap-1"><span className="h-3 w-3 rounded bg-[repeating-linear-gradient(135deg,#F9DEDC_0_3px,#fff_3px_6px)] ring-1 ring-[#B3261E]/30" />Freeze</span>
                    <span className="flex items-center gap-0.5"><Icon name="bolt" size={16} fill className="text-[#B3261E]" />Emergency</span>
                    <span className="flex items-center gap-0.5"><Icon name="power_off" size={16} className="text-[#43474E]" />Outage</span>
                    <span className="flex items-center gap-0.5"><Icon name="warning" size={16} fill className="text-[#E5A100]" />Conflict</span>
                </div>
            </div>

            <div className="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-black/5">
                <div className="grid min-w-[760px] grid-cols-7">
                    {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="border-b border-[#E3E4E8] px-2 py-2 text-center text-[11px] font-medium uppercase tracking-wider text-[#74777F]">{d}</div>)}
                    {cells.map((d, idx) => {
                        const key = ymd(d);
                        const inRange = d >= start && d <= end;
                        const items = inRange ? dayItems(d) : [];
                        const frozen = inRange ? dayFreeze(d) : [];
                        const limit = mode === 'week' ? 12 : 3;
                        const isToday = key === today;
                        return (
                            <div key={key} className={`relative border-[#E3E4E8] p-1.5 ${idx % 7 ? 'border-l' : ''} ${idx >= 7 ? 'border-t' : ''} ${mode === 'week' ? 'min-h-[240px]' : 'min-h-[112px]'} ${!inRange ? 'bg-[#F7F8FA]' : frozen.length ? 'bg-[repeating-linear-gradient(135deg,#FDF1F0_0_6px,#fff_6px_12px)]' : ''}`}
                                title={frozen.map((w) => `Freeze: ${w.name}`).join('\n')}>
                                <div className="mb-1 flex items-center justify-between gap-1">
                                    <span className={`flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-medium ${isToday ? 'bg-[#0A1F44] text-white' : inRange ? 'text-[#1B1B1F]' : 'text-[#B0B3BA]'}`}>
                                        {d.getDate()}{d.getDate() === 1 || key === from ? ` ${d.toLocaleString('en-GB', { month: 'short' })}` : ''}
                                    </span>
                                    {frozen.length > 0 && <Chip tone="error" icon="ac_unit" size="xs" title={frozen.map((w) => w.name).join(', ')}>{frozen[0].scope === 'all' ? 'Freeze' : 'Partial'}</Chip>}
                                </div>
                                <div className="space-y-1">
                                    {items.slice(0, limit).map((c) => <CalendarEvent key={c.id} change={c} conflict={conflictSet.has(c.id)} />)}
                                    {items.length > limit && <p className="px-1 text-[11px] font-medium text-[#43474E]">+{items.length - limit} more</p>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {freezeWindows.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2 text-[11px]">
                    {freezeWindows.map((w) => (
                        <Chip key={w.id} tone="error" icon="ac_unit" variant="outlined">
                            {w.name}: {formatDate(w.starts_at)} – {formatDate(w.ends_at)} · {w.scope === 'all' ? 'all changes' : (w.categories || []).map(humanize).join(', ')}{w.allow_emergency ? ' · emergencies allowed' : ''}
                        </Chip>
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
                                        <span className="flex flex-wrap gap-1">
                                            {conflictSet.has(c.id) && <Chip tone="warning" icon="warning">Conflict</Chip>}
                                            {c.outage_required && <Chip tone="neutral" icon="power_off" iconFill={false}>Outage</Chip>}
                                        </span>
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

function IconButton({ icon, label, onClick }) {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label}
            className="flex h-9 w-9 items-center justify-center rounded-full text-[#43474E] hover:bg-[#0A1F44]/[0.08] focus-visible:bg-[#0A1F44]/[0.12] focus-visible:outline-none">
            <Icon name={icon} size={22} />
        </button>
    );
}

function CalendarEvent({ change: c, conflict }) {
    const tone = TONES[RISK_TONE[c.risk_level]] || TONES.neutral;
    const time = new Date(c.scheduled_start_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    return (
        <Link href={route('changes.show', c.id)}
            className="block rounded-md py-1 pl-2 pr-1 text-[11px] leading-tight transition hover:shadow-md hover:brightness-[0.97]"
            style={{ backgroundColor: tone.container, color: tone.on, boxShadow: `inset 3px 0 0 ${tone.rail}` }}
            title={`${c.change_code} ${c.title}\n${windowLabel(c.scheduled_start_at, c.scheduled_end_at)}\n${humanize(c.status)} · ${humanize(c.change_type)} · ${humanize(c.risk_level)} risk`}>
            <span className="flex items-center gap-1">
                <span className="font-semibold tabular-nums">{time}</span>
                <span className="font-mono text-[10px] opacity-70">{c.change_code.split('-').pop()}</span>
                <span className="ml-auto flex items-center">
                    {c.change_type === 'emergency' && <Icon name="bolt" size={14} fill label="Emergency change" />}
                    {c.outage_required && <Icon name="power_off" size={14} label="Outage required" />}
                    {conflict && <Icon name="warning" size={14} fill label="Schedule conflict" className="text-[#B07800]" />}
                </span>
            </span>
            <span className="mt-0.5 block truncate">{c.title}</span>
        </Link>
    );
}
