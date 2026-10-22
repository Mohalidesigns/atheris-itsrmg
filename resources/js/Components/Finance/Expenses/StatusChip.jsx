import Chip from '@/Components/Material/Chip';
import { ACCRUAL_TONES, ACTUAL_TONES, BATCH_TONES, EXPENSE_TONES, INVOICE_TONES, MATCH_TONES, PO_TONES, humanize } from '@/Utils/finance';

const MAPS = { expense: EXPENSE_TONES, po: PO_TONES, invoice: INVOICE_TONES, match: MATCH_TONES, actual: ACTUAL_TONES, accrual: ACCRUAL_TONES, batch: BATCH_TONES };

/** One status chip for every P3 record kind. */
export default function StatusChip({ kind = 'expense', status, size = 'xs', icon }) {
    if (!status) return null;
    return <Chip size={size} tone={(MAPS[kind] || {})[status] || 'neutral'} icon={icon}>{humanize(status)}</Chip>;
}
