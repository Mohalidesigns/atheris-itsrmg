import StatusBadge from '@/Components/StatusBadge';
import Chip from '@/Components/Material/Chip';

/**
 * QualitySealBadge — the freshness indicator on every entity row.
 *
 * ATH-EAR-002 §5.4 B3 requires a "seal indicator on every entity". The point is
 * that a reader can tell, at a glance and without asking anyone, whether a
 * given record is something they may rely on — and the CISO signing a CSAT
 * return can see which answers are backed by approved data before signing.
 */
const SEAL_ICON = { approved: 'verified_user', check_needed: 'pending_actions', rejected: 'gpp_bad', draft: 'edit_note' };

export default function QualitySealBadge({ seal, showCompleteness = false, compact = false }) {
    if (!seal) {
        return (
            <Chip tone="neutral" variant="outlined" icon="remove_moderator" iconFill={false}
                title="This record has never been through the quality seal — nothing has confirmed it is accurate.">
                Unsealed
            </Chip>
        );
    }

    const overdue = seal.days_to_expiry !== null && seal.days_to_expiry !== undefined && seal.days_to_expiry < 0;

    const title = [
        seal.break_reason,
        seal.approved_by ? `Approved by ${seal.approved_by}` : null,
        seal.expires_at
            ? overdue
                ? `Expired ${Math.abs(seal.days_to_expiry)}d ago`
                : `Expires ${seal.expires_at} (${seal.days_to_expiry}d)`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <span className="inline-flex items-center gap-1.5" title={title || undefined}>
            <StatusBadge status={seal.tone} label={seal.label} icon={SEAL_ICON[seal.state]} />
            {showCompleteness && (
                <span className="inline-flex items-center gap-1">
                    <span className="h-1.5 w-10 overflow-hidden rounded-full bg-gray-100">
                        <span
                            className="block h-1.5 rounded-full"
                            style={{
                                width: `${seal.completeness || 0}%`,
                                background:
                                    seal.completeness >= 80
                                        ? '#2D7D46'
                                        : seal.completeness >= 50
                                          ? '#E5A100'
                                          : '#B3261E',
                            }}
                        />
                    </span>
                    <span className="text-[10px] text-[#718096]">{seal.completeness || 0}%</span>
                </span>
            )}
            {!compact && seal.state === 'approved' && seal.days_to_expiry !== null && seal.days_to_expiry <= 14 && seal.days_to_expiry >= 0 && (
                <span className="text-[10px] font-medium text-[#8A6400]">expires in {seal.days_to_expiry}d</span>
            )}
        </span>
    );
}
