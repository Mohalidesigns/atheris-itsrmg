import Icon from '@/Components/Material/Icon';
import StatusBadge from '@/Components/StatusBadge';

export const SEVERITY_LABELS = { critical: 'Critical', high: 'High', moderate: 'Medium', low: 'Low' };
export const SEVERITIES = ['critical', 'high', 'moderate', 'low'];

/** "moderate" is stored, "Medium" is shown. */
export const severityLabel = (s, config) => (config?.severityLabels || SEVERITY_LABELS)[s] || s;

export function SeverityBadge({ severity, config, size }) {
    return <StatusBadge status={severity === 'moderate' ? 'medium' : severity} label={severityLabel(severity, config)} size={size} />;
}

export const inputCls = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-[#718096]';
export const labelCls = 'block text-xs font-medium text-[#4A5568]';
export const primaryBtn = 'inline-flex items-center gap-1 rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90 disabled:cursor-not-allowed disabled:opacity-50';
export const secondaryBtn = 'inline-flex items-center gap-1 rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5 disabled:cursor-not-allowed disabled:opacity-50';
export const dangerBtn = 'inline-flex items-center gap-1 rounded-lg border border-[#B3261E]/30 px-3 py-2 text-sm text-[#B3261E] hover:bg-[#B3261E]/5 disabled:cursor-not-allowed disabled:opacity-50';

export function Card({ title, icon, aside, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5 ${className}`}>
            {(title || aside) && (
                <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="flex items-center gap-1.5 text-sm font-semibold text-[#2D3748]">
                        {icon && <Icon name={icon} size={18} className="text-[#C9A86A]" fill />}
                        {title}
                    </h3>
                    {aside}
                </header>
            )}
            {children}
        </section>
    );
}

export function Note({ tone = 'warning', icon, children }) {
    const tones = {
        warning: 'bg-[#E5A100]/10 text-[#8A6400]',
        error: 'bg-[#B3261E]/10 text-[#8C1D18]',
        info: 'bg-[#0A1F44]/5 text-[#0A1F44]',
        success: 'bg-[#2D7D46]/10 text-[#1B5530]',
    };
    return (
        <div className={`flex items-start gap-2 rounded-lg p-3 text-xs ${tones[tone]}`}>
            {icon && <Icon name={icon} size={16} className="mt-px" fill />}
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

/** Whole-day countdown to an ISO date. Returns { days, hours, past, label }. */
export function countdown(iso, now = Date.now()) {
    if (!iso) return null;
    const ms = new Date(iso).getTime() - now;
    if (Number.isNaN(ms)) return null;
    const past = ms < 0;
    const abs = Math.abs(ms);
    const days = Math.floor(abs / 86400000);
    const hours = Math.floor((abs % 86400000) / 3600000);
    const span = days > 0 ? `${days}d ${hours}h` : `${hours}h ${Math.floor((abs % 3600000) / 60000)}m`;
    return { days, hours, past, label: past ? `${span} overdue` : `${span} left` };
}

export const RACI_TONE = {
    A: 'bg-[#0A1F44] text-white',
    R: 'bg-[#C9A86A] text-[#0A1F44]',
    C: 'bg-[#DFE6FB] text-[#1E3A8A]',
    I: 'bg-[#E9EBEF] text-[#43474E]',
};
export const RACI_WORD = { A: 'Accountable', R: 'Responsible', C: 'Consulted', I: 'Informed' };
