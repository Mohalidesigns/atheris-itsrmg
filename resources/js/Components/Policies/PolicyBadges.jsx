const statusStyles = {
    draft: 'bg-gray-100 text-[#4A5568]',
    in_review: 'bg-[#FEF3C7] text-[#8A6400]',
    approved: 'bg-[#DBEAFE] text-[#1A365D]',
    active: 'bg-[#D1FAE5] text-[#2D7D46]',
    retired: 'bg-gray-200 text-[#718096]',
    requested: 'bg-[#FEF3C7] text-[#8A6400]',
    rejected: 'bg-[#FEE2E2] text-[#C53030]',
    revoked: 'bg-gray-200 text-[#718096]',
    expired: 'bg-[#FED7AA] text-[#C05621]',
    pending: 'bg-[#FEF3C7] text-[#8A6400]',
    completed: 'bg-[#D1FAE5] text-[#2D7D46]',
    waived: 'bg-gray-100 text-[#718096]',
    overdue: 'bg-[#FEE2E2] text-[#C53030]',
};

const labels = { in_review: 'In review', active: 'In force' };

export function PolicyStatus({ status }) {
    return <span className={`inline-flex rounded-lg px-2 py-0.5 text-xs font-semibold ${statusStyles[status] || statusStyles.draft} whitespace-nowrap`}>{labels[status] || (status || '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())}</span>;
}

export const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null);

/** True when an ISO date is before today, compared as calendar dates. */
export const isPast = (v) => {
    if (!v) return false;
    const now = new Date();
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    return String(v).slice(0, 10) < today;
};
