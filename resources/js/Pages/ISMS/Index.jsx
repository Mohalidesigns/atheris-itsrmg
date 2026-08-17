import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';
import { ShieldCheckIcon, CheckBadgeIcon, DocumentMagnifyingGlassIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

export default function IsmsIndex({ kpis = {}, themes = {}, maturity = {}, latestAudit = null }) {
    const implPercent = kpis.soa_applicable ? Math.round((kpis.soa_implemented / kpis.soa_applicable) * 100) : 0;
    return (
        <AuthenticatedLayout header="ISMS — ISO/IEC 27001:2022">
            <Head title="ISMS Overview" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS' }, { label: 'Overview' }]}
                title="Information Security Management System"
                subtitle="ISO/IEC 27001:2022 — Kano Heritage Bank Plc · scope covers all information assets supporting core banking, payments, channels, treasury and customer lifecycle."
                actions={<Link href={route('isms.soa')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open SoA</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard label="Annex A Controls" value={kpis.annex_a_total || 0} tone="navy" icon={ShieldCheckIcon} />
                <KpiCard label="SoA Applicable" value={kpis.soa_applicable || 0} sublabel={`of ${kpis.soa_total || 0}`} tone="gold" />
                <KpiCard label="Implemented" value={kpis.soa_implemented || 0} sublabel={`${implPercent}% of applicable`} tone="green" icon={CheckBadgeIcon} />
                <KpiCard label="ISMS Risks" value={kpis.isms_risks || 0} tone="white" />
                <KpiCard label="Open Gaps" value={kpis.open_gaps || 0} tone="red" icon={ExclamationTriangleIcon} />
                <KpiCard label="Maturity" value="3.3 / 4.0" sublabel="Clause 4-10" tone="white" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Annex A Themes — implementation coverage</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {Object.entries(themes).map(([code, t]) => {
                            const pct = t.count ? Math.round((t.implemented / t.count) * 100) : 0;
                            return (
                                <Link key={code} href={route('isms.controls')}
                                    className="border border-gray-100 rounded-lg p-3 hover:border-[#0A1F44]/30 hover:shadow-sm transition block">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-xs font-mono text-[#0A1F44]">{code}</p>
                                            <p className="text-sm font-semibold text-[#2D3748]">{t.name}</p>
                                        </div>
                                        <span className="text-xs font-semibold text-[#0A1F44]">{pct}%</span>
                                    </div>
                                    <p className="text-[11px] text-[#718096] mt-1">{t.implemented} of {t.count} implemented (target {t.target})</p>
                                    <div className="h-2 bg-gray-100 rounded overflow-hidden mt-2">
                                        <div className="h-2 rounded" style={{
                                            width: `${pct}%`,
                                            background: pct >= 90 ? '#2D7D46' : pct >= 70 ? '#E5A100' : '#B3261E',
                                        }} />
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">ISO 27001 Clause 4–10 Maturity</h3>
                    <ul className="space-y-2 text-xs">
                        {Object.entries(maturity).map(([k, v]) => (
                            <li key={k}>
                                <div className="flex items-center justify-between">
                                    <span className="capitalize text-[#2D3748]">{k.replace('_', ' ')}</span>
                                    <span className="font-semibold text-[#0A1F44]">{Number(v).toFixed(1)} / 4.0</span>
                                </div>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-1">
                                    <div className="h-2 rounded bg-[#0A1F44]" style={{ width: `${(v / 4) * 100}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
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
                    <p className="text-xs text-[#718096] mt-1">All 93 Annex A controls with implementation status.</p>
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
                    <h3 className="text-sm font-semibold text-[#2D3748]">Latest ISO 27001 Assessment</h3>
                    <div className="mt-2 grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                        <div><p className="text-[#718096]">Title</p><p className="text-[#2D3748] font-semibold">{latestAudit.title}</p></div>
                        <div><p className="text-[#718096]">Status</p><p className="text-[#2D3748]">{latestAudit.status}</p></div>
                        <div><p className="text-[#718096]">Overall score</p><p className="text-[#0A1F44] font-bold">{latestAudit.overall_score}%</p></div>
                        <div><p className="text-[#718096]">Compliant</p><p className="text-[#2D7D46] font-semibold">{latestAudit.compliant_count}</p></div>
                        <div><p className="text-[#718096]">Non-compliant</p><p className="text-[#B3261E] font-semibold">{latestAudit.non_compliant_count}</p></div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
