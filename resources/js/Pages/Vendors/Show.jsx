import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon } from '@heroicons/react/24/outline';

const riskLevelColors = {
    critical: { bg: '#FEE2E2', text: '#C53030' },
    high: { bg: '#FED7AA', text: '#DD6B20' },
    medium: { bg: '#FEF3C7', text: '#D4AF37' },
    low: { bg: '#D1FAE5', text: '#2D7D46' },
};

const statusColors = {
    active: { bg: '#D1FAE5', text: '#2D7D46' },
    inactive: { bg: '#E2E8F0', text: '#718096' },
    under_review: { bg: '#FEF3C7', text: '#D4AF37' },
    terminated: { bg: '#FEE2E2', text: '#C53030' },
};

const assessmentStatusColors = {
    pending: { bg: '#FEF3C7', text: '#D4AF37' },
    in_progress: { bg: '#DBEAFE', text: '#1A365D' },
    completed: { bg: '#D1FAE5', text: '#2D7D46' },
};

function TabButton({ active, children, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                active
                    ? 'border-[#D4AF37] text-[#1A365D]'
                    : 'border-transparent text-[#718096] hover:text-[#2D3748] hover:border-gray-300'
            }`}
        >
            {children}
        </button>
    );
}

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

function formatCurrency(value, currency = 'NGN') {
    if (!value) return '--';
    return new Intl.NumberFormat('en-NG', { style: 'currency', currency }).format(value);
}

export default function ShowVendor({ vendor }) {
    const [activeTab, setActiveTab] = useState('details');

    const riskColors = riskLevelColors[vendor.risk_level] || riskLevelColors.medium;
    const statColors = statusColors[vendor.status] || statusColors.active;

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {vendor.vendor_code}
                </span>
                <span>{vendor.name}</span>
            </div>
        }>
            <Head title={`${vendor.vendor_code} - ${vendor.name}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Risk Level</p>
                    <div className="mt-1">
                        <span className="inline-flex items-center px-2.5 py-0.5 text-xs font-semibold rounded-full capitalize"
                            style={{ backgroundColor: riskColors.bg, color: riskColors.text }}>
                            {vendor.risk_level}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span className="inline-flex items-center px-2.5 py-0.5 text-xs font-semibold rounded-full capitalize"
                            style={{ backgroundColor: statColors.bg, color: statColors.text }}>
                            {vendor.status?.replace('_', ' ')}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Contract Value</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {formatCurrency(vendor.contract_value, vendor.contract_currency)}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Data Access Level</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748] capitalize">
                        {vendor.data_access_level || 'Not set'}
                    </p>
                </div>
            </div>

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('vendors.edit', vendor.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['details', 'assessments'].map(tab => (
                        <TabButton key={tab} active={activeTab === tab} onClick={() => setActiveTab(tab)}>
                            {tab.charAt(0).toUpperCase() + tab.slice(1)}
                            {tab === 'assessments' && vendor.assessments?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-full">{vendor.assessments.length}</span>
                            )}
                        </TabButton>
                    ))}
                </div>

                <div className="p-6">
                    {activeTab === 'details' && (
                        <div>
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-2">General Information</h4>
                            <DetailRow label="Description" value={vendor.description} />
                            <DetailRow label="Category" value={vendor.category} />
                            <DetailRow label="Country" value={vendor.country} />
                            <DetailRow label="Website" value={vendor.website ? (
                                <a href={vendor.website} target="_blank" rel="noopener noreferrer" className="text-[#1A365D] hover:underline">
                                    {vendor.website}
                                </a>
                            ) : null} />
                            <DetailRow label="Services Provided" value={vendor.services_provided} />

                            <h4 className="text-sm font-semibold text-[#2D3748] mt-6 mb-2">Contact</h4>
                            <DetailRow label="Contact Name" value={vendor.contact_name} />
                            <DetailRow label="Contact Email" value={vendor.contact_email} />
                            <DetailRow label="Contact Phone" value={vendor.contact_phone} />

                            <h4 className="text-sm font-semibold text-[#2D3748] mt-6 mb-2">Contract</h4>
                            <DetailRow label="Contract Start" value={vendor.contract_start ? new Date(vendor.contract_start).toLocaleDateString() : null} />
                            <DetailRow label="Contract End" value={vendor.contract_end ? new Date(vendor.contract_end).toLocaleDateString() : null} />
                            <DetailRow label="Contract Value" value={formatCurrency(vendor.contract_value, vendor.contract_currency)} />
                            <DetailRow label="SLA Details" value={vendor.sla_details} />

                            <h4 className="text-sm font-semibold text-[#2D3748] mt-6 mb-2">Assessment</h4>
                            <DetailRow label="Last Assessed" value={vendor.last_assessed ? new Date(vendor.last_assessed).toLocaleDateString() : null} />
                            <DetailRow label="Next Review Date" value={vendor.next_review_date ? new Date(vendor.next_review_date).toLocaleDateString() : null} />
                        </div>
                    )}

                    {activeTab === 'assessments' && (
                        <div>
                            {vendor.assessments?.length === 0 ? (
                                <p className="text-[#718096] text-sm py-8 text-center">No assessments recorded yet.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100">
                                                <th className="px-3 py-2 text-left font-medium text-[#718096]">Title</th>
                                                <th className="px-3 py-2 text-left font-medium text-[#718096]">Type</th>
                                                <th className="px-3 py-2 text-center font-medium text-[#718096]">Score</th>
                                                <th className="px-3 py-2 text-left font-medium text-[#718096]">Status</th>
                                                <th className="px-3 py-2 text-left font-medium text-[#718096]">Assessed By</th>
                                                <th className="px-3 py-2 text-left font-medium text-[#718096]">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {vendor.assessments.map(assessment => {
                                                const aColors = assessmentStatusColors[assessment.status] || assessmentStatusColors.pending;
                                                return (
                                                    <tr key={assessment.id} className="hover:bg-gray-50/50">
                                                        <td className="px-3 py-2 font-medium text-[#2D3748]">{assessment.title}</td>
                                                        <td className="px-3 py-2 text-[#718096] capitalize">{assessment.assessment_type?.replace('_', ' ')}</td>
                                                        <td className="px-3 py-2 text-center">
                                                            {assessment.overall_score !== null ? (
                                                                <span className="font-mono-data font-semibold text-[#1A365D]">{assessment.overall_score}%</span>
                                                            ) : '--'}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
                                                                style={{ backgroundColor: aColors.bg, color: aColors.text }}>
                                                                {assessment.status?.replace('_', ' ')}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2 text-[#718096]">{assessment.assessor?.name || '--'}</td>
                                                        <td className="px-3 py-2 text-[#718096]">
                                                            {new Date(assessment.assessment_date).toLocaleDateString()}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
