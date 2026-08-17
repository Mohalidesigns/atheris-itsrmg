import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, router } from '@inertiajs/react';

/**
 * Seal policy administration — the configurable half of B3.
 *
 * "Admins configure automatic renewal intervals (30 or 90 days) that break the
 * seal on schedule **regardless of whether anything changed** — forcing
 * periodic re-validation of stale data" (§3.4). §5.4 B3 asks for 30/60/90.
 *
 * The mandatory-attribute list does double duty: it gates approval and it
 * weights the completeness score, so tightening it here immediately makes the
 * repository look less complete — which is the honest outcome, not a bug.
 */
function PolicyRow({ policy, intervals, canEdit }) {
    const [interval, setInterval] = useState(policy.renewal_interval_days);
    const [autoExpiry, setAutoExpiry] = useState(policy.auto_expiry_enabled);
    const [breakOnEdit, setBreakOnEdit] = useState(policy.break_on_edit);
    const [mandatory, setMandatory] = useState(policy.mandatory || []);
    const [saving, setSaving] = useState(false);

    const allAttributes = { ...(policy.mandatory_labels || {}), ...(policy.optional_labels || {}) };

    const dirty =
        interval !== policy.renewal_interval_days ||
        autoExpiry !== policy.auto_expiry_enabled ||
        breakOnEdit !== policy.break_on_edit ||
        JSON.stringify([...mandatory].sort()) !== JSON.stringify([...(policy.mandatory || [])].sort());

    const save = () => {
        setSaving(true);
        router.post(
            route('ea.seals.policies.save'),
            {
                entity_type: policy.entity_type,
                renewal_interval_days: interval,
                auto_expiry_enabled: autoExpiry,
                break_on_edit: breakOnEdit,
                mandatory_attributes: mandatory,
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    const toggle = (attr) =>
        setMandatory((prev) => (prev.includes(attr) ? prev.filter((a) => a !== attr) : [...prev, attr]));

    return (
        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-[#0A1F44]">{policy.label}</h3>
                    {!policy.configured && (
                        <p className="text-[11px] text-[#718096]">Using platform defaults — not yet configured.</p>
                    )}
                </div>
                {canEdit && dirty && (
                    <button
                        type="button"
                        onClick={save}
                        disabled={saving}
                        className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D] disabled:opacity-50"
                    >
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                )}
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label className="block text-xs font-medium uppercase tracking-wide text-[#718096]">
                        Re-validate every
                    </label>
                    <select
                        value={interval}
                        onChange={(e) => setInterval(Number(e.target.value))}
                        disabled={!canEdit}
                        className="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                    >
                        {intervals.map((i) => (
                            <option key={i} value={i}>
                                {i} days
                            </option>
                        ))}
                    </select>
                </div>

                <label className="flex items-start gap-2 pt-5 text-sm text-[#2D3748]">
                    <input
                        type="checkbox"
                        checked={autoExpiry}
                        onChange={(e) => setAutoExpiry(e.target.checked)}
                        disabled={!canEdit}
                        className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                    />
                    <span>
                        Expire on schedule
                        <span className="block text-[11px] text-[#718096]">
                            Breaks the seal at the interval even if nothing changed.
                        </span>
                    </span>
                </label>

                <label className="flex items-start gap-2 pt-5 text-sm text-[#2D3748]">
                    <input
                        type="checkbox"
                        checked={breakOnEdit}
                        onChange={(e) => setBreakOnEdit(e.target.checked)}
                        disabled={!canEdit}
                        className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                    />
                    <span>
                        Break on edit
                        <span className="block text-[11px] text-[#718096]">
                            An edit after approval sends it back for review.
                        </span>
                    </span>
                </label>
            </div>

            <div className="mt-4">
                <p className="text-xs font-medium uppercase tracking-wide text-[#718096]">
                    Mandatory before approval
                </p>
                <p className="mt-0.5 text-[11px] text-[#718096]">
                    These also weight the completeness score at double an optional attribute.
                </p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {Object.entries(allAttributes).map(([attr, label]) => {
                        const on = mandatory.includes(attr);
                        return (
                            <button
                                key={attr}
                                type="button"
                                disabled={!canEdit}
                                onClick={() => toggle(attr)}
                                className={`rounded-full border px-2.5 py-1 text-[11px] transition ${
                                    on
                                        ? 'border-[#0A1F44] bg-[#0A1F44] text-white'
                                        : 'border-gray-200 bg-white text-[#718096] hover:border-gray-300'
                                }`}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default function SealPolicies({ policies = [], intervals = [], posture = {} }) {
    const perms = useEaPermissions();

    return (
        <AuthenticatedLayout header="Seal Policy">
            <Head title="Quality Seal Policy" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Settings' },
                    { label: 'Seal Policy' },
                ]}
                title="Quality Seal Policy"
                subtitle="How often each kind of record must be re-confirmed, and what has to be filled in before it can be approved."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Approved" value={posture.approved || 0} tone="green" />
                <KpiCard
                    label="Need re-validation"
                    value={posture.check_needed || 0}
                    tone={posture.check_needed ? 'amber' : 'white'}
                />
                <KpiCard label="Never sealed" value={posture.unsealed || 0} tone={posture.unsealed ? 'red' : 'white'} />
                <KpiCard label="Avg completeness" value={`${posture.avg_completeness || 0}%`} tone="navy" />
            </div>

            <p className="mb-5 rounded-xl border border-gray-200 bg-white p-4 text-xs leading-relaxed text-[#718096]">
                A seal is approved by the record's Responsible or Accountable owner. It breaks when the record is
                edited afterwards, and again when the interval below elapses — whether or not anything changed. That
                second rule is the important one: a repository does not announce that it has gone stale, so something
                has to challenge it on a clock.
            </p>

            <div className="space-y-4">
                {policies.map((p) => (
                    <PolicyRow key={p.entity_type} policy={p} intervals={intervals} canEdit={perms.canAdmin} />
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
