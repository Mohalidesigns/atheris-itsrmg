// Single 5×5 rating scale for the UI. Mirrors App\Models\Risk::RATINGS — keep in sync.
export const RATING_BANDS = [
    { key: 'critical', label: 'Critical', min: 20, color: '#C53030' },
    { key: 'high', label: 'High', min: 12, color: '#DD6B20' },
    { key: 'medium', label: 'Medium', min: 6, color: '#D4AF37' },
    { key: 'low', label: 'Low', min: 3, color: '#2D7D46' },
    { key: 'very_low', label: 'Very Low', min: 1, color: '#319795' },
];

export const RATING_COLORS = Object.fromEntries(RATING_BANDS.map((b) => [b.key, b.color]));

export function ratingFor(score) {
    const s = Number(score);
    if (!s) return null;
    return (RATING_BANDS.find((b) => s >= b.min) || RATING_BANDS[RATING_BANDS.length - 1]).key;
}

export function ratingColor(score) {
    return RATING_COLORS[ratingFor(score)] || '#A0AEC0';
}

export const LIKELIHOOD_LABELS = { 1: 'Rare', 2: 'Unlikely', 3: 'Possible', 4: 'Likely', 5: 'Almost Certain' };
export const IMPACT_LABELS = { 1: 'Insignificant', 2: 'Minor', 3: 'Moderate', 4: 'Major', 5: 'Catastrophic' };

export const APPETITE_LABELS = {
    within: 'Within appetite',
    above: 'Above appetite',
    below: 'Below appetite',
};

export const SOURCE_LABELS = {
    audit: 'Audit',
    'self-assessment': 'Self-Assessment',
    incident: 'Incident',
    regulator: 'Regulator',
    external: 'External',
    'ea.obsolescence': 'EA Obsolescence',
};

/** "under_review" → "Under Review" */
export function humanize(value) {
    if (value === null || value === undefined || value === '') return '—';
    return String(value)
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Laravel date/datetime (ISO or Y-m-d) → "17 Jul 2026"; withTime adds "09:14". */
export function formatDate(value, withTime = false) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    const opts = { day: '2-digit', month: 'short', year: 'numeric' };
    if (withTime) Object.assign(opts, { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleString('en-GB', opts);
}

/** Value for an <input type="date"> from an ISO/datetime string. */
export function toDateInput(value) {
    return value ? String(value).slice(0, 10) : '';
}

// Threat-intel taxonomy labels (App\\Http\\Controllers\\ThreatController::CATEGORIES / SOURCES).
const THREAT_LABELS = {
    'ai-threat': 'AI Threat', api: 'API', c2: 'Command & Control', 'data-loss': 'Data Loss',
    'supply-chain': 'Supply Chain', 'third-party': 'Third Party',
    ngcert: 'ngCERT', nitda: 'NITDA', cbn: 'CBN', 'mitre-attck': 'MITRE ATT&CK', 'ibm-x-force': 'IBM X-Force',
};

export function threatLabel(value) {
    return THREAT_LABELS[value] || humanize(value);
}
