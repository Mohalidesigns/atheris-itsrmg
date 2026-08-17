import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * PlateauDiff — WS 4.3 / B15.
 *
 * §5.1 deleted the page this replaces: "a 93 LOC read-only comparer with no
 * authoring. Shipping a 'Scenario Compare' that cannot create a scenario invites
 * the comparison to Ardoq we lose."
 *
 * §11 wedge 3 is the commercial case: "33 recapitalised banks, mergers in
 * flight." A board approving a consolidation programme wants four numbers — what
 * it saves, what it costs to get there, what risk it closes, and what capability
 * it might accidentally lose. Those are the four panels below, in that order.
 */

const naira = (value) => {
    if (value === null || value === undefined) return '—';
    const number = Number(value);
    const sign = number < 0 ? '−' : '';
    const magnitude = Math.abs(number);
    if (magnitude >= 1_000_000_000) return `${sign}₦${(magnitude / 1_000_000_000).toFixed(2)}bn`;
    if (magnitude >= 1_000_000) return `${sign}₦${(magnitude / 1_000_000).toFixed(1)}m`;
    return `${sign}₦${magnitude.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
};

const DISPOSITION_TONES = {
    retain: 'bg-gray-100 text-gray-600',
    introduce: 'bg-[#2D7D46]/10 text-[#2D7D46]',
    modify: 'bg-[#1D4ED8]/10 text-[#1D4ED8]',
    replace: 'bg-[#E5A100]/10 text-[#8a6100]',
    retire: 'bg-[#B3261E]/10 text-[#B3261E]',
};

export default function PlateauDiff({ plateaux = [], diff, from, to, dispositions = [] }) {
    const [tab, setTab] = useState('summary');

    const swap = (params) => router.get(route('ea.plateau-diff'), { from, to, ...params }, {
        preserveState: true, preserveScroll: true,
    });

    const summary = diff?.summary || {};
    const cost = diff?.cost || {};

    return (
        <AuthenticatedLayout header="Plateau Diff">
            <Head title="Plateau Diff" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Transformation' }, { label: 'Plateau Diff' }]}
                title="Plateau Diff — scenario comparison"
                subtitle="Compare two architecture plateaux across counts, cost, risk, technical debt, capability coverage and data residency. Membership is authored per plateau with a disposition, so a scenario can be built rather than only viewed."
                actions={
                    diff && (
                        <Link
                            href={route('ea.plateaux.membership', diff.to.id)}
                            className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white"
                        >
                            Edit {diff.to.name} membership
                        </Link>
                    )
                }
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.plateaux')} current="ea.plateau-diff" />

            <div className="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-2">
                {[['from', from, 'Baseline'], ['to', to, 'Comparison']].map(([key, value, label]) => (
                    <label key={key} className="text-xs">
                        <span className="mb-1 block font-medium text-gray-500">{label}</span>
                        <select
                            value={value || ''}
                            onChange={(e) => swap({ [key]: e.target.value })}
                            className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                        >
                            {plateaux.map((plateau) => (
                                <option key={plateau.id} value={plateau.id}>
                                    {plateau.name} ({plateau.plateau_type}) — {plateau.members} member(s)
                                </option>
                            ))}
                        </select>
                    </label>
                ))}
            </div>

            {!diff && (
                <div className="rounded-xl border border-gray-100 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                    Pick two different plateaux to compare.
                </div>
            )}

            {diff && (
                <>
                    <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <KpiCard
                            label="Annual cost delta"
                            value={naira(summary.annual_cost_delta_ngn)}
                            tone={summary.annual_cost_delta_ngn < 0 ? 'green' : 'red'}
                            sublabel={cost.delta_percent !== null && cost.delta_percent !== undefined ? `${cost.delta_percent}% vs baseline` : 'no baseline cost'}
                        />
                        <KpiCard label="One-off cost to get there" value={naira(summary.one_off_cost_ngn)} tone="gold"
                            sublabel={cost.payback_years ? `payback ${cost.payback_years} year(s)` : 'no recurring saving'} />
                        <KpiCard label="Systems retired" value={summary.removed ?? 0} tone="navy"
                            sublabel={`${summary.added ?? 0} introduced · ${summary.changed ?? 0} changed`} />
                        <KpiCard
                            label="Obsolescence risks closable"
                            value={diff.risk?.risks_closable ?? 0}
                            tone={(diff.risk?.risks_closable ?? 0) > 0 ? 'green' : 'white'}
                            sublabel="open register entries the retirements clear"
                        />
                        <KpiCard
                            label="Mean technical debt"
                            value={diff.debt?.to ?? '—'}
                            tone={(diff.debt?.delta ?? 0) < 0 ? 'green' : 'white'}
                            sublabel={diff.debt?.delta !== null && diff.debt?.delta !== undefined ? `${diff.debt.delta > 0 ? '+' : ''}${diff.debt.delta} vs baseline` : ''}
                        />
                        <KpiCard
                            label="Cost confidence"
                            value={`${summary.cost_confidence_percent ?? 0}%`}
                            tone={(summary.cost_confidence_percent ?? 0) >= 80 ? 'green' : 'amber'}
                            sublabel="share of members with a resolvable cost"
                        />
                    </div>

                    {(diff.capability_coverage?.critical_lost || []).length > 0 && (
                        <div className="mb-5 rounded-xl border border-[#B3261E]/40 bg-[#B3261E]/5 p-4">
                            <h3 className="text-sm font-bold text-[#B3261E]">
                                Stop: {diff.capability_coverage.critical_lost.length} critical capability(ies) lose all realising systems
                            </h3>
                            <p className="mt-1 text-xs text-[#8a1c16]">
                                Every application mapped to these capabilities is retired or replaced in {diff.to.name},
                                and nothing in the target state realises them. This is the mistake that makes a
                                cost-driven consolidation programme fail.
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2 text-xs">
                                {diff.capability_coverage.critical_lost.map((capability) => (
                                    <span key={capability.id} className="rounded-full border border-[#B3261E]/30 bg-white px-2 py-1 font-medium">
                                        {capability.code} — {capability.name}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="mb-4 flex flex-wrap items-center gap-1 border-b border-gray-200">
                        {[
                            ['summary', 'Cost & risk'],
                            ['entities', 'Entity deltas'],
                            ['capabilities', 'Capability coverage'],
                            ['residency', 'Residency & FX'],
                            ['initiatives', 'Transition path'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                onClick={() => setTab(key)}
                                className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium ${
                                    tab === key ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-gray-500 hover:text-gray-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {tab === 'summary' && (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-3 text-sm font-bold text-[#0A1F44]">Annual cost</h3>
                                <table className="w-full text-sm">
                                    <tbody>
                                        <tr className="border-b border-gray-50">
                                            <td className="py-2 text-gray-500">{diff.from.name}</td>
                                            <td className="text-right font-medium">{naira(cost.from_ngn)}</td>
                                        </tr>
                                        <tr className="border-b border-gray-50">
                                            <td className="py-2 text-gray-500">{diff.to.name}</td>
                                            <td className="text-right font-medium">{naira(cost.to_ngn)}</td>
                                        </tr>
                                        <tr className="border-b border-gray-50">
                                            <td className="py-2 font-medium text-[#0A1F44]">Delta</td>
                                            <td className={`text-right font-bold ${cost.delta_ngn < 0 ? 'text-[#2D7D46]' : 'text-[#B3261E]'}`}>
                                                {naira(cost.delta_ngn)}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td className="py-2 text-gray-500">One-off investment</td>
                                            <td className="text-right font-medium">{naira(cost.one_off_ngn)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p className="mt-3 text-[11px] leading-snug text-gray-400">
                                    A member's own target cost is used where one is recorded; otherwise the entity's
                                    modelled TCO stands, on the basis that an unmodified system costs what it costs
                                    today. {cost.without_cost?.to ? `${cost.without_cost.to} member(s) of the comparison plateau have no resolvable cost and are excluded.` : 'Every member has a resolvable cost.'}
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-3 text-sm font-bold text-[#0A1F44]">Risk</h3>
                                <dl className="space-y-2 text-sm">
                                    <div className="flex items-center justify-between border-b border-gray-50 pb-1">
                                        <dt className="text-gray-500">Past end-of-life technology</dt>
                                        <dd className="font-medium">
                                            {diff.risk?.obsolete_tech?.from} → {diff.risk?.obsolete_tech?.to}
                                            <span className={`ml-2 text-xs ${diff.risk?.obsolete_tech?.delta < 0 ? 'text-[#2D7D46]' : 'text-[#B3261E]'}`}>
                                                ({diff.risk?.obsolete_tech?.delta > 0 ? '+' : ''}{diff.risk?.obsolete_tech?.delta})
                                            </span>
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-gray-50 pb-1">
                                        <dt className="text-gray-500">Critical applications</dt>
                                        <dd className="font-medium">
                                            {diff.risk?.critical_applications?.from} → {diff.risk?.critical_applications?.to}
                                        </dd>
                                    </div>
                                </dl>

                                <h4 className="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Register entries this programme closes
                                </h4>
                                <ul className="max-h-48 space-y-1 overflow-y-auto text-xs">
                                    {(diff.risk?.risks_closable_detail || []).map((risk) => (
                                        <li key={risk.id} className="flex items-center justify-between border-b border-gray-50 py-1">
                                            <span className="text-gray-600">{risk.title}</span>
                                            <span className="ml-2 font-mono text-[10px] text-gray-400">{risk.risk_id_code}</span>
                                        </li>
                                    ))}
                                    {!(diff.risk?.risks_closable_detail || []).length && (
                                        <li className="text-gray-400">
                                            No open obsolescence risks are cleared by this scenario.
                                        </li>
                                    )}
                                </ul>
                                <p className="mt-2 text-[11px] text-gray-400">
                                    These are real entries in the risk register, opened automatically by the
                                    obsolescence contract — not a modelled score.
                                </p>
                            </div>
                        </div>
                    )}

                    {tab === 'entities' && (
                        <div className="space-y-4">
                            {(diff.entities || []).map((group) => (
                                <div key={group.entity_type} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h3 className="text-sm font-bold text-[#0A1F44]">{group.label}</h3>
                                        <span className="text-xs text-gray-500">
                                            {group.from_count} → {group.to_count}
                                            <span className={`ml-2 font-medium ${group.count_delta < 0 ? 'text-[#2D7D46]' : group.count_delta > 0 ? 'text-[#1D4ED8]' : ''}`}>
                                                ({group.count_delta > 0 ? '+' : ''}{group.count_delta})
                                            </span>
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        {[
                                            ['Introduced', group.added, 'text-[#2D7D46]'],
                                            ['Removed', group.removed, 'text-[#B3261E]'],
                                            ['Changed in place', group.changed, 'text-[#1D4ED8]'],
                                        ].map(([label, rows, tone]) => (
                                            <div key={label}>
                                                <h4 className={`mb-1 text-xs font-semibold ${tone}`}>{label} ({rows.length})</h4>
                                                <ul className="max-h-52 space-y-1 overflow-y-auto text-xs">
                                                    {rows.map((row) => (
                                                        <li key={row.id} className="border-b border-gray-50 pb-1">
                                                            <div className="text-gray-700">{row.name}</div>
                                                            {row.disposition && (
                                                                <span className={`mt-0.5 inline-block rounded px-1.5 py-0.5 text-[10px] ${DISPOSITION_TONES[row.disposition] || ''}`}>
                                                                    {row.disposition}
                                                                </span>
                                                            )}
                                                            {row.rationale && <div className="text-[10px] text-gray-400">{row.rationale}</div>}
                                                            {row.changes && (
                                                                <div className="text-[10px] text-gray-400">
                                                                    {Object.entries(row.changes).map(([field, [before, after]]) => (
                                                                        <div key={field}>{field}: {String(before ?? '—')} → {String(after ?? '—')}</div>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </li>
                                                    ))}
                                                    {rows.length === 0 && <li className="text-gray-300">None.</li>}
                                                </ul>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'capabilities' && (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#B3261E]">
                                    Loses all realising systems ({(diff.capability_coverage?.lost || []).length})
                                </h3>
                                <p className="mb-3 text-[11px] text-gray-400">
                                    Realised in {diff.from.name} but by nothing in {diff.to.name}. Either the target
                                    state genuinely exits the business line, or a system is being retired without a
                                    successor.
                                </p>
                                <ul className="max-h-72 space-y-1 overflow-y-auto text-xs">
                                    {(diff.capability_coverage?.lost || []).map((capability) => (
                                        <li key={capability.id} className="flex items-center justify-between border-b border-gray-50 py-1">
                                            <span>{capability.code} — {capability.name}</span>
                                            <span className="text-[10px] text-gray-400">{capability.criticality}</span>
                                        </li>
                                    ))}
                                    {!(diff.capability_coverage?.lost || []).length && (
                                        <li className="text-[#2D7D46]">No capability loses its coverage.</li>
                                    )}
                                </ul>
                            </div>
                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#2D7D46]">
                                    Newly realised ({(diff.capability_coverage?.gained || []).length})
                                </h3>
                                <p className="mb-3 text-[11px] text-gray-400">
                                    Capabilities that gain a realising system — {diff.capability_coverage?.from_count} covered
                                    in the baseline, {diff.capability_coverage?.to_count} in the comparison.
                                </p>
                                <ul className="max-h-72 space-y-1 overflow-y-auto text-xs">
                                    {(diff.capability_coverage?.gained || []).map((capability) => (
                                        <li key={capability.id} className="border-b border-gray-50 py-1">
                                            {capability.code} — {capability.name}
                                        </li>
                                    ))}
                                    {!(diff.capability_coverage?.gained || []).length && (
                                        <li className="text-gray-400">None.</li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    )}

                    {tab === 'residency' && (
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">Data residency posture</h3>
                            <p className="mb-3 text-[11px] text-gray-400">
                                A cost-driven target state can quietly move Nigerian payment data offshore. This is the
                                same lens the Residency &amp; Localisation register applies, run over a scenario instead
                                of the live estate.
                            </p>
                            <table className="w-full text-sm">
                                <thead className="border-b border-gray-100 text-xs text-gray-500">
                                    <tr>
                                        <th className="py-2 text-left">Measure</th>
                                        <th className="text-right">{diff.from.name}</th>
                                        <th className="text-right">{diff.to.name}</th>
                                        <th className="text-right">Delta</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {[
                                        ['offshore', 'Hosted outside Nigeria'],
                                        ['offshore_with_payment_data', 'Offshore and holding Nigerian payment data'],
                                        ['dr_offshore', 'DR site outside Nigeria'],
                                        ['unknown', 'Hosting country not recorded'],
                                    ].map(([key, label]) => {
                                        const delta = diff.residency?.delta?.[key] ?? 0;
                                        return (
                                            <tr key={key} className="border-b border-gray-50">
                                                <td className="py-2 text-gray-600">{label}</td>
                                                <td className="text-right">{diff.residency?.from?.[key] ?? 0}</td>
                                                <td className="text-right font-medium">{diff.residency?.to?.[key] ?? 0}</td>
                                                <td className={`text-right font-medium ${delta < 0 ? 'text-[#2D7D46]' : delta > 0 ? 'text-[#B3261E]' : 'text-gray-400'}`}>
                                                    {delta > 0 ? '+' : ''}{delta}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tab === 'initiatives' && (
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-bold text-[#0A1F44]">
                                    Initiatives targeting {diff.to.name} ({diff.initiatives?.count ?? 0})
                                </h3>
                                <div className="text-xs text-gray-500">
                                    Budget {naira(diff.initiatives?.budget_ngn)} ·{' '}
                                    {diff.initiatives?.unfunded ?? 0} unfunded ·{' '}
                                    {diff.initiatives?.not_started ?? 0} not started
                                    {diff.initiatives?.latest_end_date ? ` · last delivery ${diff.initiatives.latest_end_date}` : ''}
                                </div>
                            </div>
                            <table className="w-full text-sm">
                                <thead className="border-b border-gray-100 text-xs text-gray-500">
                                    <tr>
                                        <th className="py-2 text-left">Code</th>
                                        <th className="text-left">Initiative</th>
                                        <th className="text-left">Status</th>
                                        <th className="text-left">ADM</th>
                                        <th className="text-right">Budget</th>
                                        <th className="text-right">Progress</th>
                                        <th className="text-left">Target end</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(diff.initiatives?.targeting_to || []).map((initiative) => (
                                        <tr key={initiative.id} className="border-b border-gray-50">
                                            <td className="py-2 font-mono text-xs">{initiative.code}</td>
                                            <td>{initiative.name}</td>
                                            <td className="text-xs">{initiative.status}</td>
                                            <td className="text-xs">{initiative.adm_phase}</td>
                                            <td className="text-right text-xs">{naira(initiative.budget_ngn)}</td>
                                            <td className="text-right text-xs">{initiative.progress_percent}%</td>
                                            <td className="text-xs">{initiative.target_end_date || '—'}</td>
                                        </tr>
                                    ))}
                                    {!(diff.initiatives?.targeting_to || []).length && (
                                        <tr><td colSpan={7} className="py-6 text-center text-xs text-gray-400">
                                            No initiative targets this plateau — the target state has no funded path to it.
                                        </td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                        {[diff.from, diff.to].map((plateau) => (
                            <div key={plateau.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <div className="mb-2 flex items-center justify-between">
                                    <h3 className="text-sm font-bold text-[#0A1F44]">{plateau.name}</h3>
                                    <Link href={route('ea.plateaux.membership', plateau.id)} className="text-xs text-[#0A1F44] underline">
                                        Edit membership
                                    </Link>
                                </div>
                                <p className="mb-2 text-xs text-gray-500">
                                    {plateau.plateau_type} · {plateau.effective_from || 'no start date'} · {plateau.membership_rows} membership row(s)
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {dispositions.map((disposition) => (
                                        <span key={disposition} className={`rounded px-1.5 py-0.5 text-[10px] ${DISPOSITION_TONES[disposition] || ''}`}>
                                            {disposition} {plateau.dispositions?.[disposition] ?? 0}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
