import Chip from '@/Components/Material/Chip';
import { healthTone, humanize } from '@/Utils/finance';

const ICONS = { on_track: 'check_circle', at_risk: 'warning', off_track: 'error', on_hold: 'pause_circle' };

/** Status or health (on_track / at_risk / off_track / on_hold). Null health renders nothing. */
export default function HealthChip({ value, size = 'sm' }) {
    if (!value) return null;
    return <Chip size={size} tone={healthTone(value)} icon={ICONS[value]}>{humanize(value)}</Chip>;
}
