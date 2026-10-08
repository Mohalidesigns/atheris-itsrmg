import { humanize, ratingFor } from '@/Utils/risk';

const ratingConfig = {
    critical: { bg: 'bg-[#C53030]/10', text: 'text-[#C53030]', border: 'border-[#C53030]/20' },
    high: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]', border: 'border-[#DD6B20]/20' },
    medium: { bg: 'bg-[#D4AF37]/10', text: 'text-[#B7950B]', border: 'border-[#D4AF37]/20' },
    low: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]', border: 'border-[#2D7D46]/20' },
    very_low: { bg: 'bg-[#319795]/10', text: 'text-[#319795]', border: 'border-[#319795]/20' },
};

const statusConfig = {
    identified: { bg: 'bg-blue-50', text: 'text-blue-700' },
    assessed: { bg: 'bg-purple-50', text: 'text-purple-700' },
    treating: { bg: 'bg-amber-50', text: 'text-amber-700' },
    mitigated: { bg: 'bg-teal-50', text: 'text-teal-700' },
    accepted: { bg: 'bg-green-50', text: 'text-green-700' },
    under_review: { bg: 'bg-indigo-50', text: 'text-indigo-700' },
    closed: { bg: 'bg-gray-100', text: 'text-gray-600' },
    archived: { bg: 'bg-gray-50', text: 'text-gray-400' },
};

export function RatingBadge({ rating }) {
    if (!rating) return <span className="text-xs text-gray-400">Not assessed</span>;
    const config = ratingConfig[rating] || ratingConfig.low;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border ${config.bg} ${config.text} ${config.border}`}>
            {humanize(rating).toUpperCase()}
        </span>
    );
}

export function StatusBadge({ status }) {
    if (!status) return null;
    const config = statusConfig[status] || { bg: 'bg-gray-50', text: 'text-gray-600' };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${config.bg} ${config.text}`}>
            {humanize(status)}
        </span>
    );
}

export function ScoreDisplay({ score, size = 'md' }) {
    if (!score) return <span className="text-gray-400">--</span>;
    const config = ratingConfig[ratingFor(score)];
    const sizeClasses = size === 'lg' ? 'w-10 h-10 text-lg' : 'w-7 h-7 text-sm';

    return (
        <span className={`inline-flex items-center justify-center rounded-lg font-bold font-mono-data ${sizeClasses} ${config.bg} ${config.text}`}>
            {score}
        </span>
    );
}

const treatmentStatusConfig = {
    draft: { bg: 'bg-gray-100', text: 'text-gray-600' },
    submitted: { bg: 'bg-indigo-50', text: 'text-indigo-700' },
    approved: { bg: 'bg-blue-50', text: 'text-blue-700' },
    rejected: { bg: 'bg-red-50', text: 'text-red-700' },
    in_progress: { bg: 'bg-amber-50', text: 'text-amber-700' },
    completed: { bg: 'bg-green-50', text: 'text-green-700' },
    overdue: { bg: 'bg-red-50', text: 'text-red-700' },
};

/** Treatment-plan status; `overdue` flags a still-open plan past its due date. */
export function TreatmentStatusBadge({ status, overdue = false }) {
    if (!status) return null;
    const config = treatmentStatusConfig[status] || treatmentStatusConfig.draft;
    return (
        <span className="inline-flex items-center gap-1">
            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${config.bg} ${config.text}`}>
                {humanize(status)}
            </span>
            {overdue && status !== 'overdue' && (
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700">Overdue</span>
            )}
        </span>
    );
}
