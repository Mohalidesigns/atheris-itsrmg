import Chip from '@/Components/Material/Chip';

// status → [tone, Material Symbol]. Tones live in Components/Material/Chip.
const OK = ['success', 'check_circle'];
const BAD = ['error', 'cancel'];
const map = {
    pass: OK,
    fail: BAD,
    warn: ['warning', 'warning'],
    error: ['neutral', 'error'],
    enabled: OK,
    disabled: ['muted', 'do_not_disturb_on'],
    active: OK,
    degraded: ['warning', 'warning'],
    paused: ['warning', 'pause_circle'],
    draft: ['neutral', 'edit_note'],
    in_review: ['tertiary', 'rate_review'],
    approved: ['primary', 'verified'],
    signed: ['primary', 'signature'],
    delivered: OK,
    acknowledged: ['success', 'done_all'],
    // Severity / risk: Material arrow glyphs read faster than colour alone.
    critical: ['error', 'keyboard_double_arrow_up'],
    high: ['warning', 'keyboard_arrow_up'],
    moderate: ['info', 'drag_handle'],
    medium: ['info', 'drag_handle'],
    low: ['neutral', 'keyboard_arrow_down'],
    info: ['neutral', 'info'],
    open: ['error', 'radio_button_unchecked'],
    in_progress: ['info', 'timelapse'],
    blocked: ['warning', 'block'],
    remediated: OK,
    resolved: OK,
    verified: ['success', 'verified'],
    closed: ['muted', 'task_alt'],
    escalated: ['error', 'priority_high'],
    green: ['success', 'circle'],
    amber: ['warning', 'circle'],
    red: ['error', 'circle'],
    // Change Management (ATH-CHG-001 §8)
    submitted: ['info', 'send'],
    in_approval: ['tertiary', 'hourglass_top'],
    scheduled: ['info', 'event'],
    implementing: ['warning', 'construction'],
    implemented: OK,
    review: ['tertiary', 'rate_review'],
    backed_out: ['error', 'undo'],
    failed: BAD,
    cancelled: ['muted', 'block'],
    rejected: BAD,
    pending: ['tertiary', 'hourglass_top'],
    skipped: ['muted', 'skip_next'],
    expired: ['error', 'timer_off'],
    standard: ['success', 'inventory'],
    normal: ['primary', 'sync_alt'],
    emergency: ['error', 'bolt'],
    held: OK,
    deferred: ['warning', 'schedule'],
    more_info: ['info', 'help'],
    done: OK,
    approve: OK,
    reject: BAD,
    abstain: ['muted', 'remove_circle'],
    defer: ['warning', 'schedule'],
    // Incidents, breaches, vulnerabilities, BCP, assets, audit trail
    detected: ['error', 'notification_important'],
    reported: ['neutral', 'flag'],
    triaged: ['info', 'fact_check'],
    investigating: ['warning', 'search'],
    containing: ['warning', 'shield'],
    contained: ['primary', 'shield'],
    eradicating: ['warning', 'cleaning_services'],
    recovering: ['info', 'restart_alt'],
    notifying: ['tertiary', 'campaign'],
    notified: OK,
    post_incident: ['primary', 'rate_review'],
    post_incident_review: ['primary', 'rate_review'],
    mitigated: ['success', 'shield'],
    accepted: ['tertiary', 'thumb_up'],
    tested: ['primary', 'fact_check'],
    inactive: ['muted', 'do_not_disturb_on'],
    under_review: ['tertiary', 'rate_review'],
    decommissioned: ['error', 'delete_forever'],
    very_low: ['neutral', 'keyboard_double_arrow_down'],
    created: ['success', 'add_circle'],
    updated: ['info', 'edit'],
    deleted: ['error', 'delete'],
    completed: OK,
    overdue: ['error', 'schedule'],
    required: ['error', 'notification_important'],
    not_required: ['muted', 'remove_circle'],
};

/**
 * The status glyph shows only when the label names that status ("Medium", "Medium and above",
 * "Critical · 18"). Pages that borrow a status purely for its colour (status="critical" label="PII")
 * get the tone without a misleading icon. Pass `icon` to force one, or `icon={false}` to drop it.
 */
export default function StatusBadge({ status, label, icon, size }) {
    const [tone, glyph] = map[status] || ['neutral', null];
    const plain = String(status ?? '').replace(/_/g, ' ');
    const names = !label || String(label).toLowerCase().startsWith(plain.toLowerCase());
    const shown = icon === false ? null : (icon || (names ? glyph : null));
    return (
        <Chip tone={tone} icon={shown} size={size}>
            {label || plain}
        </Chip>
    );
}
