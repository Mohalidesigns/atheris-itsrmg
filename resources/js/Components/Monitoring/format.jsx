// Shared formatting for the Continuous Monitoring pages (ATH-VAL-013).
export const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
export const fmtDateTime = (v) => (v ? new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

export const statusTone = {
    pass: 'bg-[#D1FAE5] text-[#2D7D46]', warn: 'bg-[#FEF3C7] text-[#8A6400]', fail: 'bg-[#FEE2E2] text-[#C53030]', error: 'bg-gray-100 text-[#4A5568]',
    open: 'bg-[#FEE2E2] text-[#C53030]', investigating: 'bg-[#FEF3C7] text-[#8A6400]', authorised: 'bg-[#DBEAFE] text-[#1E40AF]',
    remediated: 'bg-[#D1FAE5] text-[#2D7D46]', accepted: 'bg-gray-100 text-[#4A5568]',
    in_progress: 'bg-[#DBEAFE] text-[#1E40AF]', completed: 'bg-[#D1FAE5] text-[#2D7D46]', overdue: 'bg-[#FEE2E2] text-[#C53030]',
    enabled: 'bg-[#D1FAE5] text-[#2D7D46]', paused: 'bg-[#FEF3C7] text-[#8A6400]', disabled: 'bg-gray-100 text-[#4A5568]',
    keep: 'bg-[#D1FAE5] text-[#2D7D46]', revoke: 'bg-[#FEE2E2] text-[#C53030]', modify: 'bg-[#FEF3C7] text-[#8A6400]',
};
export const severityTone = { critical: 'bg-[#7F1D1D] text-white', high: 'bg-[#FEE2E2] text-[#C53030]', medium: 'bg-[#FEF3C7] text-[#8A6400]', low: 'bg-gray-100 text-[#4A5568]' };

export function Pill({ value, tones = statusTone, label }) {
    if (!value) return <span className="text-[#A0AEC0]">—</span>;
    return <span className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-medium capitalize ${tones[value] || 'bg-gray-100 text-[#4A5568]'}`}>{label || String(value).replace(/_/g, ' ')}</span>;
}

export function ModeTag({ mode }) {
    return mode === 'platform'
        ? <span className="rounded bg-[#0A1F44]/10 px-1.5 py-0.5 text-[10px] font-medium text-[#0A1F44]" title="Measured from the bank's own records in Atheris">platform data</span>
        : <span className="rounded bg-[#C9A86A]/20 px-1.5 py-0.5 text-[10px] font-medium text-[#7A5C1E]" title="No live connector: measured by the sandbox adapter">sandbox</span>;
}
