import Chip, { TONES } from '@/Components/Material/Chip';
import { humanize, ratingFor } from '@/Utils/risk';

const ratingChip = {
    critical: ['error', 'keyboard_double_arrow_up'],
    high: ['warning', 'keyboard_arrow_up'],
    medium: ['tertiary', 'drag_handle'],
    low: ['success', 'keyboard_arrow_down'],
    very_low: ['neutral', 'keyboard_double_arrow_down'],
};

const statusChip = {
    identified: ['info', 'flag'],
    assessed: ['primary', 'analytics'],
    treating: ['warning', 'healing'],
    mitigated: ['success', 'shield'],
    accepted: ['success', 'check_circle'],
    under_review: ['tertiary', 'rate_review'],
    closed: ['muted', 'task_alt'],
    archived: ['muted', 'inventory_2'],
};

export function RatingBadge({ rating }) {
    if (!rating) return <span className="text-xs text-gray-400">Not assessed</span>;
    const [tone, icon] = ratingChip[rating] || ratingChip.low;
    return <Chip tone={tone} icon={icon}>{humanize(rating)}</Chip>;
}

export function StatusBadge({ status }) {
    if (!status) return null;
    const [tone, icon] = statusChip[status] || ['neutral', null];
    return <Chip tone={tone} icon={icon}>{humanize(status)}</Chip>;
}

export function ScoreDisplay({ score, size = 'md' }) {
    if (!score) return <span className="text-gray-400">--</span>;
    const tone = TONES[(ratingChip[ratingFor(score)] || ratingChip.low)[0]];
    const sizeClasses = size === 'lg' ? 'w-10 h-10 text-lg' : 'w-7 h-7 text-sm';

    return (
        <span className={`inline-flex items-center justify-center rounded-lg font-bold font-mono-data ${sizeClasses}`} style={{ backgroundColor: tone.container, color: tone.on }}>
            {score}
        </span>
    );
}

const treatmentChip = {
    draft: ['neutral', 'edit_note'],
    submitted: ['info', 'send'],
    approved: ['primary', 'verified'],
    rejected: ['error', 'cancel'],
    in_progress: ['info', 'timelapse'],
    completed: ['success', 'check_circle'],
    overdue: ['error', 'schedule'],
};

/** Treatment-plan status; `overdue` flags a still-open plan past its due date. */
export function TreatmentStatusBadge({ status, overdue = false }) {
    if (!status) return null;
    const [tone, icon] = treatmentChip[status] || treatmentChip.draft;
    return (
        <span className="inline-flex items-center gap-1">
            <Chip tone={tone} icon={icon}>{humanize(status)}</Chip>
            {overdue && status !== 'overdue' && <Chip tone="error" icon="schedule">Overdue</Chip>}
        </span>
    );
}
