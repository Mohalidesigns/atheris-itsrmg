import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon, CheckCircleIcon, ClockIcon } from '@heroicons/react/24/outline';

const statusColors = {
    draft: { bg: 'bg-gray-100', text: 'text-gray-700' },
    in_review: { bg: 'bg-yellow-50', text: 'text-yellow-700' },
    approved: { bg: 'bg-blue-50', text: 'text-blue-700' },
    published: { bg: 'bg-green-50', text: 'text-[#2D7D46]' },
    retired: { bg: 'bg-red-50', text: 'text-[#C53030]' },
};

const attestationStatusColors = {
    pending: { bg: 'bg-yellow-50', text: 'text-yellow-700' },
    acknowledged: { bg: 'bg-green-50', text: 'text-[#2D7D46]' },
    declined: { bg: 'bg-red-50', text: 'text-[#C53030]' },
    overdue: { bg: 'bg-orange-50', text: 'text-orange-700' },
};

function StatusBadge({ status, colorMap }) {
    const colors = colorMap[status] || { bg: 'bg-gray-100', text: 'text-gray-700' };
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
            {status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
        </span>
    );
}

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

export default function ShowPolicy({ policy, userAttestation, categories }) {
    const [activeTab, setActiveTab] = useState('content');

    const handlePublish = () => {
        router.post(route('policies.publish', policy.id));
    };

    const handleAttest = () => {
        router.post(route('policies.attest', policy.id));
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {policy.policy_code}
                </span>
                <span>{policy.title}</span>
            </div>
        }>
            <Head title={`${policy.policy_code} - ${policy.title}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <StatusBadge status={policy.status} colorMap={statusColors} />
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Version</p>
                    <p className="mt-1 text-lg font-bold font-mono text-[#2D3748]">v{policy.version_number}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Owner</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{policy.owner?.name || 'Unassigned'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Effective Date</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{policy.effective_date || 'Not set'}</p>
                </div>
            </div>

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('policies.edit', policy.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
                {policy.status === 'approved' && (
                    <button
                        onClick={handlePublish}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-[#2D7D46] text-white rounded-lg hover:bg-[#246838]"
                    >
                        <CheckCircleIcon className="w-4 h-4" />
                        Publish
                    </button>
                )}
                {policy.status === 'published' && (!userAttestation || userAttestation.status !== 'acknowledged') && (
                    <button
                        onClick={handleAttest}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-[#D4AF37] text-white rounded-lg hover:bg-[#B8962F]"
                    >
                        <CheckCircleIcon className="w-4 h-4" />
                        Acknowledge Policy
                    </button>
                )}
                {userAttestation?.status === 'acknowledged' && (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-[#2D7D46] bg-green-50 rounded-lg">
                        <CheckCircleIcon className="w-4 h-4" />
                        Acknowledged
                    </span>
                )}
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['content', 'versions', 'attestations'].map(tab => (
                        <TabButton key={tab} active={activeTab === tab} onClick={() => setActiveTab(tab)}>
                            {tab.charAt(0).toUpperCase() + tab.slice(1)}
                            {tab === 'versions' && policy.versions?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-full">{policy.versions.length}</span>
                            )}
                            {tab === 'attestations' && policy.attestations?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-full">{policy.attestations.length}</span>
                            )}
                        </TabButton>
                    ))}
                </div>

                <div className="p-5">
                    {activeTab === 'content' && (
                        <div className="space-y-4">
                            {/* Policy metadata */}
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Category</h4>
                                    <p className="text-sm text-[#2D3748]">{categories[policy.category] || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Mandatory</h4>
                                    <p className="text-sm text-[#2D3748]">{policy.is_mandatory ? 'Yes' : 'No'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Review Date</h4>
                                    <p className="text-sm text-[#2D3748]">{policy.review_date || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Approved By</h4>
                                    <p className="text-sm text-[#2D3748]">{policy.approver?.name || '--'}</p>
                                </div>
                            </div>

                            {policy.description && (
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                    <p className="text-sm text-[#2D3748]">{policy.description}</p>
                                </div>
                            )}

                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">Policy Content</h4>
                                {policy.content ? (
                                    <div className="prose prose-sm max-w-none text-[#2D3748] whitespace-pre-wrap bg-gray-50/50 rounded-lg p-4 border border-gray-100">
                                        {policy.content}
                                    </div>
                                ) : (
                                    <p className="text-sm text-[#718096] italic">No content has been added yet.</p>
                                )}
                            </div>
                        </div>
                    )}

                    {activeTab === 'versions' && (
                        <div>
                            {policy.versions?.length === 0 ? (
                                <p className="text-sm text-[#718096] py-8 text-center">No version history available.</p>
                            ) : (
                                <div className="space-y-3">
                                    {policy.versions.map(version => (
                                        <div key={version.id} className="border border-gray-100 rounded-lg p-4">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    <span className="font-mono text-sm font-semibold text-[#1A365D] bg-[#1A365D]/5 px-2 py-0.5 rounded">
                                                        v{version.version_number}
                                                    </span>
                                                    <span className="text-sm text-[#2D3748]">
                                                        {version.change_summary || 'No change summary'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 text-xs text-[#718096]">
                                                    <ClockIcon className="w-3.5 h-3.5" />
                                                    {new Date(version.created_at).toLocaleDateString()}
                                                </div>
                                            </div>
                                            <p className="mt-1 text-xs text-[#718096]">
                                                By {version.creator?.name || 'Unknown'}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'attestations' && (
                        <div>
                            {policy.attestations?.length === 0 ? (
                                <p className="text-sm text-[#718096] py-8 text-center">No attestations recorded yet.</p>
                            ) : (
                                <div className="space-y-3">
                                    {policy.attestations.map(attestation => (
                                        <div key={attestation.id} className="border border-gray-100 rounded-lg p-4">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    <span className="text-sm font-medium text-[#2D3748]">
                                                        {attestation.user?.name || 'Unknown User'}
                                                    </span>
                                                    <span className="text-xs text-[#718096]">
                                                        {attestation.user?.email}
                                                    </span>
                                                </div>
                                                <StatusBadge status={attestation.status} colorMap={attestationStatusColors} />
                                            </div>
                                            <div className="flex items-center gap-4 mt-2 text-xs text-[#718096]">
                                                {attestation.acknowledged_at && (
                                                    <span>Acknowledged: {new Date(attestation.acknowledged_at).toLocaleDateString()}</span>
                                                )}
                                                {attestation.due_date && (
                                                    <span>Due: {attestation.due_date}</span>
                                                )}
                                            </div>
                                            {attestation.notes && (
                                                <p className="mt-2 text-sm text-[#718096]">{attestation.notes}</p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
