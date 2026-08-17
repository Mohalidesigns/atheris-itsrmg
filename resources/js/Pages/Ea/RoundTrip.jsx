import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * RoundTrip — WS 4.4, "ArchiMate round-trip release gate against Archi and
 * Sparx EA".
 *
 * A gate rather than a feature because interchange is the credibility test an EA
 * tool fails in front of an architect: they will open the export in Archi within
 * the first ten minutes of an evaluation, and if it loads with warnings the demo
 * is over.
 */
export default function RoundTrip({ result, elementTypes = [], relationshipTypes = [], targets = [] }) {
    const { props } = usePage();
    const report = props.flash?.import_report;
    const [file, setFile] = useState(null);

    const submit = (e) => {
        e.preventDefault();
        if (!file) return;
        router.post(route('ea.round-trip.inspect'), { file }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="ArchiMate Round-Trip Gate">
            <Head title="ArchiMate Round-Trip Gate" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Diagrams' }, { label: 'Round-trip gate' }]}
                title="ArchiMate Open Exchange — round-trip release gate"
                subtitle="Export, re-parse, compare. Ten checks that the repository survives a trip through The Open Group's exchange format intact, and would open cleanly in the tools an architect already has."
                actions={
                    <Link href={route('ea.exchange')} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        Exchange jobs
                    </Link>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard
                    label="Gate"
                    value={result.pass ? 'PASS' : 'FAIL'}
                    tone={result.pass ? 'green' : 'red'}
                    sublabel={`${result.checks.filter((c) => c.pass).length}/${result.checks.length} checks`}
                />
                <KpiCard label="Elements exported" value={result.counts.exported_elements} tone="navy"
                    sublabel={`${result.counts.distinct_element_types} distinct types`} />
                <KpiCard label="Relationships" value={result.counts.exported_relationships} tone="white"
                    sublabel={`${result.counts.distinct_relationship_types} distinct types`} />
                <KpiCard label="Document size" value={`${Math.round(result.xml_bytes / 1024)} kB`} tone="white" />
                <KpiCard label="Digest" value={result.sha256.slice(0, 10)} tone="gold" sublabel="sha256, first 10" />
            </div>

            <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-[1.6fr,1fr]">
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="mb-3 text-sm font-bold text-[#0A1F44]">Checks</h3>
                    <ul className="space-y-2">
                        {result.checks.map((check, index) => (
                            <li key={index} className="flex items-start gap-3 border-b border-gray-50 pb-2">
                                <span className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ${
                                    check.pass ? 'bg-[#2D7D46]/10 text-[#2D7D46]' : 'bg-[#B3261E]/10 text-[#B3261E]'
                                }`}>
                                    {check.pass ? '✓' : '✕'}
                                </span>
                                <div>
                                    <div className="text-sm text-[#0A1F44]">{check.name}</div>
                                    <div className="text-[11px] text-gray-500">{check.detail}</div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Verified against</h3>
                        <ul className="space-y-1 text-xs text-gray-600">
                            {targets.map((target) => <li key={target}>• {target}</li>)}
                        </ul>
                        <p className="mt-2 text-[11px] text-gray-400">
                            Both dialects are accepted on import: Archi puts documentation in its own element and Sparx
                            often omits it, and the two shape identifiers differently. The export targets the strict
                            schema shape both read.
                        </p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Why each check exists</h3>
                        <dl className="space-y-2 text-[11px] text-gray-500">
                            <div>
                                <dt className="font-medium text-gray-700">Legal type names</dt>
                                <dd>The standard spells them <code>Realization</code> and <code>Specialization</code>; the
                                    module's own vocabulary is British. Emitting <code>Realisation</code> makes Archi drop
                                    the relationship silently.</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-gray-700">Endpoints resolve</dt>
                                <dd>A relationship pointing at a missing identifier makes Archi render an empty view
                                    rather than raise an error — the worst failure mode in an evaluation.</dd>
                            </div>
                            <div>
                                <dt className="font-medium text-gray-700">Deterministic export</dt>
                                <dd>Two exports of an unchanged repository must be byte-identical, otherwise every export
                                    looks like a change in version control and two points in time cannot be diffed.</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">Inspect an incoming document</h3>
                <p className="mb-3 text-[11px] text-gray-400">
                    Dry run: the file is parsed and reported on, and nothing is written. An import that silently merges
                    into a repository backing a signed regulatory return is not acceptable — the same stance the scoped
                    write API takes.
                </p>
                <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                    <input
                        type="file"
                        accept=".xml"
                        onChange={(e) => setFile(e.target.files?.[0] || null)}
                        className="text-xs"
                    />
                    <button type="submit" disabled={!file} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-40">
                        Inspect
                    </button>
                </form>

                {report && (
                    <div className="mt-4 rounded-lg border border-gray-100 bg-[#F7FAFC] p-3">
                        <div className="mb-2 text-xs font-medium text-[#0A1F44]">
                            Recognised dialect: {report.dialect}
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Would import</h4>
                                <ul className="space-y-0.5 text-xs">
                                    {Object.entries(report.importable || {}).map(([type, count]) => (
                                        <li key={type} className="flex justify-between">
                                            <span className="text-gray-600">{type}</span>
                                            <span className="font-medium">{count}</span>
                                        </li>
                                    ))}
                                    <li className="flex justify-between border-t border-gray-200 pt-1">
                                        <span className="text-gray-600">Relationships</span>
                                        <span className="font-medium">{report.relationships?.importable}</span>
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                    Would skip ({(report.skipped || []).length} element(s), {report.relationships?.skipped} relationship(s))
                                </h4>
                                <ul className="max-h-40 space-y-0.5 overflow-y-auto text-[11px] text-gray-500">
                                    {(report.skipped || []).slice(0, 40).map((row, index) => (
                                        <li key={index}>{row.type} <span className="text-gray-400">{row.reason}</span></li>
                                    ))}
                                    {!(report.skipped || []).length && <li className="text-[#2D7D46]">Nothing would be lost.</li>}
                                </ul>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Element types accepted ({elementTypes.length})</h3>
                    <div className="flex flex-wrap gap-1">
                        {elementTypes.map((type) => (
                            <span key={type} className="rounded bg-gray-50 px-1.5 py-0.5 text-[10px] text-gray-600">{type}</span>
                        ))}
                    </div>
                </div>
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Relationship types accepted ({relationshipTypes.length})</h3>
                    <div className="flex flex-wrap gap-1">
                        {relationshipTypes.map((type) => (
                            <span key={type} className="rounded bg-gray-50 px-1.5 py-0.5 text-[10px] text-gray-600">{type}</span>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
