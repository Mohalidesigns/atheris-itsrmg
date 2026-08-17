import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { TrashIcon } from '@heroicons/react/24/outline';

/**
 * StewardshipDrawer — the owner-and-seal panel for a single entity.
 *
 * WS 1.1 asks for "owner pickers on every entity"; WS 1.3 for the seal state
 * machine's controls. They belong on one surface because they are the same
 * conversation: an owner is the person allowed to clear the seal, and the seal
 * is the reason ownership is worth recording.
 *
 * Loads its own state from `ea.stewardship.panel` so an index page does not
 * have to ship subscription data for every row.
 */

const ROLE_HELP = {
    responsible: 'Does the work of keeping this record accurate. May approve the seal.',
    accountable: 'Answerable for the record being right. May approve the seal.',
    consulted: 'Asked before changes. Receives surveys, cannot approve.',
    observer: 'Kept informed only.',
};

export default function StewardshipDrawer({ show, onClose, entityType, entityId, users = [], roles = [] }) {
    const perms = useEaPermissions();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [userId, setUserId] = useState('');
    const [role, setRole] = useState('responsible');
    const [businessRole, setBusinessRole] = useState('');
    const [flagReason, setFlagReason] = useState('');
    const [showFlag, setShowFlag] = useState(false);

    const load = () => {
        if (!entityType || !entityId) return;
        setLoading(true);
        fetch(route('ea.stewardship.panel', { entity_type: entityType, entity_id: entityId }), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((json) => setData(json))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (show) load();
    }, [show, entityType, entityId]);

    const after = { preserveScroll: true, onSuccess: () => load() };

    const addOwner = () => {
        if (!userId) return;
        router.post(
            route('ea.stewardship.subscribe'),
            { entity_type: entityType, entity_id: entityId, user_id: userId, role, business_role: businessRole || null },
            {
                ...after,
                onSuccess: () => {
                    setUserId('');
                    setBusinessRole('');
                    load();
                },
            },
        );
    };

    const removeOwner = (id) => router.delete(route('ea.stewardship.unsubscribe', id), after);

    const approve = () =>
        router.post(route('ea.seals.approve'), { entity_type: entityType, entity_id: entityId }, after);

    const flag = () => {
        if (!flagReason.trim()) return;
        router.post(
            route('ea.seals.flag'),
            { entity_type: entityType, entity_id: entityId, reason: flagReason },
            {
                ...after,
                onSuccess: () => {
                    setFlagReason('');
                    setShowFlag(false);
                    load();
                },
            },
        );
    };

    const seal = data?.seal;

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="border-b border-gray-100 px-6 py-4">
                <h2 className="text-base font-semibold text-[#0A1F44]">Ownership & quality seal</h2>
                <p className="mt-1 text-xs text-[#718096]">{data?.entity_label || 'Loading…'}</p>
            </div>

            <div className="max-h-[65vh] overflow-y-auto px-6 py-5">
                {loading && !data && <p className="text-sm text-[#718096]">Loading…</p>}

                {seal && (
                    <section className="mb-6">
                        <div className="flex items-center justify-between">
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-[#718096]">
                                Quality seal
                            </h3>
                            <StatusBadge status={seal.tone} label={seal.label} />
                        </div>

                        <div className="mt-3 rounded-lg border border-gray-100 bg-[#F7FAFC] p-3">
                            <div className="flex items-center gap-2">
                                <span className="h-2 flex-1 overflow-hidden rounded-full bg-gray-200">
                                    <span
                                        className="block h-2 rounded-full"
                                        style={{
                                            width: `${seal.completeness}%`,
                                            background:
                                                seal.completeness >= 80
                                                    ? '#2D7D46'
                                                    : seal.completeness >= 50
                                                      ? '#E5A100'
                                                      : '#B3261E',
                                        }}
                                    />
                                </span>
                                <span className="text-xs font-medium text-[#2D3748]">
                                    {seal.completeness}% complete
                                </span>
                            </div>

                            {seal.missing?.length > 0 && (
                                <p className="mt-2 text-xs text-[#718096]">
                                    Still missing: <span className="text-[#2D3748]">{seal.missing.join(', ')}</span>
                                </p>
                            )}

                            {seal.approved_by && (
                                <p className="mt-2 text-xs text-[#718096]">
                                    Approved by {seal.approved_by}
                                    {seal.approved_at ? ` on ${seal.approved_at}` : ''}
                                    {seal.expires_at ? ` · expires ${seal.expires_at}` : ''}
                                </p>
                            )}

                            {seal.break_reason && (
                                <p className="mt-2 text-xs text-[#8A6400]">{seal.break_reason}</p>
                            )}
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {perms.canApprove && (
                                <PrimaryButton
                                    type="button"
                                    onClick={approve}
                                    disabled={!data?.can_approve || seal.missing?.length > 0}
                                    className="!px-3 !py-1.5 !text-xs"
                                >
                                    Approve seal
                                </PrimaryButton>
                            )}
                            {perms.canEdit && (
                                <SecondaryButton onClick={() => setShowFlag((v) => !v)} className="!px-3 !py-1.5">
                                    Flag for review
                                </SecondaryButton>
                            )}
                        </div>

                        {!data?.can_approve && perms.canApprove && (
                            <p className="mt-2 text-[11px] text-[#718096]">
                                Only the Responsible or Accountable owner may approve this record.
                            </p>
                        )}
                        {data?.can_approve && seal.missing?.length > 0 && (
                            <p className="mt-2 text-[11px] text-[#8A6400]">
                                Approval is blocked until the mandatory attributes above are filled in.
                            </p>
                        )}

                        {showFlag && (
                            <div className="mt-3 flex items-center gap-2">
                                <input
                                    value={flagReason}
                                    onChange={(e) => setFlagReason(e.target.value)}
                                    placeholder="Why does this need review?"
                                    className="flex-1 rounded-lg border-gray-300 py-1.5 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                />
                                <SecondaryButton onClick={flag} className="!px-3 !py-1.5">
                                    Flag
                                </SecondaryButton>
                            </div>
                        )}
                    </section>
                )}

                <section>
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-[#718096]">Owners</h3>

                    {data?.subscriptions?.length ? (
                        <ul className="mt-2 divide-y divide-gray-100">
                            {data.subscriptions.map((s) => (
                                <li key={s.id} className="flex items-center justify-between py-2">
                                    <div className="min-w-0">
                                        <p className="text-sm text-[#2D3748]">{s.name}</p>
                                        <p className="text-[11px] text-[#718096]">
                                            {s.email}
                                            {s.business_role ? ` · ${s.business_role}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge
                                            status={
                                                s.role === 'accountable'
                                                    ? 'approved'
                                                    : s.role === 'responsible'
                                                      ? 'active'
                                                      : 'draft'
                                            }
                                            label={s.role_label}
                                        />
                                        {perms.canEdit && (
                                            <button
                                                type="button"
                                                onClick={() => removeOwner(s.id)}
                                                aria-label={`Remove ${s.name}`}
                                                className="rounded p-1 text-[#718096] hover:bg-[#B3261E]/10 hover:text-[#B3261E]"
                                            >
                                                <TrashIcon className="h-4 w-4" />
                                            </button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-2 rounded-lg border border-dashed border-gray-200 p-3 text-xs text-[#718096]">
                            Nobody owns this record. Until someone is Accountable for it, its seal cannot be approved
                            and it will never appear in a survey audience.
                        </p>
                    )}

                    {perms.canEdit && (
                        <div className="mt-4 space-y-2 rounded-lg border border-gray-100 bg-[#F7FAFC] p-3">
                            <select
                                value={userId}
                                onChange={(e) => setUserId(e.target.value)}
                                className="w-full rounded-lg border-gray-300 py-1.5 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                            >
                                <option value="">Choose a person…</option>
                                {users.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </select>
                            <div className="flex gap-2">
                                <select
                                    value={role}
                                    onChange={(e) => setRole(e.target.value)}
                                    className="rounded-lg border-gray-300 py-1.5 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                >
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    value={businessRole}
                                    onChange={(e) => setBusinessRole(e.target.value)}
                                    placeholder="Business role (optional)"
                                    className="flex-1 rounded-lg border-gray-300 py-1.5 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                />
                            </div>
                            <p className="text-[11px] text-[#718096]">{ROLE_HELP[role]}</p>
                            <SecondaryButton onClick={addOwner} disabled={!userId} className="!px-3 !py-1.5">
                                Assign owner
                            </SecondaryButton>
                        </div>
                    )}
                </section>
            </div>

            <div className="flex items-center justify-end border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                <SecondaryButton onClick={onClose}>Close</SecondaryButton>
            </div>
        </Modal>
    );
}
