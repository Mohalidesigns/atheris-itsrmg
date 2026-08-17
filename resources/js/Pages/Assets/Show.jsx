import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EaAssetPanel from '@/Components/Ea/EaAssetPanel';
import { Head, Link } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

const criticalityColors = {
    critical: { bg: '#FEE2E2', text: '#C53030' },
    high: { bg: '#FED7AA', text: '#DD6B20' },
    medium: { bg: '#FEF3C7', text: '#D4AF37' },
    low: { bg: '#D1FAE5', text: '#2D7D46' },
};

const statusColors = {
    active: { bg: '#D1FAE5', text: '#2D7D46' },
    inactive: { bg: '#E2E8F0', text: '#718096' },
    decommissioned: { bg: '#FEE2E2', text: '#C53030' },
    under_review: { bg: '#FEF3C7', text: '#D4AF37' },
};

const typeLabels = {
    hardware: 'Hardware',
    software: 'Software',
    cloud_service: 'Cloud Service',
    database: 'Database',
    network: 'Network',
    facility: 'Facility',
};

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowAsset({ asset, ea }) {
    const critColors = criticalityColors[asset.criticality] || criticalityColors.medium;
    const statColors = statusColors[asset.status] || statusColors.active;

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {asset.asset_id_code}
                </span>
                <span>{asset.name}</span>
            </div>
        }>
            <Head title={`${asset.asset_id_code} - ${asset.name}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {typeLabels[asset.asset_type] || asset.asset_type}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Criticality</p>
                    <div className="mt-1">
                        <span className="inline-flex items-center px-2.5 py-0.5 text-xs font-semibold rounded-full capitalize"
                            style={{ backgroundColor: critColors.bg, color: critColors.text }}>
                            {asset.criticality}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span className="inline-flex items-center px-2.5 py-0.5 text-xs font-semibold rounded-full capitalize"
                            style={{ backgroundColor: statColors.bg, color: statColors.text }}>
                            {asset.status?.replace('_', ' ')}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Owner</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {asset.owner?.name || 'Unassigned'}
                    </p>
                </div>
            </div>

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('assets.edit', asset.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
            </div>

            {/* Details */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Asset Information</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Description" value={asset.description} />
                    <DetailRow label="Category" value={asset.category} />
                    <DetailRow label="Department" value={asset.department} />
                    <DetailRow label="Location" value={asset.location} />
                    <DetailRow label="Data Classification" value={asset.data_classification ? asset.data_classification.charAt(0).toUpperCase() + asset.data_classification.slice(1) : null} />
                </div>

                <div className="px-6 py-4 border-t border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Technical Details</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="IP Address" value={asset.ip_address} />
                    <DetailRow label="Hostname" value={asset.hostname} />
                    <DetailRow label="Vendor" value={asset.vendor} />
                    <DetailRow label="Version" value={asset.version} />
                    <DetailRow label="License Type" value={asset.license_type} />
                </div>

                <div className="px-6 py-4 border-t border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Lifecycle</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Purchase Date" value={asset.purchase_date ? new Date(asset.purchase_date).toLocaleDateString() : null} />
                    <DetailRow label="End of Life" value={asset.end_of_life ? new Date(asset.end_of_life).toLocaleDateString() : null} />
                    <DetailRow label="Created" value={new Date(asset.created_at).toLocaleDateString()} />
                    <DetailRow label="Last Updated" value={new Date(asset.updated_at).toLocaleDateString()} />
                </div>
            </div>

            {/* Linked Risks */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mt-6">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Linked Risks</h3>
                    <span className="text-xs text-[#718096]">{(asset.risks || []).length} linked</span>
                </div>
                {(asset.risks || []).length === 0 ? (
                    <p className="px-6 py-8 text-sm text-[#718096] text-center">No risks linked to this asset yet. Link assets from a risk's edit form.</p>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {asset.risks.map(risk => (
                            <li key={risk.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                <div>
                                    <Link href={route('risks.show', risk.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">
                                        {risk.risk_id_code}
                                    </Link>
                                    <p className="text-sm text-[#2D3748]">{risk.title}</p>
                                </div>
                                <div className="flex items-center gap-2 text-xs">
                                    {risk.inherent_rating && (
                                        <span className="px-2 py-0.5 rounded-full bg-gray-100 text-[#2D3748] capitalize">{String(risk.inherent_rating).replace('_', ' ')}</span>
                                    )}
                                    <span className="px-2 py-0.5 rounded-full bg-[#1A365D]/5 text-[#1A365D] capitalize">{risk.status}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Linked Vulnerabilities */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mt-6">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Linked Vulnerabilities</h3>
                    <span className="text-xs text-[#718096]">{(asset.vulnerabilities || []).length} linked</span>
                </div>
                {(asset.vulnerabilities || []).length === 0 ? (
                    <p className="px-6 py-8 text-sm text-[#718096] text-center">No vulnerabilities linked to this asset yet. Link assets from a vulnerability's edit form.</p>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {asset.vulnerabilities.map(vuln => (
                            <li key={vuln.id} className="px-6 py-3 flex items-center justify-between hover:bg-gray-50/50">
                                <div>
                                    <Link href={route('vulnerabilities.show', vuln.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">
                                        {vuln.vuln_id_code}
                                    </Link>
                                    <p className="text-sm text-[#2D3748]">{vuln.title}</p>
                                </div>
                                <div className="flex items-center gap-2 text-xs">
                                    <span className="px-2 py-0.5 rounded-full bg-gray-100 text-[#2D3748] capitalize">{vuln.severity}</span>
                                    <span className="px-2 py-0.5 rounded-full bg-[#1A365D]/5 text-[#1A365D] capitalize">{String(vuln.status || '').replace('_', ' ')}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* ATH-EAR-002 §7.4 (I-3) — the logical architecture view of this
                physical asset: capability, criticality, TIME, zone, and what
                is running on it that has gone out of support. */}
            <div className="mt-6">
                <EaAssetPanel ea={ea} />
            </div>
        </AuthenticatedLayout>
    );
}
