import Chip from '@/Components/Material/Chip';
import { capStatusTone, humanize } from '@/Utils/finance';

export default function CapStatusChip({ status, size = 'xs' }) {
    if (!status) return null;
    return <Chip size={size} tone={capStatusTone(status)}>{humanize(status)}</Chip>;
}
