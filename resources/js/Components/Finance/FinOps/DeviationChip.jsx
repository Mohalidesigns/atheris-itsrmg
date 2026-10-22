import Chip from '@/Components/Material/Chip';
import { fmtPct } from '@/Utils/finance';

/** Deviation percentage chip: 100% or more is red, 50% or more amber. */
export default function DeviationChip({ value }) {
    const n = Number(value);
    const tone = Number.isNaN(n) ? 'neutral' : n >= 100 ? 'error' : n >= 50 ? 'warning' : 'info';
    return <Chip size="xs" tone={tone} icon="trending_up">{fmtPct(value, { sign: true, decimals: 0 })}</Chip>;
}
