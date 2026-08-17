import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { ArrowDownTrayIcon, ArrowUpTrayIcon } from '@heroicons/react/24/outline';

export default function Exchange({ jobs = [] }) {
    const queue = (type) => router.post(route('ea.exchange.queue'), { type });
    return (
        <AuthenticatedLayout header="ArchiMate Exchange">
            <Head title="ArchiMate Open Exchange" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'ArchiMate Exchange' }]}
                title="ArchiMate 3.2 Open Exchange"
                subtitle="Full round-trip export/import against the Open Group Exchange Format. Round-trip verification blocks phase exit."
                actions={<>
                    <button onClick={() => queue('export')} className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                        <ArrowDownTrayIcon className="w-4 h-4" /> Export
                    </button>
                    <button onClick={() => queue('import')} className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold">
                        <ArrowUpTrayIcon className="w-4 h-4" /> Import
                    </button>
                </>}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Jobs" value={jobs.length} tone="navy" />
                <KpiCard label="Completed" value={jobs.filter((j) => j.status === 'completed').length} tone="green" />
                <KpiCard label="Queued" value={jobs.filter((j) => j.status === 'queued').length} tone="amber" />
                <KpiCard label="Failed" value={jobs.filter((j) => j.status === 'failed').length} tone="red" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2">Format</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Elements</th>
                            <th className="px-3 py-2">Relationships</th>
                            <th className="px-3 py-2">File</th>
                            <th className="px-3 py-2">When</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {jobs.map((j) => (
                            <tr key={j.id}>
                                <td className="px-3 py-2 text-xs capitalize">{j.type}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{j.format}</td>
                                <td className="px-3 py-2"><StatusBadge status={j.status === 'completed' ? 'pass' : j.status === 'failed' ? 'fail' : 'warn'} label={j.status} /></td>
                                <td className="px-3 py-2 text-xs">{j.element_count}</td>
                                <td className="px-3 py-2 text-xs">{j.relationship_count}</td>
                                <td className="px-3 py-2 font-mono text-[10px] text-[#718096]">{j.file_path || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{j.created_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
