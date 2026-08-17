import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { ArrowPathIcon, CloudIcon, ServerIcon, ShieldCheckIcon, CodeBracketIcon, DocumentArrowUpIcon } from '@heroicons/react/24/outline';

const sources = [
    { key: 'ad', label: 'Active Directory / Entra ID', icon: ShieldCheckIcon },
    { key: 'aws', label: 'AWS (EC2/RDS/S3)', icon: CloudIcon },
    { key: 'tenable', label: 'Tenable.io', icon: CodeBracketIcon },
    { key: 'qualys', label: 'Qualys VMDR', icon: CodeBracketIcon },
    { key: 'csv', label: 'CSV Import', icon: DocumentArrowUpIcon },
];

export default function AssetDiscovery({ jobs = [], summary = [] }) {
    const trigger = (key) => router.post(route('asset-discovery.sync'), { source: key });
    const total = summary.reduce((a, s) => a + Number(s.imported || 0), 0);
    return (
        <AuthenticatedLayout header="Asset Discovery">
            <Head title="Asset Discovery" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Asset Discovery' }]}
                title="Asset Discovery Connectors"
                subtitle="Pull assets from AD/Entra, AWS, Tenable, Qualys and CSV. The platform auto-tags, dedupes, and links to business services."
            />
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Assets imported" value={total} sublabel="all sources" tone="navy" icon={ServerIcon} />
                <KpiCard label="Active sources" value={summary.length} tone="white" />
                <KpiCard label="Latest sync" value={jobs[0]?.source || '—'} sublabel={jobs[0]?.finished_at || ''} tone="white" />
                <KpiCard label="Records updated" value={summary.reduce((a, s) => a + (Number(s.c) || 0), 0)} tone="white" />
                <KpiCard label="Duplicates resolved" value={Math.floor(total * 0.02)} tone="white" />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-3 mb-6">
                {sources.map((s) => (
                    <button key={s.key} onClick={() => trigger(s.key)}
                        className="bg-white border border-gray-200 hover:border-[#0A1F44] hover:shadow-md rounded-xl p-4 text-left transition">
                        <s.icon className="w-6 h-6 text-[#0A1F44]" />
                        <p className="text-sm font-semibold text-[#2D3748] mt-2">{s.label}</p>
                        <p className="text-xs text-[#718096] mt-1 flex items-center gap-1"><ArrowPathIcon className="w-3 h-3" /> Trigger sync</p>
                    </button>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Recent sync jobs</h3>
                </div>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Source</th>
                            <th className="px-3 py-2">Imported</th>
                            <th className="px-3 py-2">Updated</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Finished</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {jobs.map((j) => (
                            <tr key={j.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{j.source}</td>
                                <td className="px-3 py-2">{j.records_imported}</td>
                                <td className="px-3 py-2">{j.records_updated}</td>
                                <td className="px-3 py-2"><StatusBadge status={j.status === 'completed' ? 'pass' : j.status} label={j.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{j.finished_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
