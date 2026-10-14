// Change Management shared constants (ATH-CHG-001 §8). Mirrors App\Models\Change\ChangeRequest — keep in sync.

export const NAVY = '#0A1F44';
export const GOLD = '#C9A86A';
export const GREEN = '#2D7D46';
export const RED = '#B3261E';
export const AMBER = '#E5A100';
export const BLUE = '#1D4ED8';
export const GREY = '#718096';

export const CHANGE_TYPES = [
    { key: 'standard', label: 'Standard', meaning: 'Pre-approved, low-risk, repeatable procedure from an approved template. No per-change approval.' },
    { key: 'normal', label: 'Normal', meaning: 'Planned change assessed for risk and routed through the bank\'s approval stages before it is scheduled.' },
    { key: 'emergency', label: 'Emergency', meaning: 'Urgent fix for an incident, vulnerability or regulatory demand. ECAB authorises; PIR is always mandatory.' },
];

export const CATEGORIES = ['application', 'infrastructure', 'network', 'security', 'database', 'core_banking', 'data', 'vendor', 'process', 'other'];

/** Material Symbol per category (Components/Material/Icon). */
export const CATEGORY_ICON = {
    application: 'apps', infrastructure: 'dns', network: 'lan', security: 'shield', database: 'database',
    core_banking: 'account_balance', data: 'dataset', vendor: 'storefront', process: 'account_tree', other: 'category',
};

/** Material tone per risk level (Components/Material/Chip TONES). */
export const RISK_TONE = { critical: 'error', high: 'warning', medium: 'info', low: 'neutral' };

export const PRIORITIES = ['low', 'medium', 'high', 'critical'];

export const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

/** Main path of the lifecycle; side states are shown as badges. */
export const STATUS_STEPS = ['draft', 'in_approval', 'approved', 'scheduled', 'implementing', 'implemented', 'review', 'closed'];

export const SIDE_STATUSES = ['rejected', 'cancelled', 'backed_out', 'failed'];

export const OPEN_STATUSES = ['draft', 'submitted', 'in_approval', 'approved', 'scheduled', 'implementing', 'implemented', 'review', 'backed_out', 'failed'];

export const TASK_TYPES = ['pre_implementation', 'implementation', 'validation', 'backout', 'communication'];

export const TASK_STATUSES = ['pending', 'in_progress', 'done', 'skipped'];

export const PIR_OUTCOMES = ['successful', 'successful_with_issues', 'failed', 'backed_out'];

export const IMPACT_LABELS = { 1: 'Insignificant', 2: 'Minor', 3: 'Moderate', 4: 'Major', 5: 'Severe' };
export const LIKELIHOOD_LABELS = { 1: 'Rare', 2: 'Unlikely', 3: 'Possible', 4: 'Likely', 5: 'Almost certain' };

export const RISK_COLOUR = { critical: RED, high: AMBER, medium: BLUE, low: GREY };
export const PRIORITY_COLOUR = { critical: RED, high: AMBER, medium: BLUE, low: GREY };
export const TYPE_COLOUR = { standard: GREEN, normal: NAVY, emergency: RED };
export const STATUS_COLOUR = {
    draft: GREY, submitted: BLUE, in_approval: GOLD, approved: NAVY, scheduled: BLUE, implementing: AMBER, implemented: GREEN,
    review: GOLD, closed: GREY, rejected: RED, cancelled: GREY, backed_out: RED, failed: RED,
};
export const APPROVAL_COLOUR = { pending: GOLD, approved: GREEN, rejected: RED, skipped: GREY, expired: RED };
export const VOTE_COLOUR = { approve: GREEN, reject: RED, abstain: GREY, defer: AMBER };
export const EVENT_DOT = {
    created: NAVY, submitted: BLUE, routed: GOLD, approved: GREEN, rejected: RED, status: BLUE, scheduled: BLUE, conflict: AMBER,
    freeze_override: RED, cab_vote: GOLD, assigned: GREEN, updated: '#A0AEC0', comment: GREY, task: '#A0AEC0', pir: GOLD, notified: '#A0AEC0',
};

/** Risk band from the raw impact × likelihood score (ChangeRiskScorer::levelFor). */
export function riskLevelForScore(score) {
    const s = Number(score) || 0;
    if (s >= 16) return 'critical';
    if (s >= 10) return 'high';
    if (s >= 5) return 'medium';
    return 'low';
}

const RANK = { low: 0, medium: 1, high: 2, critical: 3 };

/**
 * Live preview of ChangeRiskScorer::scoreFor. Options mirror the automatic uplifts:
 * outage adds a likelihood step; core banking or a critical asset lifts to high; emergency is never below medium.
 */
export function riskLevelFor(impact, likelihood, opts = {}) {
    const i = Math.min(5, Math.max(1, Number(impact) || 1));
    let l = Math.min(5, Math.max(1, Number(likelihood) || 1));
    const factors = [];
    if (opts.outage && l < 5) { l += 1; factors.push('Outage required: likelihood raised one step.'); }
    const score = i * l;
    let level = riskLevelForScore(score);
    if (opts.category === 'core_banking' && RANK[level] < RANK.high) { level = 'high'; factors.push('Core banking change: level raised to high.'); }
    if (opts.criticalAsset && RANK[level] < RANK.high) { level = 'high'; factors.push(`Affects a critical asset${typeof opts.criticalAsset === 'string' ? ` (${opts.criticalAsset})` : ''}: level raised to high.`); }
    if (opts.type === 'emergency' && RANK[level] < RANK.medium) { level = 'medium'; factors.push('Emergency change: level is never below medium.'); }
    return { score, level, factors, impact: i, likelihood: l };
}

export function riskRank(level) {
    return RANK[level] ?? 0;
}

/** "12 Oct 2026 09:00 – 11:30" for a window; end-only time when on the same day. */
export function windowLabel(start, end) {
    if (!start) return 'Not scheduled';
    const s = new Date(start);
    const e = end ? new Date(end) : null;
    const d = (x) => x.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    const t = (x) => x.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    if (!e || Number.isNaN(e.getTime())) return `${d(s)} ${t(s)}`;
    if (d(s) === d(e)) return `${d(s)} ${t(s)} – ${t(e)}`;
    return `${d(s)} ${t(s)} – ${d(e)} ${t(e)}`;
}

/** Value for an <input type="datetime-local"> from an ISO string (local time). */
export function toDateTimeInput(value) {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function hoursLabel(hours) {
    const h = Number(hours) || 0;
    return h >= 48 && h % 24 === 0 ? `${h / 24} days` : `${h} h`;
}

export function relativeDue(iso) {
    if (!iso) return null;
    const diff = (new Date(iso).getTime() - Date.now()) / 36e5;
    if (Number.isNaN(diff)) return null;
    const abs = Math.abs(diff);
    const txt = abs >= 48 ? `${Math.round(abs / 24)} d` : `${Math.round(abs)} h`;
    return diff < 0 ? `${txt} overdue` : `due in ${txt}`;
}
