import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';

const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const bar = (pct) => (pct == null ? '#CBD5E0' : pct >= 90 ? '#2D7D46' : pct >= 70 ? '#E5A100' : '#B3261E');

export default function PciDashboard({ requirements = [], overall = null, assessment = null, nextValidationDue = null, validationType, scoping = {}, controls = {}, relevantPolicies = 0 }) {
    const overdue = nextValidationDue && nextValidationDue < new Date().toISOString().slice(0, 10);
    return (
        <AuthenticatedLayout header="PCI DSS v4.0.1">
            <Head title="PCI DSS" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management' }, { label: 'Dashboard' }]}
                title="PCI DSS v4.0.1 compliance"
                subtitle={`${validationType}. Requirement status comes from the latest PCI assessment; scope from the CDE register.`}
                actions={<Link href={route('pci.saq')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Assessment</Link>}
            />
            <div className="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard label="Sub-requirements in place" value={overall == null ? '—' : `${overall}%`} sublabel={assessment ? `${assessment.status === 'completed' ? 'assessed' : 'in progress'} · ${assessment.title}` : 'no PCI assessment yet'} tone="navy" />
                <KpiCard label="Next validation due" value={fmt(nextValidationDue)} sublabel={assessment?.end_date ? `last completed ${fmt(assessment.end_date)}` : 'no completed assessment'} tone={overdue || !nextValidationDue ? 'red' : 'white'} />
                <KpiCard label="CDE assets" value={scoping.cde ?? 0} sublabel={`${scoping.connected ?? 0} connected-to`} tone="white" />
                <KpiCard label="Assets not scoped" value={scoping.unscoped ?? 0} tone={scoping.unscoped ? 'amber' : 'white'} />
                <KpiCard label="Mapped controls" value={controls.mapped ?? 0} sublabel={`${controls.ineffective ?? 0} ineffective`} tone={controls.ineffective ? 'amber' : 'white'} />
                <KpiCard label="Sub-reqs with no control" value={controls.unmapped_subs ?? 0} sublabel={`${relevantPolicies} PCI policies in force`} tone={controls.unmapped_subs ? 'amber' : 'white'} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">The 12 requirements: sub-requirements in place (of those tested)</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {requirements.map((r) => (
                        <Link key={r.code} href={route('pci.matrix')} className="block rounded-lg border border-gray-100 p-3 hover:border-[#0A1F44]/30">
                            <div className="flex items-start justify-between gap-2">
                                <p className="text-sm text-[#2D3748]"><span className="font-mono text-xs text-[#0A1F44]">Req {r.code}</span> {r.title}</p>
                                <span className="text-xs font-semibold text-[#0A1F44]">{r.pct == null ? 'not tested' : `${r.pct}%`}</span>
                            </div>
                            <div className="mt-2 h-2 overflow-hidden rounded bg-gray-100"><div className="h-2 rounded" style={{ width: `${r.pct ?? 0}%`, background: bar(r.pct) }} /></div>
                            <p className="mt-1 text-[11px] text-[#718096]">{r.in_place} of {r.tested} tested in place{r.not_in_place ? ` · ${r.not_in_place} not in place` : ''}{r.unmapped ? ` · ${r.unmapped} with no control` : ''}</p>
                        </Link>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
