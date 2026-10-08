// Shared CBN-CSAT labels and colours. Maturity uses one scale everywhere (0 = Sub-Baseline … 5 = Innovative).
export { formatDate, humanize } from '@/Utils/risk';

export const IR_LEVELS = ['least', 'minimal', 'moderate', 'significant', 'most'];
export const IR_LEVEL_COLORS = { least: '#2D7D46', minimal: '#319795', moderate: '#D4AF37', significant: '#DD6B20', most: '#C53030' };

/** IR average (1–5) → level, using the CBN thresholds (≤1.4, ≤2.4, ≤3.4, ≤4.4). */
export function irLevelFor(score) {
    const s = Number(score);
    if (!s) return null;
    if (s <= 1.4) return 'least';
    if (s <= 2.4) return 'minimal';
    if (s <= 3.4) return 'moderate';
    if (s <= 4.4) return 'significant';
    return 'most';
}

export const MATURITY_LABELS = { 0: 'Sub-Baseline', 1: 'Baseline', 2: 'Evolving', 3: 'Intermediate', 4: 'Advanced', 5: 'Innovative' };
export const MATURITY_KEYS = ['sub_baseline', 'baseline', 'evolving', 'intermediate', 'advanced', 'innovative'];
export const MATURITY_COLORS = { 0: '#C53030', 1: '#DD6B20', 2: '#D4AF37', 3: '#319795', 4: '#2D7D46', 5: '#1A365D' };

export const STATUS_LABELS = {
    draft: 'Draft',
    in_progress: 'In Progress',
    pending_approval: 'Pending Approval',
    approved: 'Approved',
    submitted: 'Submitted to CBN',
};
export const STATUS_COLORS = {
    draft: 'bg-gray-100 text-gray-700',
    in_progress: 'bg-blue-50 text-blue-700',
    pending_approval: 'bg-amber-50 text-amber-800',
    approved: 'bg-green-50 text-green-700',
    submitted: 'bg-[#1A365D] text-white',
};

export const RAG_COLORS = { green: '#2D7D46', amber: '#D4AF37', red: '#C53030' };

/** Shown at the top of section pages when the cycle is frozen. */
export function lockMessage(assessment) {
    return `This assessment is ${STATUS_LABELS[assessment.status] || assessment.status} — answers and registers are read-only until it is returned for revision.`;
}

export const LICENCE_LABELS = {
    dmb: 'Deposit Money Bank (DMB)', mfb: 'Microfinance Bank (MFB)', mortgage_bank: 'Mortgage Bank',
    psb: 'Payment Service Bank (PSB)', merchant_bank: 'Merchant Bank', development_finance: 'Development Finance Institution',
};
