const ratingConfig = {
    critical: { bg: 'bg-[#C53030]/10', text: 'text-[#C53030]', border: 'border-[#C53030]/20' },
    high: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]', border: 'border-[#DD6B20]/20' },
    medium: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]', border: 'border-[#D4AF37]/20' },
    low: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]', border: 'border-[#2D7D46]/20' },
    very_low: { bg: 'bg-[#319795]/10', text: 'text-[#319795]', border: 'border-[#319795]/20' },
};

const statusConfig = {
    identified: { bg: 'bg-blue-50', text: 'text-blue-700' },
    assessed: { bg: 'bg-purple-50', text: 'text-purple-700' },
    treating: { bg: 'bg-amber-50', text: 'text-amber-700' },
    accepted: { bg: 'bg-green-50', text: 'text-green-700' },
    closed: { bg: 'bg-gray-50', text: 'text-gray-500' },
    archived: { bg: 'bg-gray-50', text: 'text-gray-400' },
};

export function RatingBadge({ rating }) {
    if (!rating) return <span className="text-xs text-gray-400">Not assessed</span>;
    const config = ratingConfig[rating] || ratingConfig.low;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border ${config.bg} ${config.text} ${config.border}`}>
            {rating.replace('_', ' ').toUpperCase()}
        </span>
    );
}

export function StatusBadge({ status }) {
    if (!status) return null;
    const config = statusConfig[status] || statusConfig.identified;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
}

export function ScoreDisplay({ score, size = 'md' }) {
    if (!score) return <span className="text-gray-400">--</span>;
    const rating = score >= 20 ? 'critical' : score >= 15 ? 'high' : score >= 8 ? 'medium' : score >= 4 ? 'low' : 'very_low';
    const config = ratingConfig[rating];
    const sizeClasses = size === 'lg' ? 'w-10 h-10 text-lg' : 'w-7 h-7 text-sm';

    return (
        <span className={`inline-flex items-center justify-center rounded-lg font-bold font-mono-data ${sizeClasses} ${config.bg} ${config.text}`}>
            {score}
        </span>
    );
}
