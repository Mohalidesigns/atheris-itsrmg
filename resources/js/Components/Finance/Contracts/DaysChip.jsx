import Chip from '@/Components/Material/Chip';
import { daysLabel } from '@/Utils/finance';

/**
 * Relative-days chip for an end or notice date. Past dates read red, dates inside `warnDays` amber.
 * `muted` greys it for closed contracts.
 */
export default function DaysChip({ days, warnDays = 30, muted = false }) {
    if (days === null || days === undefined) return null;
    const tone = muted ? 'muted' : days < 0 ? 'error' : days <= warnDays ? 'warning' : 'neutral';
    return <Chip size="xs" tone={tone}>{daysLabel(days)}</Chip>;
}
