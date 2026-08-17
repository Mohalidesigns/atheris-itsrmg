import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { LockClosedIcon } from '@heroicons/react/24/outline';

const mb = (b) => (Number(b) / 1024 / 1024).toFixed(2) + ' MB';

export default function EvidenceVaultIndex({ evidence = [], stats = {} }) {
    return (
        <AuthenticatedLayout header="Evidence Vault">
            <Head title="Evidence Vault" />
            <PageHeader
                breadcrumbs={[{ label: 'Compliance' }, { label: 'Evidence Vault (WORM)' }]}
                title="Evidence Vault (WORM)"
                subtitle="S3 Object Lock / MinIO WORM evidence with SHA-256 integrity chain and 7-year retention."
            />
            <div className="grid grid-cols-3 gap-3 mb-4">
                <KpiCard label="Items" value={stats.total || 0} tone="navy" icon={LockClosedIcon} />
                <KpiCard label="WORM-locked" value={stats.worm_locked || 0} tone="gold" />
                <KpiCard label="Total size" value={mb(stats.bytes || 0)} tone="white" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Path</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Size</th>
                            <th className="px-3 py-2">SHA-256</th>
                            <th className="px-3 py-2">Retention Until</th>
                            <th className="px-3 py-2">WORM</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {evidence.map((e) => (
                            <tr key={e.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#2D3748]">{e.file_path}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.category}</td>
                                <td className="px-3 py-2 text-xs">{mb(e.bytes)}</td>
                                <td className="px-3 py-2 font-mono text-[10px] text-[#718096] truncate max-w-[160px]">{e.sha256}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.retention_until}</td>
                                <td className="px-3 py-2">{e.worm_locked ? <StatusBadge status="active" label="Locked" /> : <StatusBadge status="draft" label="Unlocked" />}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
