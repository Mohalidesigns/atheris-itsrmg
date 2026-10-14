import Chip from '@/Components/Material/Chip';

// Shared formatting for the Continuous Monitoring pages (ATH-VAL-013).
export const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
export const fmtDateTime = (v) => (v ? new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

// value → [Material tone, symbol] (Components/Material/Chip).
export const statusTone = {
    pass: ['success', 'check_circle'], warn: ['warning', 'warning'], fail: ['error', 'cancel'], error: ['neutral', 'error'],
    open: ['error', 'radio_button_unchecked'], investigating: ['warning', 'search'], authorised: ['info', 'verified'],
    remediated: ['success', 'check_circle'], accepted: ['neutral', 'thumb_up'],
    in_progress: ['info', 'timelapse'], completed: ['success', 'check_circle'], overdue: ['error', 'schedule'],
    enabled: ['success', 'check_circle'], paused: ['warning', 'pause_circle'], disabled: ['muted', 'do_not_disturb_on'],
    keep: ['success', 'check'], revoke: ['error', 'person_remove'], modify: ['warning', 'edit'],
};
export const severityTone = {
    critical: ['error', 'keyboard_double_arrow_up'], high: ['warning', 'keyboard_arrow_up'], medium: ['info', 'drag_handle'], low: ['neutral', 'keyboard_arrow_down'],
};

export function Pill({ value, tones = statusTone, label }) {
    if (!value) return <span className="text-[#A0AEC0]">—</span>;
    const [tone, icon] = tones[value] || ['neutral', null];
    return <Chip tone={tone} icon={icon}>{label || String(value).replace(/_/g, ' ')}</Chip>;
}

export function ModeTag({ mode }) {
    return mode === 'platform'
        ? <Chip tone="primary" icon="database" size="xs" title="Measured from the bank's own records in Atheris">Platform data</Chip>
        : <Chip tone="tertiary" icon="science" size="xs" title="No live connector: measured by the sandbox adapter">Sandbox</Chip>;
}
