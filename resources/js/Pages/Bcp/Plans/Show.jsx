import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { ClockIcon, ShieldCheckIcon, DocumentTextIcon } from '@heroicons/react/24/outline';

const typeLabels = { bcp: 'BCP', dr: 'DR', crisis: 'Crisis' };
const typeColors = { bcp: '#1A365D', dr: '#2D7D46', crisis: '#C53030' };
const statusColors = {
    draft: '#718096',
    active: '#2D7D46',
    under_review: '#D4AF37',
    tested: '#1A365D',
    expired: '#C53030',
};
const passFailColors = { pass: '#2D7D46', partial: '#D4AF37', fail: '#C53030' };

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

export default function ShowPlan({ plan }) {
    const [activeTab, setActiveTab] = useState('details');

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {plan.plan_code}
                </span>
                <span>{plan.title}</span>
            </div>
        }>
            <Head title={`${plan.plan_code} - ${plan.title}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <div className="mt-1">
                        <span
                            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full text-white"
                            style={{ backgroundColor: typeColors[plan.plan_type] || '#718096' }}
                        >
                            {typeLabels[plan.plan_type] || plan.plan_type}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span
                            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full"
                            style={{
                                backgroundColor: (statusColors[plan.status] || '#718096') + '15',
                                color: statusColors[plan.status] || '#718096',
                            }}
                        >
                            {plan.status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RTO</p>
                    <p className="mt-1 text-lg font-semibold text-[#2D3748] font-mono">
                        {plan.rto_hours != null ? `${plan.rto_hours}h` : '-'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RPO</p>
                    <p className="mt-1 text-lg font-semibold text-[#2D3748] font-mono">
                        {plan.rpo_hours != null ? `${plan.rpo_hours}h` : '-'}
                    </p>
                </div>
            </div>

            {/* Tabs */}
            <div className="border-b border-gray-200 mb-6">
                <div className="flex gap-0">
                    <TabButton active={activeTab === 'details'} onClick={() => setActiveTab('details')}>
                        <span className="flex items-center gap-1.5"><DocumentTextIcon className="w-4 h-4" /> Details</span>
                    </TabButton>
                    <TabButton active={activeTab === 'tests'} onClick={() => setActiveTab('tests')}>
                        <span className="flex items-center gap-1.5"><ShieldCheckIcon className="w-4 h-4" /> Tests ({plan.tests?.length || 0})</span>
                    </TabButton>
                </div>
            </div>

            {/* Details Tab */}
            {activeTab === 'details' && (
                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-4">Plan Information</h3>
                        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Owner</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{plan.owner?.name || 'Unassigned'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Version</dt>
                                <dd className="mt-1 text-sm text-[#2D3748] font-mono">{plan.version}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Last Tested</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{plan.last_tested || 'Never'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Next Test Date</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{plan.next_test_date || 'Not scheduled'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Next Review Date</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{plan.next_review_date || 'Not scheduled'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Approved By</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">
                                    {plan.approver?.name || 'Not approved'}
                                    {plan.approved_at && <span className="text-[#718096] ml-1">({plan.approved_at})</span>}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {plan.description && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Description</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.description}</p>
                        </div>
                    )}

                    {plan.scope && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Scope</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.scope}</p>
                        </div>
                    )}

                    {plan.objectives && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Objectives</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.objectives}</p>
                        </div>
                    )}
                </div>
            )}

            {/* Tests Tab */}
            {activeTab === 'tests' && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    {plan.tests && plan.tests.length > 0 ? (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50/50">
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Title</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Type</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Status</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Scheduled</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Result</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Conductor</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {plan.tests.map(test => (
                                    <tr key={test.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3 text-[#2D3748] font-medium">{test.title}</td>
                                        <td className="px-4 py-3 text-[#718096] capitalize">{test.test_type}</td>
                                        <td className="px-4 py-3">
                                            <span className="capitalize text-[#718096]">{test.status?.replace('_', ' ')}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{test.scheduled_date}</td>
                                        <td className="px-4 py-3">
                                            {test.pass_fail ? (
                                                <span
                                                    className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full text-white"
                                                    style={{ backgroundColor: passFailColors[test.pass_fail] || '#718096' }}
                                                >
                                                    {test.pass_fail.charAt(0).toUpperCase() + test.pass_fail.slice(1)}
                                                </span>
                                            ) : (
                                                <span className="text-[#718096]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{test.conductor?.name || '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <div className="px-4 py-12 text-center text-[#718096]">
                            <ShieldCheckIcon className="w-8 h-8 mx-auto mb-2 text-gray-300" />
                            <p>No tests recorded yet.</p>
                        </div>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
