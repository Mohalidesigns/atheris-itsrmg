import { CAL_COLORS, diffDays, isoDate, localDate, parseIso } from '@/Utils/finance';
import { Link } from '@inertiajs/react';
import { monthName } from './calendarUtils';

/** One row per contract: bar from start to end clipped to the range, amber tick at window open, red tick at notice deadline. */
export default function TimelineGantt({ from, to, today, rows }) {
    const total = Math.max(1, diffDays(to, from) + 1);
    const pos = (iso) => ((diffDays(iso, from)) / total) * 100;
    const months = [];
    const f = parseIso(from); const t = parseIso(to);
    for (let d = new Date(f.getFullYear(), f.getMonth(), 1); d <= t; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) {
        const s = isoDate(d) < from ? from : isoDate(d);
        const next = new Date(d.getFullYear(), d.getMonth() + 1, 1);
        const e = isoDate(next) > to ? to : isoDate(new Date(next.getTime() - 86400000));
        months.push({ label: `${monthName(d.getFullYear(), d.getMonth(), 'short')} ${String(d.getFullYear()).slice(2)}`, left: pos(s), width: ((diffDays(e, s) + 1) / total) * 100 });
    }
    const todayIn = today >= from && today <= to;
    const clip = (v) => Math.max(0, Math.min(100, v));
    if (rows.length === 0) return <p className="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center text-sm text-[#718096]">No contracts fall in this range.</p>;
    return (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <div className="min-w-[900px]">
                <div className="flex border-b border-gray-200 bg-gray-50 text-[11px] font-medium text-[#718096]">
                    <div className="w-56 shrink-0 px-3 py-1.5">Contract</div>
                    <div className="relative h-7 flex-1">{months.map((m) => <span key={m.label} className="absolute top-0 flex h-full items-center border-l border-gray-200 pl-1" style={{ left: `${m.left}%`, width: `${m.width}%` }}>{m.label}</span>)}</div>
                </div>
                {rows.map((r) => {
                    const left = clip(pos(r.start)); const right = clip(pos(r.end) + 100 / total);
                    const col = CAL_COLORS[r.color] ?? CAL_COLORS.grey;
                    return (
                        <div key={r.id} className="flex items-center border-b border-gray-50 last:border-0 hover:bg-gray-50/60">
                            <div className="w-56 shrink-0 truncate px-3 py-2 text-xs"><Link href={r.href} className="text-[#0A1F44] underline" title={`${r.contract_code} ${r.title}`}>{r.contract_code}</Link> <span className="text-[#4A5568]">{r.title}</span><span className="block truncate text-[11px] text-[#718096]">{r.vendor ?? ''}</span>{(r.approved_unexecuted || r.status === 'approved_unexecuted') && <span className="block truncate text-[11px] font-semibold text-[#B3261E]">Approved renewal not executed</span>}</div>
                            <div className="relative h-9 flex-1">
                                {todayIn && <span className="absolute inset-y-0 w-px bg-[#0A1F44]/60" style={{ left: `${pos(today)}%` }} />}
                                <span className="absolute top-1/2 h-3 -translate-y-1/2 rounded-md opacity-90" style={{ left: `${left}%`, width: `${Math.max(0.6, right - left)}%`, backgroundColor: col.bar }} title={`${localDate(r.start)} to ${localDate(r.end)} · ${r.status === 'approved_unexecuted' ? 'approved renewal not executed' : r.status}`} />
                                {r.window_opens_on && r.window_opens_on >= from && r.window_opens_on <= to && <span className="absolute top-1/2 h-5 w-1 -translate-y-1/2 rounded-sm bg-[#E5A100] ring-1 ring-white" style={{ left: `${pos(r.window_opens_on)}%` }} title={`Window opens ${localDate(r.window_opens_on)}`} />}
                                {r.notice_deadline_on && r.notice_deadline_on >= from && r.notice_deadline_on <= to && <span className="absolute top-1/2 h-5 w-1 -translate-y-1/2 rounded-sm bg-[#B3261E] ring-1 ring-white" style={{ left: `${pos(r.notice_deadline_on)}%` }} title={`Notice deadline ${localDate(r.notice_deadline_on)}`} />}
                            </div>
                        </div>
                    );
                })}
            </div>
            <p className="flex flex-wrap gap-4 border-t border-gray-100 px-3 py-2 text-[11px] text-[#718096]"><span><span className="mr-1 inline-block h-2.5 w-1 bg-[#E5A100]" />Window opens</span><span><span className="mr-1 inline-block h-2.5 w-1 bg-[#B3261E]" />Notice deadline</span><span><span className="mr-1 inline-block h-2.5 w-px bg-[#0A1F44]" />Today</span></p>
        </div>
    );
}
