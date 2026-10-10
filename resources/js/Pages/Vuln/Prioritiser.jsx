import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link, router } from '@inertiajs/react';

const BAND_STYLE = {
    P1: 'bg-[#B3261E] text-white',
    P2: 'bg-[#E5A100] text-white',
    P3: 'bg-[#1D4ED8] text-white',
    P4: 'bg-gray-200 text-gray-700',
};

function slaLabel(h) {
    return h >= 48 ? `${Math.round(h / 24)}d` : `${h}h`;
}

export default function VulnPrioritiser({ rows = [], stats = {}, slaHours = {}, filters = {} }) {
    const apply = (next) => router.get(route('vuln-prioritiser.index'), { ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header="Vulnerability Prioritiser">
            <Head title="Vulnerability Prioritiser" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Vulnerability Prioritiser' }]}
                title="Vulnerability Prioritiser"
                subtitle="Outstanding findings ranked by CVSS, EPSS exploit probability, CISA KEV and the criticality of the affected asset."
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Outstanding" value={stats.outstanding ?? 0} tone="white" />
                <KpiCard label="P1" value={stats.p1 ?? 0} tone={stats.p1 ? 'red' : 'white'} />
                <KpiCard label="On CISA KEV" value={stats.kev ?? 0} tone={stats.kev ? 'amber' : 'white'} />
                <KpiCard label="Past SLA" value={stats.sla_breached ?? 0} tone="white" />
                <KpiCard label="P1/P2 without a ticket" value={stats.no_ticket ?? 0} tone="white" />
            </div>

            <div className="mb-4 rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#4A5568] shadow-sm">
                <p>
                    <span className="font-semibold text-[#2D3748]">Score (0-100)</span> = CVSS/10 × 40 + EPSS × 25 + 20 if on CISA KEV (+5 if used in ransomware) + asset weight (critical 10, high 7, medium 4, low 2).
                    Bands: P1 ≥ 80, P2 ≥ 60, P3 ≥ 40, P4 below. KEV findings take the critical SLA.
                </p>
                <p className="mt-1">
                    <span className="font-semibold text-[#2D3748]">Your remediation SLA:</span>{' '}
                    {['critical', 'high', 'medium', 'low'].map((s) => `${humanize(s)} ${slaLabel(slaHours[s] ?? 0)}`).join(' · ')}
                </p>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                {['', 'P1', 'P2', 'P3', 'P4'].map((b) => (
                    <button key={b || 'all'} type="button" onClick={() => apply({ band: b || undefined })}
                        className={`rounded-lg border px-3 py-1.5 text-xs ${((filters.band || '') === b) ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>
                        {b || 'All bands'}
                    </button>
                ))}
                <label className="ml-2 flex items-center gap-2 text-xs text-[#2D3748]">
                    <input type="checkbox" checked={!!filters.kev} onChange={(e) => apply({ kev: e.target.checked ? 1 : undefined })} className="rounded border-gray-300" />
                    KEV only
                </label>
                <span className="ml-auto text-xs text-[#718096]">{rows.length} shown</span>
            </div>

            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Priority</th>
                            <th className="px-3 py-2">Finding</th>
                            <th className="px-3 py-2">CVSS</th>
                            <th className="px-3 py-2">EPSS</th>
                            <th className="px-3 py-2">KEV</th>
                            <th className="px-3 py-2">Asset</th>
                            <th className="px-3 py-2">Due</th>
                            <th className="px-3 py-2">Tickets</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-3 py-8 text-center text-[#718096]">No outstanding findings match.</td></tr>
                        )}
                        {rows.map((v) => (
                            <tr key={v.id} className="hover:bg-gray-50">
                                <td className="px-3 py-2 whitespace-nowrap">
                                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${BAND_STYLE[v.band]}`}>{v.band}</span>
                                    <span className="ml-2 font-mono font-semibold text-[#0A1F44]">{v.score}</span>
                                </td>
                                <td className="px-3 py-2">
                                    <Link href={route('vulnerabilities.show', v.id)} className="text-[#0A1F44] hover:underline">{v.title}</Link>
                                    <p className="text-xs text-[#718096]">
                                        <span className="font-mono">{v.vuln_id_code}</span> · {v.cve_id || 'No CVE'} · <span className="capitalize">{v.severity}</span> · {humanize(v.status)}
                                    </p>
                                </td>
                                <td className="px-3 py-2">{v.cvss_score ?? '—'}</td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {v.epss !== null ? (
                                        <>
                                            {(v.epss * 100).toFixed(1)}%
                                            {v.epss_percentile !== null && <span className="block text-[10px] text-[#718096]">p{Math.round(v.epss_percentile * 100)}</span>}
                                        </>
                                    ) : <span className="text-xs text-[#718096]">not scored</span>}
                                </td>
                                <td className="px-3 py-2">
                                    {v.kev ? <StatusBadge status="critical" label={v.ransomware ? 'KEV · ransomware' : 'KEV'} /> : <span className="text-xs text-[#718096]">—</span>}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {v.asset ? (
                                        <>
                                            <span className="text-[#2D3748]">{v.asset.name}</span>
                                            <span className="block capitalize text-[#718096]">{v.asset.criticality}{v.asset_count > 1 ? ` · +${v.asset_count - 1} more` : ''}</span>
                                        </>
                                    ) : <span className="text-[#718096]">Not linked</span>}
                                </td>
                                <td className={`px-3 py-2 text-xs whitespace-nowrap ${v.sla_breached ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                    {formatDate(v.due_date)}{v.sla_breached && <span className="block">overdue</span>}
                                </td>
                                <td className="px-3 py-2 text-xs whitespace-nowrap">
                                    {v.open_tickets > 0 ? (
                                        <Link href={route('vulnerability-tickets.index', { vulnerability_id: v.id })} className="text-[#0A1F44] underline">{v.open_tickets} open</Link>
                                    ) : (
                                        <Link href={route('vulnerability-tickets.create', { vulnerability_id: v.id })} className="text-[#B3261E] underline">Raise ticket</Link>
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
