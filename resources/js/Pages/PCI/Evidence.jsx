import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { FolderIcon, LockClosedIcon } from '@heroicons/react/24/outline';

const mb = (b) => (Number(b || 0) / 1024 / 1024).toFixed(2) + ' MB';

export default function PciEvidence({ vaultItems = [], byReq = {}, summary = {} }) {
    return (
        <AuthenticatedLayout header="PCI Evidence">
            <Head title="PCI Evidence Repository" />
            <PageHeader
                breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Evidence Repository' }]}
                title="PCI-DSS Evidence Repository"
                subtitle="WORM-locked evidence (SHA-256 + 7-year retention) mapped to each requirement."
                actions={<Link href={route('evidence-vault.index')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open Evidence Vault</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Items" value={summary.total || 0} tone="navy" icon={FolderIcon} />
                <KpiCard label="WORM locked" value={summary.total || 0} sublabel="100%" tone="gold" icon={LockClosedIcon} />
                <KpiCard label="Total size" value={mb(summary.size)} tone="white" />
                <KpiCard label="Retention" value="7 years" tone="green" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                {Object.entries(byReq).map(([code, items]) => (
                    <div key={code} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">{code}</h3>
                        <p className="text-[11px] text-[#718096] mb-3">{items.length} evidence item(s)</p>
                        <ul className="space-y-1.5 text-xs">
                            {items.map((e, i) => (
                                <li key={i} className="flex items-center justify-between">
                                    <span className="font-mono text-[10px] text-[#2D3748] truncate max-w-[220px]">{e.file_path}</span>
                                    <StatusBadge status="active" label="Locked" />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>

            <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Full vault index (top 80)</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Path</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Size</th>
                            <th className="px-3 py-2">Retention</th>
                            <th className="px-3 py-2">SHA-256</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {vaultItems.map((e) => (
                            <tr key={e.id}>
                                <td className="px-3 py-2 font-mono text-[11px] text-[#2D3748]">{e.file_path}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.category}</td>
                                <td className="px-3 py-2 text-xs">{mb(e.bytes)}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.retention_until}</td>
                                <td className="px-3 py-2 font-mono text-[10px] text-[#718096] truncate max-w-[150px]">{e.sha256}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
