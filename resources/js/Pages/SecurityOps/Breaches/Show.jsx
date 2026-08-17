import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    PencilIcon,
    ExclamationTriangleIcon,
    ShieldCheckIcon,
    ClockIcon,
    CheckCircleIcon,
} from '@heroicons/react/24/outline';

const severityColors = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
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

export default function ShowBreach({ breach }) {
    const [activeTab, setActiveTab] = useState('details');

    const statusMap = {
        open: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        investigating: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        contained: { bg: 'bg-[#1A365D]/10', text: 'text-[#1A365D]' },
        notifying: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]' },
        remediated: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        closed: { bg: 'bg-gray-100', text: 'text-gray-500' },
    };
    const statusStyle = statusMap[breach.status] || statusMap.open;

    const ndpaRequired = breach.ndpa_notification_required;
    const ndpaNotified = breach.ndpa_notified_at;
    const ndpaDeadline = breach.ndpa_notification_deadline;

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {breach.breach_id_code || `BRC-${String(breach.id).padStart(4, '0')}`}
                </span>
                <span>{breach.title}</span>
            </div>
        }>
            <Head title={`${breach.breach_id_code || 'BRC'} - ${breach.title}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${statusStyle.bg} ${statusStyle.text}`}>
                            {(breach.status || '').replace(/_/g, ' ')}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Breach Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748] capitalize">
                        {(breach.breach_type || '--').replace(/_/g, ' ')}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Records Affected</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#2D3748]">
                        {breach.records_affected != null
                            ? Number(breach.records_affected).toLocaleString()
                            : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Assigned To</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {breach.assignee?.name || 'Unassigned'}
                    </p>
                </div>
            </div>

            {/* NDPA Notification Banner */}
            {ndpaRequired && (
                <div className={`rounded-xl border p-4 mb-6 ${
                    ndpaNotified
                        ? 'bg-[#2D7D46]/5 border-[#2D7D46]/20'
                        : 'bg-[#C53030]/5 border-[#C53030]/20'
                }`}>
                    <div className="flex items-center gap-3">
                        {ndpaNotified ? (
                            <CheckCircleIcon className="w-6 h-6 text-[#2D7D46]" />
                        ) : (
                            <ExclamationTriangleIcon className="w-6 h-6 text-[#C53030]" />
                        )}
                        <div>
                            <h4 className={`text-sm font-semibold ${ndpaNotified ? 'text-[#2D7D46]' : 'text-[#C53030]'}`}>
                                NDPA Notification {ndpaNotified ? 'Completed' : 'Required'}
                            </h4>
                            <div className="flex items-center gap-4 mt-1 text-xs text-[#718096]">
                                {ndpaDeadline && (
                                    <span className="flex items-center gap-1">
                                        <ClockIcon className="w-3 h-3" />
                                        Deadline: {ndpaDeadline}
                                    </span>
                                )}
                                {ndpaNotified && (
                                    <span className="flex items-center gap-1">
                                        <CheckCircleIcon className="w-3 h-3" />
                                        Notified: {ndpaNotified}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('data-breaches.edit', breach.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['details', 'incident', 'notification'].map(tab => (
                        <TabButton key={tab} active={activeTab === tab} onClick={() => setActiveTab(tab)}>
                            {tab.charAt(0).toUpperCase() + tab.slice(1)}
                        </TabButton>
                    ))}
                </div>

                <div className="p-5">
                    {activeTab === 'details' && (
                        <div className="space-y-4">
                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                <p className="text-sm text-[#2D3748]">{breach.description || 'No description provided.'}</p>
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Breach Type</h4>
                                    <p className="text-sm text-[#2D3748] capitalize">{(breach.breach_type || '--').replace(/_/g, ' ')}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Records Affected</h4>
                                    <p className="text-sm font-mono-data font-semibold text-[#2D3748]">
                                        {breach.records_affected != null ? Number(breach.records_affected).toLocaleString() : '--'}
                                    </p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Data Types</h4>
                                    <p className="text-sm text-[#2D3748]">{breach.data_types_affected || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Created</h4>
                                    <p className="text-sm text-[#2D3748]">{breach.created_at || '--'}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'incident' && (
                        <div>
                            {breach.incident ? (
                                <div className="space-y-4">
                                    <div className="border border-gray-100 rounded-lg p-4">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <Link
                                                    href={route('incidents.show', breach.incident.id)}
                                                    className="font-mono-data text-xs text-[#1A365D] font-semibold hover:underline"
                                                >
                                                    {breach.incident.incident_id_code || `INC-${String(breach.incident.id).padStart(4, '0')}`}
                                                </Link>
                                                <p className="text-sm font-medium text-[#2D3748] mt-1">{breach.incident.title}</p>
                                                <p className="text-xs text-[#718096] mt-1">{breach.incident.description?.substring(0, 200)}</p>
                                            </div>
                                            <div className="flex items-center gap-2 shrink-0">
                                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize" style={{
                                                    backgroundColor: `${severityColors[breach.incident.severity] || '#718096'}15`,
                                                    color: severityColors[breach.incident.severity] || '#718096',
                                                }}>
                                                    {breach.incident.severity}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <ShieldCheckIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                    <p className="text-sm text-[#718096]">No incident linked to this breach.</p>
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'notification' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="bg-gray-50 rounded-lg p-4 text-center">
                                    <p className="text-xs text-[#718096] uppercase font-medium mb-2">NDPA Required</p>
                                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${
                                        ndpaRequired
                                            ? 'bg-[#C53030]/10 text-[#C53030]'
                                            : 'bg-gray-100 text-gray-500'
                                    }`}>
                                        {ndpaRequired ? 'Yes' : 'No'}
                                    </span>
                                </div>
                                <div className="bg-gray-50 rounded-lg p-4 text-center">
                                    <p className="text-xs text-[#718096] uppercase font-medium mb-2">Deadline</p>
                                    <p className="text-sm font-semibold text-[#2D3748]">
                                        {ndpaDeadline || '--'}
                                    </p>
                                </div>
                                <div className="bg-gray-50 rounded-lg p-4 text-center">
                                    <p className="text-xs text-[#718096] uppercase font-medium mb-2">Notified At</p>
                                    <p className="text-sm font-semibold text-[#2D3748]">
                                        {ndpaNotified || '--'}
                                    </p>
                                </div>
                            </div>

                            {ndpaRequired && !ndpaNotified && (
                                <div className="bg-[#DD6B20]/5 border border-[#DD6B20]/20 rounded-lg p-4">
                                    <div className="flex items-center gap-2">
                                        <ExclamationTriangleIcon className="w-5 h-5 text-[#DD6B20]" />
                                        <p className="text-sm font-medium text-[#DD6B20]">
                                            NDPA notification has not been sent yet. Please ensure compliance within the deadline.
                                        </p>
                                    </div>
                                </div>
                            )}

                            {ndpaNotified && (
                                <div className="bg-[#2D7D46]/5 border border-[#2D7D46]/20 rounded-lg p-4">
                                    <div className="flex items-center gap-2">
                                        <CheckCircleIcon className="w-5 h-5 text-[#2D7D46]" />
                                        <p className="text-sm font-medium text-[#2D7D46]">
                                            NDPA notification was successfully sent on {ndpaNotified}.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
