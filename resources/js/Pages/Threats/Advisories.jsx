import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, humanize, threatLabel } from '@/Utils/risk';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

function AffectedPanel({ advisory }) {
    const { findings, assets, open } = advisory.affected;

    if ((advisory.cves || []).length === 0) {
        return (
            <p className="mt-3 rounded-lg bg-[#F7FAFC] px-3 py-2 text-xs text-[#4A5568]">
                This advisory names no CVEs, so it cannot be matched to your findings automatically. Review the indicators below against your SIEM.
            </p>
        );
    }
    if (findings.length === 0) {
        return (
            <p className="mt-3 rounded-lg bg-[#2D7D46]/10 px-3 py-2 text-xs text-[#2D7D46]">
                Not affected on current evidence: none of {advisory.cves.join(', ')} appears in your vulnerability register. Confirm your scanners cover the products named.
            </p>
        );
    }

    return (
        <div className={`mt-3 rounded-lg border px-3 py-2 text-xs ${open > 0 ? 'border-[#B3261E]/30 bg-[#B3261E]/5' : 'border-[#2D7D46]/30 bg-[#2D7D46]/5'}`}>
            <p className={`font-semibold ${open > 0 ? 'text-[#B3261E]' : 'text-[#2D7D46]'}`}>
                {open > 0 ? `Affected: ${open} open finding${open > 1 ? 's' : ''}` : 'Previously affected: every matching finding is closed'}
                {assets.length > 0 && ` on ${assets.length} asset${assets.length > 1 ? 's' : ''}`}
            </p>
            <ul className="mt-2 space-y-1">
                {findings.map((f) => (
                    <li key={f.id} className="flex flex-wrap items-center gap-2">
                        <Link href={route('vulnerabilities.show', f.id)} className="font-mono text-[#0A1F44] underline">{f.code}</Link>
                        <span className="text-[#2D3748]">{f.cve_id} · {f.title}</span>
                        <StatusBadge status={f.status} label={humanize(f.status)} />
                    </li>
                ))}
            </ul>
            {assets.length > 0 && (
                <p className="mt-2 text-[#4A5568]">
                    Assets: {assets.map((a) => `${a.name} (${a.criticality})`).join(', ')}
                </p>
            )}
        </div>
    );
}

export default function ThreatAdvisories({ advisories = [], sources = [], filters = {} }) {
    const [open, setOpen] = useState({});
    const toggle = (id) => setOpen((o) => ({ ...o, [id]: !o[id] }));
    const affectedCount = advisories.filter((a) => a.affected.open > 0).length;

    return (
        <AuthenticatedLayout header="Threat Advisories">
            <Head title="Threat Advisories" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Threat Advisories' }]}
                title="Threat Advisories"
                subtitle="ngCERT, NITDA and CISA advisories, matched by CVE against your own vulnerability register and the assets it affects."
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <select value={filters.source || ''} onChange={(e) => router.get(route('threat-advisories.index'), { source: e.target.value || undefined }, { preserveState: true, replace: true })}
                    className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <option value="">All sources</option>
                    {sources.map((s) => <option key={s} value={s}>{threatLabel(s)}</option>)}
                </select>
                <span className="text-xs text-[#718096]">
                    {advisories.length} advisories · <span className={affectedCount ? 'font-semibold text-[#B3261E]' : ''}>{affectedCount} affecting open findings</span>
                </span>
            </div>

            <div className="space-y-3">
                {advisories.length === 0 && <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096]">No advisories.</p>}
                {advisories.map((a) => (
                    <div key={a.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="rounded bg-[#0A1F44] px-2 py-0.5 text-[10px] uppercase text-white">{threatLabel(a.source)}</span>
                                    <span className="text-xs text-[#718096]">{a.advisory_id}</span>
                                    <span className="text-xs text-[#718096]">{formatDate(a.published_at)}</span>
                                    {a.affected.open > 0 && <StatusBadge status="critical" label="You are affected" />}
                                </div>
                                <h3 className="mt-1 text-sm font-semibold text-[#2D3748]">{a.title}</h3>
                                {a.body && <p className="mt-1 text-xs text-[#718096]">{a.body}</p>}
                            </div>
                            <button type="button" onClick={() => toggle(a.id)}
                                className="whitespace-nowrap rounded-lg border border-[#0A1F44]/20 px-3 py-1.5 text-xs text-[#0A1F44] hover:bg-[#0A1F44]/5">
                                {open[a.id] ? 'Hide' : 'Am I affected?'}
                            </button>
                        </div>
                        {(a.cves || []).length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                                {a.cves.map((c) => (
                                    <span key={c} className="rounded bg-[#B3261E]/10 px-2 py-0.5 font-mono text-[10px] text-[#B3261E]">{c}</span>
                                ))}
                            </div>
                        )}
                        {open[a.id] && (
                            <>
                                <AffectedPanel advisory={a} />
                                {Object.keys(a.iocs || {}).length > 0 && (
                                    <div className="mt-2 text-xs text-[#4A5568]">
                                        <span className="font-semibold">Indicators:</span>{' '}
                                        {Object.entries(a.iocs).map(([type, values]) => `${type.toUpperCase()}: ${(values || []).join(', ')}`).join(' · ')}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
