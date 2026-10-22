import Chip from '@/Components/Material/Chip';
import { RGT_LABELS } from '@/Utils/finance';

const TONES = { run: 'neutral', grow: 'info', transform: 'tertiary' };

export default function RgtChip({ rgt, size = 'xs' }) {
    if (!rgt) return null;
    return <Chip size={size} tone={TONES[rgt] ?? 'neutral'}>{RGT_LABELS[rgt] ?? rgt}</Chip>;
}
