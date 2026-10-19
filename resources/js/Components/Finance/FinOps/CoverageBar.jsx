/** Allocated share of a source with the retained remainder; `pct` is the allocated percentage (0-100+). */
export default function CoverageBar({ pct, label }) {
    const n = Math.max(0, Number(pct) || 0);
    const over = n > 100.0001;
    const w = Math.min(100, n);
    return (
        <div className="min-w-[8rem]" role="img" aria-label={`${label ?? 'Coverage'} ${n.toFixed(1)}% allocated`}>
            <div className="flex h-2 overflow-hidden rounded bg-gray-100">
                <div style={{ width: `${w}%`, backgroundColor: over ? '#B3261E' : '#0A1F44' }} />
            </div>
            <p className="mt-0.5 text-[11px] text-[#718096]">{n.toFixed(1)}% allocated{!over && n < 100 ? `, ${(100 - n).toFixed(1)}% retained` : ''}</p>
        </div>
    );
}
