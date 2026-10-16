import Modal from '@/Components/Modal';
import { CAL_COLORS, isoDate } from '@/Utils/finance';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { groupByDay, longDay, monthWeeks, weekdayLabels } from './calendarUtils';

export function EventChip({ event, compact = false }) {
    const col = CAL_COLORS[event.color] ?? CAL_COLORS.grey;
    return (
        <Link href={event.href || '#'} title={event.title} className={`block truncate rounded-md border-l-[3px] px-1.5 py-0.5 text-[11px] leading-4 ${col.chip} ${compact ? '' : 'hover:brightness-95'}`}>{event.title}</Link>
    );
}

/** All events of one day, opened from "+N more", a mobile day tap or a quarter / year cell. */
export function DayModal({ day, events, onClose }) {
    return (
        <Modal show={!!day} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h3 className="mb-3 text-sm font-semibold text-[#0A1F44]">{day ? longDay(day) : ''}</h3>
                {events.length === 0 ? <p className="text-sm text-[#718096]">Nothing scheduled.</p> : (
                    <ul className="space-y-1.5">{events.map((e) => <li key={e.id}><EventChip event={e} compact /></li>)}</ul>
                )}
                <div className="mt-4 text-right"><button type="button" onClick={onClose} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Close</button></div>
            </div>
        </Modal>
    );
}

/** Monday-first month grid. Up to 3 chips per day on sm and up; dots plus a day sheet on phones. */
export default function MonthGrid({ anchor, today, events }) {
    const [day, setDay] = useState(null);
    const byDay = groupByDay(events);
    const weeks = monthWeeks(anchor);
    const month = new Date(`${anchor}T00:00:00`).getMonth();
    const labels = weekdayLabels();
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="grid grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-[11px] font-medium uppercase tracking-wide text-[#718096]">
                {labels.map((l) => <div key={l} className="py-1.5">{l}</div>)}
            </div>
            {weeks.map((w, wi) => (
                <div key={wi} className="grid grid-cols-7 border-b border-gray-100 last:border-0">
                    {w.map((d) => {
                        const k = isoDate(d);
                        const items = byDay.get(k) ?? [];
                        const out = d.getMonth() !== month;
                        const isToday = k === today;
                        return (
                            <div key={k} className={`min-h-[3.5rem] border-r border-gray-100 p-1 last:border-0 sm:min-h-[6.5rem] ${out ? 'bg-gray-50/70' : ''}`}>
                                <button type="button" onClick={() => items.length && setDay(k)} aria-label={`${longDay(k)}, ${items.length} event${items.length === 1 ? '' : 's'}`}
                                    className="flex w-full flex-col items-start gap-1 text-left sm:pointer-events-none">
                                    <span className={`flex h-5 min-w-[1.25rem] items-center justify-center rounded-md px-1 text-[11px] ${isToday ? 'bg-[#0A1F44] font-semibold text-white' : out ? 'text-[#A0A4AB]' : 'text-[#2D3748]'}`}>{d.getDate()}</span>
                                    <span className="flex flex-wrap gap-0.5 sm:hidden">
                                        {items.slice(0, 4).map((e) => <span key={e.id} className={`h-1.5 w-1.5 rounded-sm ${(CAL_COLORS[e.color] ?? CAL_COLORS.grey).dot}`} />)}
                                    </span>
                                </button>
                                <div className="hidden space-y-0.5 sm:block">
                                    {items.slice(0, 3).map((e) => <EventChip key={e.id} event={e} />)}
                                    {items.length > 3 && <button type="button" onClick={() => setDay(k)} className="px-1 text-[11px] text-[#0A1F44] underline">+{items.length - 3} more</button>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ))}
            <DayModal day={day} events={day ? byDay.get(day) ?? [] : []} onClose={() => setDay(null)} />
        </div>
    );
}
