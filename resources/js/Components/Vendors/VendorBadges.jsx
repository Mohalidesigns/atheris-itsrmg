const tierStyles = {
    1: 'bg-[#B3261E] text-white',
    2: 'bg-[#E5A100] text-white',
    3: 'bg-gray-200 text-[#2D3748]',
};

const gradeStyles = {
    A: 'bg-[#D1FAE5] text-[#2D7D46]',
    B: 'bg-[#E6F4EA] text-[#2D7D46]',
    C: 'bg-[#FEF3C7] text-[#8A6400]',
    D: 'bg-[#FED7AA] text-[#C05621]',
    F: 'bg-[#FEE2E2] text-[#C53030]',
};

export function TierBadge({ tier }) {
    if (!tier) return <span className="text-xs text-[#718096]">Untiered</span>;
    return <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold ${tierStyles[tier]}`}>TIER {tier}</span>;
}

export function GradeBadge({ grade, value }) {
    if (!grade) return <span className="text-xs text-[#718096]">Not rated</span>;
    return (
        <span className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-bold ${gradeStyles[grade] || gradeStyles.C}`}>
            {grade}{value != null && <span className="font-normal opacity-80">{value}</span>}
        </span>
    );
}

/** A tiny inline trend line for a list of 0-100 scores. */
export function Sparkline({ values = [], width = 80, height = 20 }) {
    if (values.length < 2) return null;
    const min = Math.min(...values) - 2;
    const max = Math.max(...values) + 2;
    const pts = values.map((v, i) => `${(i / (values.length - 1)) * width},${height - ((v - min) / (max - min || 1)) * height}`).join(' ');
    const falling = values[values.length - 1] < values[0];
    return (
        <svg width={width} height={height} className="inline-block" aria-hidden="true">
            <polyline points={pts} fill="none" stroke={falling ? '#B3261E' : '#2D7D46'} strokeWidth="1.5" />
        </svg>
    );
}

export const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null);

/** True when an ISO date (YYYY-MM-DD…) is before today, compared as calendar dates. */
export const isPast = (v) => {
    if (!v) return false;
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    return String(v).slice(0, 10) < today;
};
