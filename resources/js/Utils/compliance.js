// Shared Compliance labels and colours. Mirrors App\Models\{ComplianceResult, Gap, Control, Evidence} — keep in sync.
export { formatDate, humanize, toDateInput } from '@/Utils/risk';

export const RESULT_STYLES = {
    compliant: { label: 'Compliant', color: '#2D7D46', chip: 'bg-green-50 text-green-700', row: 'border-green-200 bg-green-50/40' },
    partially_compliant: { label: 'Partially Compliant', color: '#D4AF37', chip: 'bg-amber-50 text-amber-800', row: 'border-amber-200 bg-amber-50/40' },
    non_compliant: { label: 'Non-Compliant', color: '#C53030', chip: 'bg-red-50 text-red-700', row: 'border-red-200 bg-red-50/40' },
    not_applicable: { label: 'Not Applicable', color: '#718096', chip: 'bg-gray-100 text-gray-600', row: 'border-gray-200 bg-gray-50' },
    not_assessed: { label: 'Not Assessed', color: '#CBD5E0', chip: 'bg-slate-50 text-slate-500', row: 'border-gray-100 bg-white' },
};

export const ASSESSMENT_STATUS = {
    planned: { label: 'Planned', chip: 'bg-gray-100 text-gray-700' },
    in_progress: { label: 'In Progress', chip: 'bg-blue-50 text-blue-700' },
    completed: { label: 'Completed', chip: 'bg-green-50 text-green-700' },
    cancelled: { label: 'Cancelled', chip: 'bg-gray-50 text-gray-400 line-through' },
};

export const GAP_SEVERITY = {
    critical: { label: 'Critical', chip: 'bg-red-50 text-red-700', color: '#C53030' },
    high: { label: 'High', chip: 'bg-orange-50 text-orange-700', color: '#DD6B20' },
    medium: { label: 'Medium', chip: 'bg-amber-50 text-amber-800', color: '#D4AF37' },
    low: { label: 'Low', chip: 'bg-green-50 text-green-700', color: '#2D7D46' },
};

export const GAP_STATUS = {
    identified: { label: 'Identified', chip: 'bg-blue-50 text-blue-700' },
    remediation_planned: { label: 'Remediation Planned', chip: 'bg-purple-50 text-purple-700' },
    in_progress: { label: 'In Progress', chip: 'bg-amber-50 text-amber-800' },
    remediated: { label: 'Remediated', chip: 'bg-green-50 text-green-700' },
    accepted: { label: 'Risk Accepted', chip: 'bg-gray-100 text-gray-700' },
    closed: { label: 'Closed', chip: 'bg-gray-50 text-gray-400' },
};
export const GAP_RESOLVED = ['remediated', 'accepted', 'closed'];

export const EFFECTIVENESS = {
    effective: { label: 'Effective', chip: 'bg-green-50 text-green-700', color: '#2D7D46' },
    partially_effective: { label: 'Partially Effective', chip: 'bg-amber-50 text-amber-800', color: '#D4AF37' },
    ineffective: { label: 'Ineffective', chip: 'bg-red-50 text-red-700', color: '#C53030' },
    not_assessed: { label: 'Not Assessed', chip: 'bg-gray-100 text-gray-500', color: '#A0AEC0' },
};
export const effectivenessOf = (value) => EFFECTIVENESS[value] || EFFECTIVENESS.not_assessed;

export const CONTROL_STATUS = {
    draft: 'bg-gray-100 text-gray-600',
    active: 'bg-green-50 text-green-700',
    under_review: 'bg-blue-50 text-blue-700',
    inactive: 'bg-gray-50 text-gray-400',
    deprecated: 'bg-gray-50 text-gray-400 line-through',
};

export const COVERAGE = {
    full: { label: 'Full', chip: 'bg-green-50 text-green-700' },
    partial: { label: 'Partial', chip: 'bg-amber-50 text-amber-800' },
    planned: { label: 'Planned', chip: 'bg-gray-100 text-gray-600' },
};

export const EVIDENCE_TYPES = {
    document: 'Document', screenshot: 'Screenshot', url: 'Link / URL', attestation: 'Attestation', log: 'Log extract',
};

/** Effective evidence state: an approved item past its validity date shows as expired. */
export function evidenceState(e) {
    if (e.is_expired) return { key: 'expired', label: 'Expired', chip: 'bg-gray-100 text-gray-500' };
    return {
        approved: { key: 'approved', label: 'Approved', chip: 'bg-green-50 text-green-700' },
        rejected: { key: 'rejected', label: 'Rejected', chip: 'bg-red-50 text-red-700' },
        pending: { key: 'pending', label: 'Pending Review', chip: 'bg-amber-50 text-amber-800' },
    }[e.status] || { key: e.status, label: e.status, chip: 'bg-gray-100 text-gray-500' };
}

/** Compliance score colour bands (≥80 green, ≥60 gold, ≥40 orange, else red). */
export function scoreColor(score) {
    if (score === null || score === undefined) return '#A0AEC0';
    const s = Number(score);
    return s >= 80 ? '#2D7D46' : s >= 60 ? '#D4AF37' : s >= 40 ? '#DD6B20' : '#C53030';
}

export function isOverdue(date, resolved = false) {
    if (!date || resolved) return false;
    const d = new Date(String(date).slice(0, 10) + 'T23:59:59');
    return d < new Date();
}
