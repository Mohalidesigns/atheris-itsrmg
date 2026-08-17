import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, router, useForm } from '@inertiajs/react';

/**
 * A compiled regulatory return — ATH-EAR-002 A1.
 *
 * §6.3 A1: "Every answer carries **evidence citations** back to the EA entities
 * that produced it, with the quality-seal state shown so the CISO knows what is
 * trustworthy before signing."
 *
 * That matters legally, not just editorially: the April 2026 CSAT circular
 * makes false or misleading data a regulatory breach under BOFIA 2020, and the
 * return is CISO-signed. The signing dialog therefore shows the evidence
 * position first and refuses below the configured floor.
 */

const CONFIDENCE_TONE = { strong: 'pass', adequate: 'in_progress', weak: 'warn', insufficient: 'fail' };
const SEAL_TONE = { approved: 'pass', check_needed: 'warn', draft: 'draft', rejected: 'fail', unsealed: 'closed' };

function Value({ value }) {
    if (value === null || value === undefined) return <span className="text-[#718096]">—</span>;
    if (typeof value === 'boolean') return <span>{value ? 'Yes' : 'No'}</span>;

    if (Array.isArray(value)) {
        if (value.length === 0) return <span className="text-[#718096]">None</span>;
        if (typeof value[0] !== 'object') return <span>{value.join(', ')}</span>;

        return (
            <div className="overflow-x-auto">
                <table className="min-w-full text-[11px]">
                    <thead>
                        <tr className="text-left uppercase text-[#718096]">
                            {Object.keys(value[0]).map((k) => (
                                <th key={k} className="pb-1 pr-3">
                                    {k.replace(/_/g, ' ')}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {value.slice(0, 25).map((row, i) => (
                            <tr key={i}>
                                {Object.values(row).map((v, j) => (
                                    <td key={j} className="py-1 pr-3 text-[#2D3748]">
                                        {v === null || v === undefined
                                            ? '—'
                                            : typeof v === 'object'
                                              ? JSON.stringify(v)
                                              : String(v)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                {value.length > 25 && (
                    <p className="mt-1 text-[10px] text-[#718096]">
                        {value.length - 25} more row(s) in the sealed archive.
                    </p>
                )}
            </div>
        );
    }

    if (typeof value === 'object') {
        return (
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px]">
                {Object.entries(value).map(([k, v]) => (
                    <div key={k} className="contents">
                        <dt className="text-[#718096]">{k.replace(/_/g, ' ')}</dt>
                        <dd className="text-[#2D3748]">
                            {v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v)}
                        </dd>
                    </div>
                ))}
            </dl>
        );
    }

    return <span className="text-[#2D3748]">{String(value)}</span>;
}

function SignDialog({ show, onClose, ret, minimumConfidence }) {
    const form = useForm({ role: 'Chief Information Security Officer', confirm: false });
    const belowFloor = ret.evidence_confidence < minimumConfidence;

    const submit = (e) => {
        e.preventDefault();
        form.post(route('regulatory-returns.sign', ret.id), { onSuccess: () => onClose() });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="border-b border-gray-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-[#0A1F44]">Sign {ret.code}</h2>
                    <p className="mt-1 text-xs text-[#718096]">
                        Signing hash-seals the return and makes it immutable.
                    </p>
                </div>

                <div className="space-y-4 px-6 py-5">
                    <div
                        className={`rounded-lg border p-3 ${belowFloor ? 'border-[#B3261E]/30 bg-[#B3261E]/5' : 'border-[#E5A100]/30 bg-[#E5A100]/5'}`}
                    >
                        <p className="text-sm font-medium text-[#2D3748]">
                            {ret.evidence_confidence}% of the evidence cited by this return is backed by a record
                            an owner has approved.
                        </p>
                        <p className="mt-1 text-xs text-[#718096]">
                            {belowFloor
                                ? `This is below the ${minimumConfidence}% floor required to sign. Approve the quality seals on the cited records first — the signature attests to data the platform cannot currently vouch for.`
                                : 'False or misleading data in a CBN self-assessment is a regulatory breach under BOFIA 2020. Your name and the evidence position at this moment are both recorded.'}
                        </p>
                    </div>

                    <div>
                        <label className="block text-xs font-medium uppercase tracking-wide text-[#718096]">
                            Signing in the capacity of
                        </label>
                        <input
                            className="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                            value={form.data.role}
                            onChange={(e) => form.setData('role', e.target.value)}
                        />
                    </div>

                    <label className="flex items-start gap-2 text-sm text-[#2D3748]">
                        <input
                            type="checkbox"
                            checked={form.data.confirm}
                            onChange={(e) => form.setData('confirm', e.target.checked)}
                            className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                        />
                        <span>
                            I have reviewed the compiled answers and the evidence behind them, and confirm this
                            return is accurate to the best of my knowledge.
                        </span>
                    </label>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing || !form.data.confirm || belowFloor}>
                        Sign and seal
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

export default function ReturnShow({ return: ret = {}, citations = [], citationSummary = {}, diff, minimumConfidence = 30 }) {
    const perms = useEaPermissions();
    const [signing, setSigning] = useState(false);

    const byQuestion = citations.reduce((acc, c) => {
        (acc[c.question_ref] ||= []).push(c);
        return acc;
    }, {});

    return (
        <AuthenticatedLayout header={ret.code}>
            <Head title={ret.name} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Regulatory Returns', href: route('regulatory-returns.index') },
                    { label: ret.code },
                ]}
                title={ret.name}
                subtitle={[ret.regulator, ret.legal_entity, ret.due_date ? `due ${ret.due_date}` : null]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <>
                        <StatusBadge status={ret.tone} label={ret.state?.replace(/_/g, ' ')} />
                        {!ret.is_sealed && perms.canApprove && (
                            <button
                                type="button"
                                onClick={() => setSigning(true)}
                                className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                            >
                                Sign
                            </button>
                        )}
                        {ret.state === 'signed' && perms.canApprove && (
                            <button
                                type="button"
                                onClick={() => router.post(route('regulatory-returns.submit', ret.id))}
                                className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                            >
                                Mark submitted
                            </button>
                        )}
                        {ret.is_sealed && perms.canExport && (
                            <a
                                href={route('regulatory-returns.download', ret.id)}
                                className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                            >
                                Download sealed archive
                            </a>
                        )}
                    </>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    label="Completeness"
                    value={`${ret.completeness}%`}
                    tone={ret.completeness >= 70 ? 'green' : ret.completeness >= 40 ? 'amber' : 'red'}
                    sublabel="How much the repository could answer"
                />
                <KpiCard
                    label="Evidence confidence"
                    value={`${ret.evidence_confidence}%`}
                    tone={ret.evidence_confidence >= 55 ? 'green' : ret.evidence_confidence >= 30 ? 'amber' : 'red'}
                    sublabel={ret.confidence_band}
                />
                <KpiCard
                    label="Citations"
                    value={citationSummary.total || 0}
                    tone="navy"
                    sublabel={`${citationSummary.approved || 0} approved at capture`}
                />
                <KpiCard
                    label={ret.is_sealed ? 'Signed' : 'Not signed'}
                    value={ret.signed_by || '—'}
                    tone={ret.is_sealed ? 'gold' : 'white'}
                    sublabel={ret.signed_at || ret.generated_at}
                />
            </div>

            {ret.evidence_confidence < minimumConfidence && !ret.is_sealed && (
                <div className="mb-5 rounded-xl border border-[#B3261E]/40 bg-[#B3261E]/5 p-4">
                    <p className="text-sm font-semibold text-[#B3261E]">
                        This return cannot be signed at {ret.evidence_confidence}% evidence confidence
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        Below the {minimumConfidence}% floor, a signature would be attesting to data the platform
                        itself cannot vouch for. Approve the quality seals on the cited records — the citation table
                        below shows which are unapproved — or record the gap explicitly before signing.
                    </p>
                </div>
            )}

            {diff && (
                <div className="mb-5 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">
                        Change since {diff.previous_period} ({diff.previous_code})
                    </h3>
                    <p className="mt-1 text-xs text-[#718096]">
                        Completeness {diff.completeness_delta >= 0 ? '+' : ''}
                        {diff.completeness_delta} pts · evidence confidence{' '}
                        {diff.confidence_delta >= 0 ? '+' : ''}
                        {diff.confidence_delta} pts
                    </p>
                    {diff.changes?.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {diff.changes.slice(0, 12).map((c) => (
                                <span
                                    key={c.metric}
                                    className="rounded-full border border-gray-200 px-2 py-0.5 text-[11px] text-[#2D3748]"
                                >
                                    {c.metric.replace(/_/g, ' ')}: {c.before} → {c.after}
                                    <span className={c.direction === 'up' ? 'text-[#B3261E]' : 'text-[#2D7D46]'}>
                                        {' '}
                                        ({c.delta >= 0 ? '+' : ''}
                                        {c.delta})
                                    </span>
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <div className="space-y-4">
                {(ret.sections || []).map((section) => (
                    <div key={section.ref} className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                        <div className="border-b border-gray-100 px-5 py-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="font-mono text-[10px] uppercase text-[#718096]">{section.ref}</p>
                                    <h3 className="text-sm font-semibold text-[#0A1F44]">{section.title}</h3>
                                </div>
                                <StatusBadge
                                    status={
                                        section.completeness >= 70 ? 'pass' : section.completeness >= 40 ? 'warn' : 'fail'
                                    }
                                    label={`${section.completeness}%`}
                                />
                            </div>
                            <p className="mt-2 text-[11px] italic text-[#718096]">{section.requirement}</p>
                        </div>

                        <div className="px-5 py-4">
                            <Value value={section.answer} />
                        </div>

                        {section.caveat && (
                            <p className="border-t border-[#E5A100]/20 bg-[#E5A100]/5 px-5 py-3 text-xs text-[#8A6400]">
                                {section.caveat}
                            </p>
                        )}

                        {byQuestion[section.ref]?.length > 0 && (
                            <details className="border-t border-gray-100 px-5 py-3">
                                <summary className="cursor-pointer text-xs text-[#718096]">
                                    {byQuestion[section.ref].length} evidence citation(s) ·{' '}
                                    {byQuestion[section.ref].filter((c) => c.was_approved).length} approved at capture
                                </summary>
                                <table className="mt-2 min-w-full text-[11px]">
                                    <thead>
                                        <tr className="text-left uppercase text-[#718096]">
                                            <th className="pb-1 pr-3">Record</th>
                                            <th className="pb-1 pr-3">Type</th>
                                            <th className="pb-1 pr-3">Seal at capture</th>
                                            <th className="pb-1">Complete</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {byQuestion[section.ref].slice(0, 30).map((c) => (
                                            <tr key={c.id}>
                                                <td className="py-1 pr-3 text-[#2D3748]">{c.label}</td>
                                                <td className="py-1 pr-3 text-[#718096]">{c.entity_type || '—'}</td>
                                                <td className="py-1 pr-3">
                                                    <StatusBadge
                                                        status={SEAL_TONE[c.seal_state] || 'draft'}
                                                        label={c.seal_state || 'n/a'}
                                                    />
                                                </td>
                                                <td className="py-1 text-[#718096]">
                                                    {c.completeness !== null ? `${c.completeness}%` : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </details>
                        )}
                    </div>
                ))}
            </div>

            {ret.signoff_chain?.length > 0 && (
                <div className="mt-5 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Sign-off chain</h3>
                    <ul className="mt-3 space-y-2 text-xs">
                        {ret.signoff_chain.map((s, i) => (
                            <li key={i} className="text-[#2D3748]">
                                <span className="font-medium">{s.name}</span> — {s.role}
                                <span className="block text-[10px] text-[#718096]">
                                    {s.signed_at} · evidence confidence at signing {s.evidence_confidence}%
                                </span>
                            </li>
                        ))}
                    </ul>
                    {ret.archive_hash && (
                        <p className="mt-3 break-all font-mono text-[10px] text-[#718096]">{ret.archive_hash}</p>
                    )}
                </div>
            )}

            <SignDialog
                show={signing}
                onClose={() => setSigning(false)}
                ret={ret}
                minimumConfidence={minimumConfidence}
            />
        </AuthenticatedLayout>
    );
}
