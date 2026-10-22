import Chip from '@/Components/Material/Chip';
import { humanize, severityTone } from '@/Utils/finance';

/** Variance severity chip. `severity` null renders nothing unless `showOk` is set. */
export default function SeverityChip({ severity, direction, showOk = false, size = 'xs' }) {
    if (!severity) return showOk ? <Chip size={size} tone="success" icon="check_circle">Within tolerance</Chip> : null;
    const icon = severity === 'red' ? 'error' : 'warning';
    return <Chip size={size} tone={severityTone(severity)} icon={icon}>{humanize(severity)}{direction ? ` ${direction === 'over' ? 'overspend' : 'underspend'}` : ''}</Chip>;
}
