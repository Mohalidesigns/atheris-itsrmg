import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * CostModel — WS 4.7 / B17: "cost & TCO algorithms beyond FX — cost per
 * capability, TCO, technical debt scoring."
 *
 * §11 makes the CFO the third buyer: "the CIO gets portfolio, radar, ARB and
 * roadmap; the CISO gets CSAT evidence…; the CFO gets the FX exposure lens — all
 * from one dataset, priced once."
 *
 * Every total on this page carries its coverage. A TCO figure computed over an
 * estate where a third of applications have no recorded cost is not a TCO
 * figure, and §10's evidence-integrity gate applies to money as much as to
 * controls.
 */

const naira = (value, compact = true) => {
    if (value === null || value === undefined) return '—';
    const number = Number(value);
    if (!compact) return `₦${number.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
    const magnitude = Math.abs(number);
    if (magnitude >= 1_000_000_000) return `₦${(number / 1_000_000_000).toFixed(2)}bn`;
    if (magnitude >= 1_000_000) return `₦${(number / 1_000_000).toFixed(1)}m`;
    return `₦${number.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
};

const DEBT_TONES = {
    severe: 'bg-[#B3261E]/10 text-[#B3261E]',
    high: 'bg-[#E5A100]/15 text-[#8a6100]',
    moderate: 'bg-[#1D4ED8]/10 text-[#1D4ED8]',
    low: 'bg-[#2D7D46]/10 text-[#2D7D46]',
};

const VIEWS = [
    ['portfolio', 'Portfolio & technical debt'],
    ['per_capability', 'Cost per capability'],
    ['per_process', 'Cost per process'],
    ['rationalisation', 'Rationalisation candidates'],
];

export default function CostModel({ view, portfolio, perCapability, perProcess, candidates, fxRates = {} }) {
    const [search, setSearch] = useState('');
    const totals = portfolio?.totals || {};
    const factors = portfolio?.factors || {};

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();
        const list = portfolio?.applications || [];
        return term ? list.filter((row) => `${row.name} ${row.code}`.toLowerCase().includes(term)) : list;
    }, [portfolio, search]);

    return (
        <AuthenticatedLayout header="Cost & TCO">
            <Head title="Cost & TCO" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Portfolio' }, { label: 'Cost & TCO' }]}
                title="Cost, TCO and technical debt"
                subtitle="Licence cost is the number a bank has; total cost of ownership is the number it needs. Uplift factors are explicit and configurable rather than a single magic multiplier, so each one can be argued with."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.applications')} current="ea.cost-model" />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <KpiCard label="Licence / subscription" value={naira(totals.licence_ngn)} tone="white"
                    sublabel={`${totals.application_count} applications`} />
                <KpiCard label="Modelled TCO" value={naira(totals.tco_ngn)} tone="navy"
                    sublabel={totals.tco_uplift_ratio ? `${totals.tco_uplift_ratio}× licence` : ''} />
                <KpiCard
                    label="Cost coverage"
                    value={`${totals.cost_coverage_percent ?? 0}%`}
                    tone={(totals.cost_coverage_percent ?? 0) >= 80 ? 'green' : 'amber'}
                    sublabel={`${totals.no_cost_recorded ?? 0} without cost · ${totals.unknown_currency ?? 0} unknown currency`}
                />
                <KpiCard label="Mean technical debt" value={totals.mean_debt_score ?? 0} tone="gold" sublabel="0 = clean, 100 = retire now" />
                <KpiCard label="Severe debt" value={totals.severe_debt ?? 0}
                    tone={(totals.severe_debt ?? 0) > 0 ? 'red' : 'white'} sublabel="score ≥ 60" />
                <KpiCard label="Recorded vs modelled TCO" value={`${totals.recorded_tco ?? 0} / ${totals.modelled_tco ?? 0}`}
                    tone="white" sublabel="a recorded figure always wins" />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-1 border-b border-gray-200">
                {VIEWS.map(([key, label]) => (
                    <button
                        key={key}
                        onClick={() => router.get(route('ea.cost-model'), { view: key }, { preserveScroll: true })}
                        className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium ${
                            view === key ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {view === 'portfolio' && (
                <>
                    <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-[1fr,320px]">
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div className="mb-3 flex items-center justify-between">
                                <h3 className="text-sm font-bold text-[#0A1F44]">Applications by modelled TCO</h3>
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Filter…"
                                    className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                />
                            </div>
                            <div className="max-h-[560px] overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 border-b border-gray-100 bg-white text-xs text-gray-500">
                                        <tr>
                                            <th className="py-2 text-left">Application</th>
                                            <th className="text-left">Criticality</th>
                                            <th className="text-right">Licence</th>
                                            <th className="text-right">TCO</th>
                                            <th className="text-right">Per user</th>
                                            <th className="text-left">Debt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row) => (
                                            <tr key={row.id} className="border-b border-gray-50 align-top">
                                                <td className="py-2">
                                                    <Link href={route('ea.applications.show', row.id)} className="text-[#0A1F44] hover:underline">
                                                        {row.name}
                                                    </Link>
                                                    <div className="text-[10px] text-gray-400">
                                                        {row.code} · {row.lifecycle}{row.currency ? ` · ${row.currency}` : ''}
                                                        {row.tco_basis !== 'modelled' && row.tco_basis !== 'recorded' && (
                                                            <span className="ml-1 text-[#B3261E]">{row.tco_basis.replace(/_/g, ' ')}</span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="text-xs">{row.criticality}</td>
                                                <td className="text-right text-xs">{naira(row.licence_ngn)}</td>
                                                <td className="text-right text-sm font-medium">{naira(row.tco_ngn)}</td>
                                                <td className="text-right text-xs text-gray-500">{naira(row.cost_per_user)}</td>
                                                <td>
                                                    <span className={`rounded px-1.5 py-0.5 text-[10px] ${DEBT_TONES[row.debt_band]}`}>
                                                        {row.debt_score} {row.debt_band}
                                                    </span>
                                                    {row.debt_drivers?.length > 0 && (
                                                        <div className="mt-0.5 max-w-[220px] text-[10px] text-gray-400">
                                                            {row.debt_drivers.join(' · ')}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">TCO uplift factors</h3>
                                <p className="mb-2 text-[11px] text-gray-400">
                                    As multiples of recorded licence cost. Configurable in <code>config/ea.php</code>,
                                    for the same reason the FX rates are: a factor compiled into code is a wrong number
                                    with a deployment cycle attached.
                                </p>
                                <dl className="space-y-1 text-xs">
                                    {['infrastructure', 'support', 'internal_effort', 'integration'].map((key) => (
                                        <div key={key} className="flex justify-between border-b border-gray-50 py-1">
                                            <dt className="capitalize text-gray-500">{key.replace(/_/g, ' ')}</dt>
                                            <dd className="font-medium">×{factors[key]}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <h4 className="mt-3 mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                    Criticality uplift on infrastructure
                                </h4>
                                <dl className="space-y-1 text-xs">
                                    {Object.entries(factors.criticality_uplift || {}).map(([band, value]) => (
                                        <div key={band} className="flex justify-between">
                                            <dt className="capitalize text-gray-500">{band}</dt>
                                            <dd>+{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="mt-2 text-[11px] text-gray-400">
                                    A critical system carries hot DR capacity; a low-criticality one does not.
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Reference rates</h3>
                                <dl className="space-y-1 text-xs">
                                    {Object.entries(fxRates).map(([currency, rate]) => (
                                        <div key={currency} className="flex justify-between border-b border-gray-50 py-1">
                                            <dt className="text-gray-500">{currency}</dt>
                                            <dd className="font-medium">₦{Number(rate).toLocaleString()}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="mt-2 text-[11px] text-gray-400">
                                    A cost in a currency with no rate is reported as unknown rather than added to the
                                    naira total — a dollar figure counted as naira understates the exposure roughly
                                    1,550-fold.
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">What the debt score uses</h3>
                                <ul className="space-y-1 text-[11px] text-gray-500">
                                    <li>• Technical fit assessment (0–30)</li>
                                    <li>• Lifecycle — sunsetting but still in service (0–15)</li>
                                    <li>• Share of technology dependencies past end-of-life (0–25)</li>
                                    <li>• End-of-life dates inside 12 months (0–15)</li>
                                    <li>• Critical system on a single external vendor (0–15)</li>
                                </ul>
                                <p className="mt-2 text-[11px] text-gray-400">
                                    Deliberately no code-quality dimension: the module has no visibility of source, and
                                    a fabricated fifth signal would discredit the four real ones.
                                </p>
                            </div>
                        </div>
                    </div>
                </>
            )}

            {view === 'per_capability' && perCapability && (
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-bold text-[#0A1F44]">Cost per capability</h3>
                        <span className="text-xs text-gray-500">
                            Unallocated: {naira(perCapability.unallocated?.cost_ngn)} across{' '}
                            {perCapability.unallocated?.application_count} application(s) mapped to no capability
                        </span>
                    </div>
                    <p className="mb-3 text-[11px] text-gray-400">
                        Rolled up over the whole capability subtree via the materialised closure — “what do we spend on
                        payments” means the payments branch, not just the L1 node's direct links. Where one application
                        realises several capabilities its cost is split by the recorded allocation weight.
                    </p>
                    <div className="max-h-[560px] overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 border-b border-gray-100 bg-white text-xs text-gray-500">
                                <tr>
                                    <th className="py-2 text-left">Capability</th>
                                    <th className="text-left">L</th>
                                    <th className="text-right">Direct</th>
                                    <th className="text-right">Rolled up</th>
                                    <th className="text-right">Apps</th>
                                    <th className="text-right">Sub-capabilities</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perCapability.capabilities.map((row) => (
                                    <tr key={row.id} className="border-b border-gray-50">
                                        <td className="py-2">
                                            <Link href={route('ea.capabilities.show', row.id)} className="text-[#0A1F44] hover:underline">
                                                {row.code} — {row.name}
                                            </Link>
                                        </td>
                                        <td className="text-xs text-gray-400">{row.level}</td>
                                        <td className="text-right text-xs">{naira(row.direct_cost_ngn)}</td>
                                        <td className="text-right text-sm font-medium">{naira(row.rolled_cost_ngn)}</td>
                                        <td className="text-right text-xs">{row.application_count}</td>
                                        <td className="text-right text-xs text-gray-400">{row.descendant_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {view === 'per_process' && perProcess && (
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">Cost per business process</h3>
                    <p className="mb-3 text-[11px] text-gray-400">
                        An application's cost is split evenly across the processes it serves — a system supporting ten
                        processes does not cost ten times over. Recovery objectives come from the BIA through the
                        business-continuity contract, so the cost and the criticality are describing the same process.
                    </p>
                    <div className="max-h-[560px] overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 border-b border-gray-100 bg-white text-xs text-gray-500">
                                <tr>
                                    <th className="py-2 text-left">Process</th>
                                    <th className="text-left">Level</th>
                                    <th className="text-left">Criticality</th>
                                    <th className="text-right">RTO</th>
                                    <th className="text-right">Applications</th>
                                    <th className="text-right">Allocated cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perProcess.map((row) => (
                                    <tr key={row.id} className="border-b border-gray-50">
                                        <td className="py-2">{row.code} — {row.name}</td>
                                        <td className="text-xs text-gray-400">L{row.level}</td>
                                        <td className="text-xs">{row.criticality}</td>
                                        <td className="text-right text-xs">{row.rto_hours ? `${row.rto_hours}h` : '—'}</td>
                                        <td className="text-right text-xs">{row.application_count}</td>
                                        <td className="text-right text-sm font-medium">{naira(row.cost_ngn)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {view === 'rationalisation' && candidates && (
                <div className="space-y-4">
                    <div className="rounded-xl border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-4">
                        <h3 className="text-sm font-bold text-[#0A1F44]">
                            {candidates.length} capability(ies) covered by more than one live application
                        </h3>
                        <p className="mt-1 text-xs text-gray-600">
                            Total annual saving on offer:{' '}
                            <span className="font-bold">
                                {naira(candidates.reduce((sum, c) => sum + Number(c.annual_saving_ngn || 0), 0))}
                            </span>
                            . The survivor proposed is the best-fitting system, not the cheapest — a bank does not
                            consolidate onto its weakest platform to save money. Take a candidate into a scenario from
                            a plateau's membership screen, where the decision is recorded with its rationale.
                        </p>
                    </div>

                    {candidates.map((candidate) => (
                        <div key={candidate.capability.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-bold text-[#0A1F44]">
                                    {candidate.capability.code} — {candidate.capability.name}
                                </h4>
                                <span className="text-sm font-bold text-[#2D7D46]">
                                    {naira(candidate.annual_saving_ngn)} / year
                                    {candidate.displaced_without_cost > 0 && (
                                        <span className="ml-2 text-[11px] font-normal text-[#8a6100]">
                                            + {candidate.displaced_without_cost} displaced system(s) with no recorded cost
                                        </span>
                                    )}
                                </span>
                            </div>
                            <table className="w-full text-sm">
                                <thead className="border-b border-gray-100 text-xs text-gray-500">
                                    <tr>
                                        <th className="py-1.5 text-left">Application</th>
                                        <th className="text-left">Role</th>
                                        <th className="text-left">Fit (business / technical)</th>
                                        <th className="text-left">TIME</th>
                                        <th className="text-right">TCO</th>
                                        <th className="text-right">Debt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-b border-gray-50 bg-[#2D7D46]/5">
                                        <td className="py-1.5 font-medium">{candidate.survivor?.name}</td>
                                        <td className="text-xs text-[#2D7D46]">proposed survivor</td>
                                        <td className="text-xs">{candidate.survivor?.business_fit} / {candidate.survivor?.technical_fit}</td>
                                        <td className="text-xs">{candidate.survivor?.time_score || '—'}</td>
                                        <td className="text-right text-xs">{naira(candidate.survivor?.tco_ngn)}</td>
                                        <td className="text-right text-xs">{candidate.survivor?.debt_score}</td>
                                    </tr>
                                    {candidate.displaced.map((row) => (
                                        <tr key={row.id} className="border-b border-gray-50">
                                            <td className="py-1.5">{row.name}</td>
                                            <td className="text-xs text-[#B3261E]">displaced</td>
                                            <td className="text-xs">{row.business_fit} / {row.technical_fit}</td>
                                            <td className="text-xs">{row.time_score || '—'}</td>
                                            <td className="text-right text-xs">{naira(row.tco_ngn)}</td>
                                            <td className="text-right text-xs">{row.debt_score}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}

                    {candidates.length === 0 && (
                        <div className="rounded-xl border border-gray-100 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                            No capability is covered by more than one live application.
                        </div>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
