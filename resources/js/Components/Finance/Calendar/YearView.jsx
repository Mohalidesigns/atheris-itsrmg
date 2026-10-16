import { parseIso } from '@/Utils/finance';
import { useState } from 'react';
import { DayModal } from './MonthGrid';
import { MiniMonth } from './QuarterView';
import { groupByDay } from './calendarUtils';

/** Twelve mini months with marker dots and a count per month; selecting a month jumps to the month view. */
export default function YearView({ anchor, today, events, onPickMonth }) {
    const y = parseIso(anchor).getFullYear();
    const byDay = groupByDay(events);
    const [day, setDay] = useState(null);
    const counts = Array.from({ length: 12 }, (_, m) => events.filter((e) => { const d = parseIso(e.start); return d.getFullYear() === y && d.getMonth() === m; }).length);
    return (
        <div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {Array.from({ length: 12 }, (_, m) => (
                    <div key={m}>
                        <MiniMonth year={y} month={m} byDay={byDay} today={today} heat onDay={(k) => setDay(k)} />
                        <div className="mt-1 flex items-center justify-between px-1 text-xs text-[#718096]">
                            <span>{counts[m]} event{counts[m] === 1 ? '' : 's'}</span>
                            <button type="button" onClick={() => onPickMonth(y, m)} className="text-[#0A1F44] underline">Open month</button>
                        </div>
                    </div>
                ))}
            </div>
            <DayModal day={day} events={day ? byDay.get(day) ?? [] : []} onClose={() => setDay(null)} />
        </div>
    );
}
