import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { PlayIcon } from '@heroicons/react/24/outline';

export default function CcmIndex({ tests = [], lastRuns = [], summary = {} }) {
    const run = (id) => router.post(route('ccm.run', id));
    return (
        <AuthenticatedLayout header="Continuous Controls Monitoring">
            <Head title="CCM Console" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'CCM Console' }]}
                title="Continuous Controls Monitoring (CCM)"
                subtitle="40+ pre-built tests for CBN RBCSF, NDPA, PCI-DSS with automated evidence capture."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Tests enabled" value={summary.total} tone="navy" />
                <KpiCard label="Pass" value={summary.pass} tone="green" />
                <KpiCard label="Warn" value={summary.warn} tone="amber" />
                <KpiCard label="Fail" value={summary.fail} tone="red" />
                <KpiCard label="Error" value={summary.error} tone="white" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Test</th>
                            <th className="px-3 py-2">Adapter</th>
                            <th className="px-3 py-2">Last status</th>
                            <th className="px-3 py-2">Last run</th>
                            <th className="px-3 py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tests.map((t) => (
                            <tr key={t.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{t.test?.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{t.test?.title}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.test?.adapter_key}</td>
                                <td className="px-3 py-2">{t.last_status ? <StatusBadge status={t.last_status} /> : '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.last_run_at}</td>
                                <td className="px-3 py-2">
                                    <button onClick={() => run(t.id)} className="inline-flex items-center gap-1 text-xs text-[#0A1F44] hover:underline">
                                        <PlayIcon className="w-3 h-3" /> Run now
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
