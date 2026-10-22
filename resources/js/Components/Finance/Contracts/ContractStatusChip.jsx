import Chip from '@/Components/Material/Chip';
import { CONTRACT_TONES, humanize } from '@/Utils/finance';

const ICONS = { active: 'check_circle', in_renewal_window: 'event_upcoming', notice_deadline_passed: 'alarm', expired: 'event_busy', renewed: 'autorenew', terminated: 'cancel', replaced: 'swap_horiz', draft: 'edit_note' };

export default function ContractStatusChip({ status, size = 'xs' }) {
    return <Chip size={size} tone={CONTRACT_TONES[status] || 'neutral'} icon={ICONS[status]}>{humanize(status)}</Chip>;
}
