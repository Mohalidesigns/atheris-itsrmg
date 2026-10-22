import Chip from '@/Components/Material/Chip';
import { stageLabel, stageTone } from '@/Utils/finance';

export default function StageChip({ stage, size = 'sm' }) {
    return <Chip size={size} tone={stageTone(stage)}>{stageLabel(stage)}</Chip>;
}
