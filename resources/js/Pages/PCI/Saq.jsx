import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head } from '@inertiajs/react';

const responseTone = (r) => ({ yes: 'pass', yes_cc: 'warn', no: 'fail', na: 'draft' })[r] || 'draft';

export default function PciSaq({ sections = [], summary = {} }) {
    const pct = summary.total ? Math.round(((summary.yes + summary.yes_cc) / summary.total) * 100) : 0;
    return (
        <AuthenticatedLayout header="SAQ-D (Service Provider)">
            <Head title="PCI-DSS SAQ-D" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Self-Assessment (SAQ)' }]}
                title="PCI-DSS v4.0.1 SAQ-D"
                subtitle={`Service-provider self-assessment · ${summary.total} questions · ${pct}% compliant`}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Questions" value={summary.total} tone="navy" />
                <KpiCard label="Yes" value={summary.yes} tone="green" />
                <KpiCard label="Yes + CC" value={summary.yes_cc} tone="amber" />
                <KpiCard label="No" value={summary.no} tone="red" />
                <KpiCard label="N/A" value={summary.na} tone="white" />
            </div>
            <div className="space-y-3">
                {sections.map((s) => (
                    <div key={s.code} className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                        <div className="p-4 border-b border-gray-100 bg-[#0A1F44]/5">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">{s.code} — {s.title}</h3>
                        </div>
                        <ul className="divide-y divide-gray-100">
                            {s.questions.map((q) => (
                                <li key={q.code} className="p-3 flex items-start justify-between gap-3 text-sm">
                                    <div>
                                        <span className="font-mono text-xs text-[#0A1F44] mr-2">{q.code}</span>
                                        <span className="text-[#2D3748]">{q.question}</span>
                                    </div>
                                    <StatusBadge status={responseTone(q.response)} label={q.response.replace('_', ' ')} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
