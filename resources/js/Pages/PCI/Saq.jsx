import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';

export const statusTone = { compliant: 'bg-[#D1FAE5] text-[#2D7D46]', partially_compliant: 'bg-[#FEF3C7] text-[#8A6400]', non_compliant: 'bg-[#FEE2E2] text-[#C53030]', not_applicable: 'bg-gray-100 text-[#718096]', not_assessed: 'bg-white border border-gray-200 text-[#718096]' };
const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function PciAssessment({ requirements = [], assessment = null, assessments = [], summary = {}, labels = {}, validationType, frameworkId, canAssess = false }) {
    return (
        <AuthenticatedLayout header="PCI Assessment">
            <Head title="PCI Assessment" />
            <PageHeader breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Assessment' }]}
                title="PCI DSS assessment"
                subtitle={`${validationType}. Assessments run on the compliance engine: open one to record each sub-requirement's result, findings and evidence.`}
                actions={canAssess && <Link href={route('compliance-assessments.create', { framework: frameworkId })} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Start a PCI assessment</Link>} />

            {!assessment ? (
                <div className="rounded-xl border border-gray-100 bg-white p-10 text-center text-sm text-[#718096]">No PCI DSS assessment has been started for this bank.</div>
            ) : (
                <>
                    <div className="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-4">
                        {Object.entries(labels).map(([k, l]) => <KpiCard key={k} label={l} value={summary[k] ?? 0} tone={k === 'non_compliant' && summary[k] ? 'red' : k === 'compliant' ? 'green' : 'white'} />)}
                        <KpiCard label="Sub-requirements" value={summary.total ?? 0} tone="navy" />
                    </div>
                    <p className="mb-3 text-sm text-[#4A5568]">Showing <Link href={route('compliance-assessments.show', assessment.id)} className="font-semibold text-[#0A1F44] underline">{assessment.title}</Link> ({assessment.status.replace('_', ' ')}, {fmt(assessment.start_date)} to {fmt(assessment.end_date)}).</p>
                    <div className="space-y-3">
                        {requirements.map((r) => (
                            <div key={r.code} className="bg-white rounded-xl border border-gray-100 shadow-sm">
                                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2">
                                    <p className="text-sm font-semibold text-[#2D3748]"><span className="font-mono text-xs text-[#0A1F44]">Req {r.code}</span> {r.title}</p>
                                    <span className="text-xs text-[#718096]">{r.in_place}/{r.subs.length} in place</span>
                                </div>
                                <ul className="divide-y divide-gray-50">
                                    {r.subs.map((s) => (
                                        <li key={s.id} className="flex flex-col gap-1 px-4 py-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="text-sm text-[#2D3748]"><span className="font-mono text-xs">{s.code}</span> {s.title}
                                                {s.findings && <p className="text-[11px] text-[#B3261E]">{s.findings}</p>}
                                            </div>
                                            <span className="flex flex-shrink-0 items-center gap-2 text-xs">
                                                {s.evidence > 0 && <span className="text-[#718096]">{s.evidence} evidence</span>}
                                                <span className={`rounded-lg px-2 py-0.5 ${statusTone[s.status]}`}>{labels[s.status]}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {assessments.length > 0 && (
                <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-2">All PCI assessments</h3>
                    <ul className="divide-y divide-gray-50 text-sm">
                        {assessments.map((a) => (
                            <li key={a.id} className="flex justify-between py-2"><Link href={route('compliance-assessments.show', a.id)} className="text-[#0A1F44] underline">{a.title}</Link><span className="text-xs text-[#718096]">{a.status.replace('_', ' ')} · {fmt(a.end_date || a.start_date)}{a.overall_score != null ? ` · ${Number(a.overall_score).toFixed(0)}%` : ''}</span></li>
                        ))}
                    </ul>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
