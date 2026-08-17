import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { DocumentArrowDownIcon } from '@heroicons/react/24/outline';

export default function EvidencePacks({ packs = [] }) {
    const generate = () => router.post(route('ea.evidence-packs.generate'));
    return (
        <AuthenticatedLayout header="CBN Evidence Packs">
            <Head title="CBN EA Evidence Packs" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'CBN Evidence Packs' }]}
                title="CBN EA Evidence Pack Generator"
                subtitle="PDF + ZIP bundle containing maturity answers, evidence links and attestation signatures for CBN examiners."
                actions={<button onClick={generate} className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                    <DocumentArrowDownIcon className="w-4 h-4" /> Generate new pack
                </button>}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Packs" value={packs.length} tone="navy" />
                <KpiCard label="Latest score" value={packs[0]?.overall_score ? Number(packs[0].overall_score).toFixed(2) : '—'} tone="gold" />
                <KpiCard label="Pending review" value={packs.filter((p) => p.status === 'generated').length} tone="amber" />
                <KpiCard label="Submitted" value={packs.filter((p) => p.status === 'submitted').length} tone="green" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Period</th>
                            <th className="px-3 py-2">Assessment</th>
                            <th className="px-3 py-2">Score</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Artefacts</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {packs.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{p.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{p.name}</td>
                                <td className="px-3 py-2 text-xs">{p.period}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{p.assessment?.title || '—'}</td>
                                <td className="px-3 py-2 text-sm font-semibold text-[#0A1F44]">{Number(p.overall_score || 0).toFixed(2)}</td>
                                <td className="px-3 py-2"><StatusBadge status={p.status === 'submitted' ? 'pass' : 'moderate'} label={p.status} /></td>
                                <td className="px-3 py-2 text-xs">
                                    <span className="inline-flex items-center gap-1 text-[#0A1F44]"><DocumentArrowDownIcon className="w-3 h-3" /> PDF</span>
                                    <span className="inline-flex items-center gap-1 text-[#0A1F44] ml-2"><DocumentArrowDownIcon className="w-3 h-3" /> ZIP</span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
