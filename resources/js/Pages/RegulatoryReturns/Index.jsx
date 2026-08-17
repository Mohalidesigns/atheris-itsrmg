import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, useForm } from '@inertiajs/react';
import { DocumentCheckIcon } from '@heroicons/react/24/outline';

/**
 * Regulatory Returns — ATH-EAR-002 A1, the flagship.
 *
 * §1.4: "Do not sell an EA tool. **Sell the regulatory architecture return, and
 * ship an EA repository as the machine that produces it.**"
 *
 * §5.5 promotes this out of the EA module: the returns draw on EA, CSAT, Risk,
 * Control, Vendor, Incident and BCP data, and "burying them inside EA hides the
 * product's best feature from the CISO who buys it".
 *
 * §6.2 makes the compliance calendar "the product's clock" — the reason the
 * repository stays current is that a filing deadline maintains data in a way no
 * governance policy ever has.
 */

const CONFIDENCE_TONE = { strong: 'pass', adequate: 'in_progress', weak: 'warn', insufficient: 'fail' };

function CompileDialog({ show, onClose, templates, entities }) {
    const form = useForm({
        template: templates?.[0]?.value || '',
        period: String(new Date().getFullYear()),
        legal_entity_id: entities?.[0]?.value || '',
    });

    const selected = templates?.find((t) => t.value === form.data.template);

    const submit = (e) => {
        e.preventDefault();
        form.post(route('regulatory-returns.compile'), { onSuccess: () => onClose() });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="border-b border-gray-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-[#0A1F44]">Compile a return</h2>
                    <p className="mt-1 text-xs text-[#718096]">
                        The return is compiled from the repository as it stands now. Every answer carries a
                        citation, and the seal state of each cited record is captured at the moment of compiling.
                    </p>
                </div>

                <div className="space-y-4 px-6 py-5">
                    <div>
                        <label className="block text-xs font-medium uppercase tracking-wide text-[#718096]">
                            Return
                        </label>
                        <select
                            className="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                            value={form.data.template}
                            onChange={(e) => form.setData('template', e.target.value)}
                        >
                            {templates?.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                        {selected && (
                            <p className="mt-1.5 text-[11px] text-[#718096]">
                                {selected.description} · {selected.regulator} · {selected.cadence}
                            </p>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium uppercase tracking-wide text-[#718096]">
                                Period
                            </label>
                            <input
                                className="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                value={form.data.period}
                                onChange={(e) => form.setData('period', e.target.value)}
                                placeholder="2026 or 2026-Q3"
                            />
                            {form.errors.period && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.period}</p>
                            )}
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase tracking-wide text-[#718096]">
                                Legal entity
                            </label>
                            <select
                                className="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                value={form.data.legal_entity_id}
                                onChange={(e) => form.setData('legal_entity_id', e.target.value)}
                            >
                                <option value="">Group-wide</option>
                                {entities?.map((e) => (
                                    <option key={e.value} value={e.value}>
                                        {e.label}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-1 text-[11px] text-[#718096]">
                                The ITSB maturity target depends on the entity's licence class.
                            </p>
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>
                        {form.processing ? 'Compiling…' : 'Compile'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

export default function ReturnsIndex({
    returns = [],
    templates = [],
    entities = [],
    calendar = [],
    summary = {},
}) {
    const perms = useEaPermissions();
    const [compiling, setCompiling] = useState(false);

    return (
        <AuthenticatedLayout header="Regulatory Returns">
            <Head title="Regulatory Returns" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Returns' }]}
                title="Regulatory Returns"
                subtitle="Each submission compiled from the architecture repository, with an evidence citation behind every answer."
                actions={
                    perms.canCreate && (
                        <button
                            type="button"
                            onClick={() => setCompiling(true)}
                            className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                        >
                            Compile a return
                        </button>
                    )
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Returns" value={summary.total || 0} tone="navy" />
                <KpiCard label="In preparation" value={summary.in_preparation || 0} tone="gold" />
                <KpiCard label="Signed" value={summary.signed || 0} tone="green" />
                <KpiCard label="Overdue" value={summary.overdue || 0} tone={summary.overdue ? 'red' : 'white'} />
                <KpiCard
                    label="Below signing floor"
                    value={summary.low_confidence || 0}
                    tone={summary.low_confidence ? 'amber' : 'white'}
                    sublabel="Evidence confidence too low"
                />
            </div>

            <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Compliance calendar</h3>
                    <p className="mt-0.5 text-xs text-[#718096]">
                        The obligations that set the clock. A filing deadline maintains architecture data in a way
                        no governance policy has managed.
                    </p>
                </div>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Obligation</th>
                            <th className="px-3 py-2">Instrument</th>
                            <th className="px-3 py-2">Cadence</th>
                            <th className="px-3 py-2">Next due</th>
                            <th className="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {calendar.map((c, i) => (
                            <tr key={i} className="hover:bg-gray-50/60">
                                <td className="px-3 py-2 text-[#2D3748]">
                                    {c.obligation}
                                    {c.caveat && (
                                        <span
                                            className="ml-1 cursor-help text-[#E5A100]"
                                            title={c.caveat}
                                            aria-label={c.caveat}
                                        >
                                            ⚠
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.instrument}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.cadence}</td>
                                <td className="px-3 py-2 text-xs">
                                    {c.due || '—'}
                                    {c.days_remaining !== null && c.days_remaining !== undefined && (
                                        <span
                                            className={`block text-[10px] ${c.days_remaining < 0 ? 'text-[#B3261E]' : c.days_remaining <= 90 ? 'text-[#8A6400]' : 'text-[#718096]'}`}
                                        >
                                            {c.days_remaining < 0
                                                ? `${Math.abs(c.days_remaining)}d overdue`
                                                : `${c.days_remaining}d`}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {c.template && perms.canCreate && (
                                        <button
                                            type="button"
                                            onClick={() => setCompiling(true)}
                                            className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                        >
                                            Compile
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {returns.length === 0 ? (
                <EaEmptyState
                    icon={DocumentCheckIcon}
                    title="No returns compiled yet"
                    description="A return is a compiled artefact of the architecture graph plus the GRC data — not a document somebody types. Compile one to see how much of it the repository can already answer, and which answers are backed by evidence an owner has approved."
                    actionLabel="Compile the first return"
                    onAction={() => setCompiling(true)}
                    canAct={perms.canCreate}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Compiled returns
                    </h3>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Code</th>
                                    <th className="px-3 py-2">Return</th>
                                    <th className="px-3 py-2">Entity</th>
                                    <th className="px-3 py-2">Period</th>
                                    <th className="px-3 py-2">Due</th>
                                    <th className="px-3 py-2">State</th>
                                    <th className="px-3 py-2">Complete</th>
                                    <th className="px-3 py-2">Evidence</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {returns.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50/60">
                                        <td className="px-3 py-2 font-mono text-xs">
                                            <Link
                                                href={route('regulatory-returns.show', r.id)}
                                                className="text-[#0A1F44] hover:underline"
                                            >
                                                {r.code}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 text-[#2D3748]">
                                            {r.name}
                                            <span className="block text-[10px] text-[#718096]">{r.regulator}</span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {r.legal_entity || 'Group-wide'}
                                        </td>
                                        <td className="px-3 py-2 text-xs">{r.period}</td>
                                        <td className="px-3 py-2 text-xs">
                                            {r.due_date || '—'}
                                            {r.is_overdue && (
                                                <span className="block text-[10px] text-[#B3261E]">overdue</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={r.tone} label={r.state.replace(/_/g, ' ')} />
                                        </td>
                                        <td className="px-3 py-2 text-xs">{r.completeness}%</td>
                                        <td className="px-3 py-2">
                                            <StatusBadge
                                                status={CONFIDENCE_TONE[r.confidence_band] || 'draft'}
                                                label={`${r.evidence_confidence}%`}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <CompileDialog
                show={compiling}
                onClose={() => setCompiling(false)}
                templates={templates}
                entities={entities}
            />
        </AuthenticatedLayout>
    );
}
