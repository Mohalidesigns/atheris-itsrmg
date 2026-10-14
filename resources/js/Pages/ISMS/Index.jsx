import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldCheckIcon, CheckBadgeIcon, DocumentMagnifyingGlassIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function IsmsIndex({ kpis = {}, themes = {}, soaVersion = null, pendingVersion = false, latestAudit = null }) {
    const { auth } = usePage().props;
    const implPercent = kpis.soa_applicable ? Math.round((kpis.soa_implemented / kpis.soa_applicable) * 100) : 0;
    return (
        <AuthenticatedLayout header="ISMS — ISO/IEC 27001:2022">
            <Head title="ISMS Overview" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS' }, { label: 'Overview' }]}
                title="Information Security Management System"
                subtitle={`ISO/IEC 27001:2022 — ${auth?.organization?.name || 'the bank'} · what the Statement of Applicability claims, set against what the controls and the latest assessment show.`}
                actions={<Link href={route('isms.soa')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open SoA</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                <KpiCard label="Annex A controls" value={kpis.annex_a_total || 0} sublabel={`${kpis.soa_applicable || 0} applicable`} tone="navy" icon={ShieldCheckIcon} />
                <KpiCard label="Implemented" value={kpis.soa_implemented || 0} sublabel={`${implPercent}% of applicable`} tone="green" icon={CheckBadgeIcon} />
                <KpiCard label="SoA claim ≠ evidence" value={kpis.soa_flagged || 0} tone={kpis.soa_flagged ? 'red' : 'white'} />
                <KpiCard label="ISMS risks" value={kpis.isms_risks || 0} sublabel={`${kpis.isms_risks_above_appetite || 0} above appetite`} tone="white" />
                <KpiCard label="Open non-conformities" value={kpis.open_gaps || 0} sublabel={`${kpis.overdue_gaps || 0} overdue`} tone={kpis.overdue_gaps ? 'red' : 'white'} icon={ExclamationTriangleIcon} />
                <KpiCard label="SoA version" value={soaVersion ? `v${soaVersion.version_no}` : 'None'} sublabel={soaVersion ? `approved ${fmt(soaVersion.approved_at)}` : 'never approved'} tone={soaVersion ? 'white' : 'amber'} />
            </div>
            {(pendingVersion || soaVersion?.changes > 0) && (
                <p className="mb-6 rounded-lg border border-[#E5A100]/40 bg-[#FFF8E6] px-3 py-2 text-xs text-[#8A6400]">
                    {pendingVersion ? 'A new SoA version is awaiting approval. ' : ''}
                    {soaVersion?.changes > 0 ? `${soaVersion.changes} Annex A entries have changed since v${soaVersion.version_no} was approved.` : ''}
                    {' '}<Link href={route('isms.soa')} className="underline">Review in the SoA</Link>
                </p>
            )}

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-6">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Annex A themes: claimed implementation against assessed compliance</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {Object.entries(themes).map(([code, t]) => {
                        const impl = t.applicable ? Math.round((t.implemented / t.applicable) * 100) : 0;
                        const comp = t.assessed ? Math.round((t.compliant / t.assessed) * 100) : null;
                        return (
                            <Link key={code} href={route('isms.soa')} className="block rounded-lg border border-gray-100 p-3 transition hover:border-[#0A1F44]/30 hover:shadow-sm">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <p className="text-xs font-mono text-[#0A1F44]">{code}</p>
                                        <p className="text-sm font-semibold text-[#2D3748]">{t.name}</p>
                                    </div>
                                    {t.flagged > 0 && <span className="text-[11px] font-semibold text-[#B3261E]">{t.flagged} flagged</span>}
                                </div>
                                <p className="mt-1 text-[11px] text-[#718096]">{t.applicable} of {t.count} applicable</p>
                                {[['SoA says implemented', impl, `${t.implemented}/${t.applicable}`], ['Assessed compliant', comp, t.assessed ? `${t.compliant}/${t.assessed}` : 'not assessed']].map(([l, pct, n]) => (
                                    <div key={l} className="mt-2">
                                        <div className="flex justify-between text-[11px] text-[#4A5568]"><span>{l}</span><span>{pct == null ? n : `${pct}% · ${n}`}</span></div>
                                        <div className="mt-0.5 h-2 overflow-hidden rounded bg-gray-100">
                                            <div className="h-2 rounded" style={{ width: `${pct ?? 0}%`, background: (pct ?? 0) >= 90 ? '#2D7D46' : (pct ?? 0) >= 70 ? '#E5A100' : '#B3261E' }} />
                                        </div>
                                    </div>
                                ))}
                            </Link>
                        );
                    })}
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <Link href={route('isms.risks')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition">
                    <ExclamationTriangleIcon className="w-6 h-6 text-[#C9A86A]" />
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-2">ISMS Risks</h3>
                    <p className="text-xs text-[#718096] mt-1">Risks scoped into the ISMS, with residual score + treatment status.</p>
                </Link>
                <Link href={route('isms.controls')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition">
                    <CheckBadgeIcon className="w-6 h-6 text-[#2D7D46]" />
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-2">Annex A Controls</h3>
                    <p className="text-xs text-[#718096] mt-1">Every Annex A control with its implementation status.</p>
                </Link>
                <Link href={route('isms.soa')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition">
                    <ShieldCheckIcon className="w-6 h-6 text-[#0A1F44]" />
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-2">Statement of Applicability</h3>
                    <p className="text-xs text-[#718096] mt-1">Applicability + justifications per Annex A control.</p>
                </Link>
                <Link href={route('isms.gap-analysis')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition">
                    <DocumentMagnifyingGlassIcon className="w-6 h-6 text-[#B3261E]" />
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-2">Gap Analysis</h3>
                    <p className="text-xs text-[#718096] mt-1">Non-conformities, severity, owners, due dates.</p>
                </Link>
            </div>

            {latestAudit && (
                <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Latest completed ISO 27001 assessment <span className="font-normal text-[#718096]">(the evidence the SoA is checked against)</span></h3>
                    <div className="mt-2 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                        <div><p className="text-[#718096]">Title</p><p className="text-[#2D3748] font-semibold">{latestAudit.title}</p></div>
                        <div><p className="text-[#718096]">Completed</p><p className="text-[#2D3748]">{fmt(latestAudit.end_date)}</p></div>
                        <div><p className="text-[#718096]">Overall score</p><p className="text-[#0A1F44] font-bold">{latestAudit.overall_score}%</p></div>
                        <div><p className="text-[#718096]">Compliant</p><p className="text-[#2D7D46] font-semibold">{latestAudit.compliant_count}</p></div>
                        <div><p className="text-[#718096]">Non-compliant</p><p className="text-[#B3261E] font-semibold">{latestAudit.non_compliant_count}</p></div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
