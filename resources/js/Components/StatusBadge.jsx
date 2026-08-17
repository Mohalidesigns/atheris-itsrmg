export default function StatusBadge({ status, label }) {
    const map = {
        pass: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        fail: 'bg-[#B3261E]/10 text-[#B3261E] border-[#B3261E]/30',
        warn: 'bg-[#E5A100]/10 text-[#8A6400] border-[#E5A100]/30',
        error: 'bg-gray-100 text-gray-700 border-gray-300',
        enabled: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        disabled: 'bg-gray-100 text-gray-600 border-gray-300',
        active: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        paused: 'bg-[#E5A100]/10 text-[#8A6400] border-[#E5A100]/30',
        draft: 'bg-gray-100 text-gray-700 border-gray-300',
        in_review: 'bg-[#C9A86A]/15 text-[#8A6400] border-[#C9A86A]/40',
        approved: 'bg-[#0A1F44]/10 text-[#0A1F44] border-[#0A1F44]/30',
        signed: 'bg-[#0A1F44]/10 text-[#0A1F44] border-[#0A1F44]/30',
        delivered: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        acknowledged: 'bg-[#2D7D46]/20 text-[#2D7D46] border-[#2D7D46]/40',
        critical: 'bg-[#B3261E]/10 text-[#B3261E] border-[#B3261E]/30',
        high: 'bg-[#E5A100]/10 text-[#8A6400] border-[#E5A100]/30',
        moderate: 'bg-[#1D4ED8]/10 text-[#1D4ED8] border-[#1D4ED8]/30',
        low: 'bg-gray-100 text-gray-700 border-gray-300',
        open: 'bg-[#B3261E]/10 text-[#B3261E] border-[#B3261E]/30',
        in_progress: 'bg-[#1D4ED8]/10 text-[#1D4ED8] border-[#1D4ED8]/30',
        blocked: 'bg-[#E5A100]/10 text-[#8A6400] border-[#E5A100]/30',
        remediated: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        verified: 'bg-[#2D7D46]/20 text-[#2D7D46] border-[#2D7D46]/40',
        closed: 'bg-gray-100 text-gray-600 border-gray-300',
        escalated: 'bg-[#B3261E]/10 text-[#B3261E] border-[#B3261E]/30',
        green: 'bg-[#2D7D46]/10 text-[#2D7D46] border-[#2D7D46]/30',
        amber: 'bg-[#E5A100]/10 text-[#8A6400] border-[#E5A100]/30',
        red: 'bg-[#B3261E]/10 text-[#B3261E] border-[#B3261E]/30',
    };
    const klass = map[status] || 'bg-gray-100 text-gray-700 border-gray-300';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium border ${klass}`}>
            {label || status}
        </span>
    );
}
