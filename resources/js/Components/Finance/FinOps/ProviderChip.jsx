import Chip from '@/Components/Material/Chip';
import { providerLabel } from '@/Utils/finance';

const TONES = { aws: 'warning', azure: 'info', gcp: 'success', oci: 'error', other: 'neutral' };

export default function ProviderChip({ provider, size = 'xs' }) {
    if (!provider) return null;
    return <Chip size={size} tone={TONES[provider] ?? 'neutral'} icon="cloud">{providerLabel(provider)}</Chip>;
}
