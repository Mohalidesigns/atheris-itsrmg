import { CAL_COLORS, addDays, isoDate, parseIso } from '@/Utils/finance';
import { useState } from 'react';
import { DayModal, EventChip } from './MonthGrid';
import { groupByDay, monthName, monthWeeks, weekdayLabels } from './calendarUtils';

export function MiniMonth({ year, month, byDay, today, onDay, heat = false }) {
    const anchor = isoDate(new Date(year, month, 1));
    const weeks = monthWeeks(anchor);
    const labels = weekdayLabels('narrow');
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-3">
            <p className="mb-1 text-sm font-semibold text-[#0A1F44]">{monthName(year, month)}</p>
            <div className="grid grid-cols-7 gap-px text-center text-[10px] text-[#718096]">{labels.map((l, i) => <span key={i}>{l}</span>)}</div>
            {weeks.map((w, i) => (
                <div key={i} className="grid grid-cols-7 gap-px">
                    {w.map((d) => {
                        const k = isoDate(d);
                        if (d.getMonth() !== month) return <span key={k} />;
                        const items = byDay.get(k) ?? [];
                        const top = items.length ? CAL_COLORS[items.find((e) => e.color === 'red')?.color ?? items.find((e) => e.color === 'amber')?.color ?? items[0].color] ?? CAL_COLORS.grey : null;
                        return (
                            <button key={k} type="button" disabled={!items.length && !onDay} onClick={() => onDay?.(k, items)} aria-label={`${k}${items.length ? `, ${items.length} events` : ''}`}
                                className={`relative flex h-7 flex-col items-center justify-center rounded-md text-[11px] ${k === today ? 'bg-[#0A1F44] text-white' : 'text-[#2D3748] hover:bg-gray-100'}`}>
                                {d.getDate()}
                                {top && <span className={`absolute bottom-0.5 h-1 w-1 rounded-sm ${heat && items.length > 2 ? 'w-3' : ''} ${top.dot}`} />}
                            </button>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

/** Three mini months with markers, then the quarter's events grouped by week. */
export default function QuarterView({ anchor, today, events }) {
    const a = parseIso(anchor);
    const q0 = Math.floor(a.getMonth() / 3) * 3;
    const byDay = groupByDay(events);
    const [day, setDay] = useState(null);
    const start = new Date(a.getFullYear(), q0, 1);
    const end = new Date(a.getFullYear(), q0 + 3, 0);
    const inQ = events.filter((e) => { const d = parseIso(e.start); return d >= start && d <= end; }).sort((x, y) => String(x.start).localeCompare(String(y.start)));
    const weeks = new Map();
    inQ.forEach((e) => {
        const d = parseIso(e.start);
        const mon = addDays(d, -((d.getDay() + 6) % 7));
        const k = isoDate(mon);
        if (!weeks.has(k)) weeks.set(k, []);
        weeks.get(k).push(e);
    });
    return (
        <div className="space-y-4">
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                {[0, 1, 2].map((i) => <MiniMonth key={i} year={a.getFullYear()} month={q0 + i} byDay={byDay} today={today} onDay={(k) => setDay(k)} />)}
            </div>
            <div className="rounded-lg border border-gray-200 bg-white">
                <h3 className="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-[#0A1F44]">Events this quarter ({inQ.length})</h3>
                {inQ.length === 0 ? <p className="px-5 py-8 text-center text-sm text-[#718096]">Nothing scheduled this quarter.</p> : [...weeks.entries()].map(([mon, list]) => (
                    <div key={mon} className="border-b border-gray-50 px-5 py-2 last:border-0">
                        <p className="mb-1 text-xs font-medium text-[#718096]">Week of {parseIso(mon).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</p>
                        <ul className="space-y-1">{list.map((e) => (
                            <li key={e.id} className="flex items-center gap-2 text-sm"><span className="w-14 shrink-0 text-xs tabular-nums text-[#718096]">{parseIso(e.start).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</span><div className="min-w-0 flex-1"><EventChip event={e} /></div></li>
                        ))}</ul>
                    </div>
                ))}
            </div>
            <DayModal day={day} events={day ? byDay.get(day) ?? [] : []} onClose={() => setDay(null)} />
        </div>
    );
}
