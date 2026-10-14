import Chip from '@/Components/Material/Chip';

const tierChip = { 1: ['error', 'counter_1'], 2: ['warning', 'counter_2'], 3: ['neutral', 'counter_3'] };
const gradeTone = { A: 'success', B: 'success', C: 'warning', D: 'warning', F: 'error' };

export function TierBadge({ tier }) {
    if (!tier) return <span className="text-xs text-[#718096]">Untiered</span>;
    const [tone, icon] = tierChip[tier] || tierChip[3];
    return <Chip tone={tone} icon={icon} size="xs">Tier {tier}</Chip>;
}

export function GradeBadge({ grade, value }) {
    if (!grade) return <span className="text-xs text-[#718096]">Not rated</span>;
    return (
        <Chip tone={gradeTone[grade] || 'warning'} icon="grade">
            <span className="font-bold">{grade}</span>{value != null && <span className="ml-1 font-normal opacity-80">{value}</span>}
        </Chip>
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
