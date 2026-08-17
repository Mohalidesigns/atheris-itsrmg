import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';
import { CreditCardIcon, ShieldCheckIcon, DocumentTextIcon, ServerStackIcon } from '@heroicons/react/24/outline';

export default function PciDashboard({ requirements = [], overall = 0, lastAssessedAt, nextAssessmentAt, saq, assetsInCde = 0, controlsMappedPci = 0, relevantPolicies = 0, evidenceArtefacts = 0 }) {
    return (
        <AuthenticatedLayout header="PCI-DSS v4.0.1">
            <Head title="PCI Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management' }, { label: 'Dashboard' }]}
                title="PCI-DSS v4.0.1 Compliance"
                subtitle={`SAQ type: ${saq} · Last assessed ${lastAssessedAt} · Next: ${nextAssessmentAt} · Overall maturity: ${overall}%`}
                actions={<Link href={route('pci.saq')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open SAQ</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Overall maturity" value={`${overall}%`} tone="navy" icon={ShieldCheckIcon} />
                <KpiCard label="Assets in CDE" value={assetsInCde} tone="gold" icon={ServerStackIcon} />
                <KpiCard label="Controls mapped" value={controlsMappedPci} tone="green" />
                <KpiCard label="Relevant policies" value={relevantPolicies} tone="white" icon={DocumentTextIcon} />
                <KpiCard label="Evidence artefacts" value={evidenceArtefacts} tone="white" icon={CreditCardIcon} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-4">12 PCI-DSS Requirements — Kano Heritage maturity</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    {requirements.map((r) => {
                        const tone = r.maturity >= 90 ? 'bg-[#2D7D46]' : r.maturity >= 75 ? 'bg-[#E5A100]' : r.maturity >= 50 ? 'bg-[#1D4ED8]' : 'bg-[#B3261E]';
                        return (
                            <Link key={r.code} href={route('pci.controls')}
                                className="border border-gray-100 rounded-lg p-3 hover:border-[#0A1F44]/30 hover:shadow-sm transition block">
                                <div className="flex items-start justify-between">
                                    <p className="text-xs font-mono text-[#0A1F44]">{r.code}</p>
                                    <span className="text-xs font-bold text-[#0A1F44]">{r.maturity}%</span>
                                </div>
                                <p className="text-sm font-semibold text-[#2D3748] mt-1">{r.title}</p>
                                <p className="text-[11px] text-[#718096] mt-1">{r.description}</p>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-2">
                                    <div className={`h-2 rounded ${tone}`} style={{ width: `${r.maturity}%` }} />
                                </div>
                            </Link>
                        );
                    })}
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                <Link href={route('pci.cde')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition">
                    <ServerStackIcon className="w-5 h-5 text-[#C9A86A]" />
                    <h3 className="text-sm font-semibold mt-2">Cardholder Data Environment</h3>
                    <p className="text-xs text-[#718096] mt-1">Scoped assets, zones, flows.</p>
                </Link>
                <Link href={route('pci.controls')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition">
                    <ShieldCheckIcon className="w-5 h-5 text-[#2D7D46]" />
                    <h3 className="text-sm font-semibold mt-2">Controls mapping</h3>
                    <p className="text-xs text-[#718096] mt-1">Tenant controls per PCI requirement.</p>
                </Link>
                <Link href={route('pci.saq')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition">
                    <DocumentTextIcon className="w-5 h-5 text-[#0A1F44]" />
                    <h3 className="text-sm font-semibold mt-2">SAQ-D responses</h3>
                    <p className="text-xs text-[#718096] mt-1">72 scoped questions with responses.</p>
                </Link>
                <Link href={route('pci.matrix')} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition">
                    <CreditCardIcon className="w-5 h-5 text-[#B3261E]" />
                    <h3 className="text-sm font-semibold mt-2">Control matrix</h3>
                    <p className="text-xs text-[#718096] mt-1">Heat grid across all sub-requirements.</p>
                </Link>
            </div>
        </AuthenticatedLayout>
    );
}
