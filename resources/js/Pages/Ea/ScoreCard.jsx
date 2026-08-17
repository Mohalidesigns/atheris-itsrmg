import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link } from '@inertiajs/react';

/**
 * Architecture Completeness Score Card — B5.
 *
 * ATH-EAR-002 §3.6: "**none of the six products ships an
 * assessment-and-completeness layer.** Avolution ships 40 frameworks but no
 * instrument that tells you how complete your architecture *documentation* is.
 * That is an open white space, and it maps almost perfectly onto CBN's
 * maturity-level requirement."
 *
 * The instrument is IFEAD's 4×6 traffic-light grid: four aspect areas × six
 * abstraction levels, each cell red (unknown) / amber (partially documented) /
 * green (fully documented). It reads knowledge completeness and cross-domain
 * alignment at the same time — which is why the alignment gap is called out
 * separately below rather than being buried in the average.
 */

const STATE_STYLE = {
    documented: { bg: 'bg-[#2D7D46]', text: 'text-white', label: 'Documented' },
    partial: { bg: 'bg-[#E5A100]', text: 'text-white', label: 'Partial' },
    unknown: { bg: 'bg-[#B3261E]', text: 'text-white', label: 'Unknown' },
};

function Cell({ cell }) {
    const style = STATE_STYLE[cell.state] || STATE_STYLE.unknown;

    const title = [
        cell.hint,
        `${cell.count} record(s)${cell.expected ? ` · ${cell.expected} expected for full marks` : ''}`,
        cell.tracks_seal ? `${cell.sealed} approved by an owner` : 'Not tracked by the quality seal',
        `Score ${cell.score}/100`,
    ].join('\n');

    const inner = (
        <div
            className={`flex h-full min-h-[72px] flex-col justify-between rounded-lg p-2 ${style.bg} ${style.text} transition hover:opacity-90`}
            title={title}
        >
            <span className="text-[10px] font-medium leading-tight opacity-90">{cell.hint}</span>
            <span className="flex items-baseline justify-between">
                <span className="text-lg font-bold">{cell.score}</span>
                <span className="text-[10px] opacity-90">
                    {cell.count}
                    {cell.tracks_seal ? ` · ${cell.sealed}✓` : ''}
                </span>
            </span>
        </div>
    );

    return cell.route ? (
        <Link href={route(cell.route)} className="block h-full">
            {inner}
        </Link>
    ) : (
        inner
    );
}

export default function ScoreCard({
    aspects = {},
    levels = {},
    cells = {},
    overall = {},
    maturity = {},
    graphHealth = [],
}) {
    const levelKeys = Object.keys(levels);
    const aspectKeys = Object.keys(aspects);

    const danglingTotal = graphHealth.reduce((a, r) => a + r.dangling, 0);

    return (
        <AuthenticatedLayout header="Architecture Score Card">
            <Head title="Architecture Score Card" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Command Centre' },
                    { label: 'Score Card' },
                ]}
                title="Architecture Completeness Score Card"
                subtitle="How much of the architecture is actually documented and confirmed — computed from the repository, not assessed by opinion."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.score-card')} current="ea.score-card" />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard
                    label="Overall completeness"
                    value={`${overall.score || 0}%`}
                    tone={overall.score >= 70 ? 'green' : overall.score >= 40 ? 'amber' : 'red'}
                    sublabel={`${overall.documented || 0} of ${overall.total_cells || 24} cells documented`}
                />
                <KpiCard
                    label="Indicative maturity"
                    value={`L${maturity.level ?? 0}`}
                    tone={maturity.meets_category_one ? 'green' : maturity.meets_category_two ? 'amber' : 'red'}
                    sublabel={maturity.label}
                />
                <KpiCard label="Partially documented" value={overall.partial || 0} tone="white" />
                <KpiCard label="Unknown" value={overall.unknown || 0} tone={overall.unknown ? 'red' : 'white'} />
                <KpiCard
                    label="Alignment gap"
                    value={`${overall.alignment_gap || 0} pts`}
                    tone={overall.alignment_gap > 30 ? 'amber' : 'white'}
                    sublabel="Spread between strongest and weakest aspect"
                />
            </div>

            <div className="mb-5 rounded-xl border border-gray-200 bg-white p-4">
                <p className="text-sm text-[#2D3748]">
                    Indicative CBN ITSB maturity:{' '}
                    <span className="font-semibold">
                        Level {maturity.level} — {maturity.label}
                    </span>
                    .{' '}
                    {maturity.meets_category_one
                        ? 'This meets the Level 3 target the ITSB sets for Category One institutions.'
                        : maturity.meets_category_two
                          ? 'This meets the Level 2 target for Category Two institutions, but falls short of the Level 3 required of Category One.'
                          : 'This falls short of both the Level 3 target for Category One institutions and the Level 2 target for Category Two.'}
                </p>
                <p className="mt-2 text-xs text-[#718096]">
                    Indicative only. The ITSB assessment covers process and governance maturity as well as
                    documentation completeness, and is scored by the IT Standards Governance Council — this figure
                    reads the repository, which is one input to it.
                </p>
            </div>

            <div className="mb-5 overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 className="text-sm font-semibold text-[#0A1F44]">Completeness by aspect area × abstraction level</h3>
                <p className="mb-4 mt-1 text-xs text-[#718096]">
                    Each cell scores population against an expected minimum, weighted toward records an owner has
                    actually confirmed. A table of unconfirmed rows is not documented architecture.
                </p>

                <div className="overflow-x-auto">
                    <div
                        className="grid min-w-[860px] gap-2"
                        style={{ gridTemplateColumns: `160px repeat(${levelKeys.length}, minmax(0, 1fr))` }}
                    >
                        <div />
                        {levelKeys.map((level) => (
                            <div key={level} className="pb-1 text-center text-[10px] font-semibold uppercase leading-tight text-[#718096]">
                                {levels[level]}
                            </div>
                        ))}

                        {aspectKeys.map((aspect) => (
                            <>
                                <div
                                    key={`${aspect}-label`}
                                    className="flex items-center pr-2 text-xs font-semibold text-[#0A1F44]"
                                >
                                    <span>
                                        {aspects[aspect]}
                                        <span className="ml-1 font-normal text-[#718096]">
                                            {overall.by_aspect?.[aspect] ?? 0}%
                                        </span>
                                    </span>
                                </div>
                                {levelKeys.map((level) => (
                                    <Cell key={`${aspect}.${level}`} cell={cells[`${aspect}.${level}`] || {}} />
                                ))}
                            </>
                        ))}
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-4 text-[11px] text-[#718096]">
                    {Object.entries(STATE_STYLE).map(([key, s]) => (
                        <span key={key} className="flex items-center gap-1.5">
                            <span className={`h-3 w-3 rounded ${s.bg}`} />
                            {s.label}
                        </span>
                    ))}
                    <span className="ml-auto">Each cell shows score · record count · ✓ approved</span>
                </div>
            </div>

            {overall.alignment_gap > 20 && (
                <div className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <p className="text-sm font-semibold text-[#8A6400]">
                        Cross-domain alignment gap: {overall.alignment_gap} points
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        <span className="font-medium">{aspects[overall.weakest_aspect]}</span> is documented
                        substantially less well than the strongest aspect, and the{' '}
                        <span className="font-medium">{levels[overall.weakest_level]}</span> level is the weakest
                        abstraction across the board. An uneven architecture is harder to defend than an evenly
                        middling one, because the gaps hide inside the average.
                    </p>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Cross-module reference health</h3>
                    <p className="mt-0.5 text-xs text-[#718096]">
                        A return that cites an architecture entity has to be able to resolve it. Any dangling
                        reference here silently inflates the coverage figures computed over it.
                    </p>
                </div>

                {danglingTotal === 0 ? (
                    <p className="px-4 py-4 text-xs text-[#2D7D46]">
                        Every cross-module reference resolves. Coverage figures computed over them are citable.
                    </p>
                ) : (
                    <p className="border-b border-[#B3261E]/20 bg-[#B3261E]/5 px-4 py-3 text-xs text-[#B3261E]">
                        {danglingTotal} reference(s) do not resolve. Figures computed over them are arithmetic over
                        noise and must not be shown to a regulator until repaired.
                    </p>
                )}

                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Reference</th>
                            <th className="px-3 py-2">Target module</th>
                            <th className="px-3 py-2">Populated</th>
                            <th className="px-3 py-2">Resolves</th>
                            <th className="px-3 py-2">Dangling</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {graphHealth.map((r) => (
                            <tr key={`${r.table}.${r.column}`}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">
                                    {r.table}.{r.column}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.module}</td>
                                <td className="px-3 py-2 text-xs">{r.total}</td>
                                <td className="px-3 py-2 text-xs">
                                    <span className={r.percent === 100 ? 'text-[#2D7D46]' : 'text-[#8A6400]'}>
                                        {r.percent}%
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {r.dangling > 0 ? (
                                        <span className="font-medium text-[#B3261E]">{r.dangling}</span>
                                    ) : (
                                        <span className="text-[#718096]">—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
