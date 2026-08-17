import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon } from '@heroicons/react/24/outline';

const severityColors = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
    info: '#319795',
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

export default function ShowVulnerability({ vulnerability }) {
    const [activeTab, setActiveTab] = useState('details');

    const severityColor = severityColors[vulnerability.severity] || '#718096';

    const statusMap = {
        open: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        in_progress: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        mitigated: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        resolved: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        closed: { bg: 'bg-gray-100', text: 'text-gray-500' },
        accepted: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]' },
    };
    const statusStyle = statusMap[vulnerability.status] || statusMap.open;

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {vulnerability.vuln_id_code || `VULN-${String(vulnerability.id).padStart(4, '0')}`}
                </span>
                <span>{vulnerability.title}</span>
            </div>
        }>
            <Head title={`${vulnerability.vuln_id_code || 'VULN'} - ${vulnerability.title}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Severity</p>
                    <p className="mt-1 text-sm font-semibold capitalize" style={{ color: severityColor }}>
                        {vulnerability.severity}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">CVSS Score</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#2D3748]">
                        {vulnerability.cvss_score != null ? Number(vulnerability.cvss_score).toFixed(1) : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${statusStyle.bg} ${statusStyle.text}`}>
                            {(vulnerability.status || '').replace(/_/g, ' ')}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Assigned To</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {vulnerability.assignee?.name || 'Unassigned'}
                    </p>
                </div>
            </div>

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('vulnerabilities.edit', vulnerability.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['details', 'tickets'].map(tab => (
                        <TabButton key={tab} active={activeTab === tab} onClick={() => setActiveTab(tab)}>
                            {tab.charAt(0).toUpperCase() + tab.slice(1)}
                            {tab === 'tickets' && vulnerability.tickets?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-full">{vulnerability.tickets.length}</span>
                            )}
                        </TabButton>
                    ))}
                </div>

                <div className="p-5">
                    {activeTab === 'details' && (
                        <div className="space-y-4">
                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                <p className="text-sm text-[#2D3748]">{vulnerability.description || 'No description provided.'}</p>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">CVE ID</h4>
                                    <p className="text-sm font-mono-data text-[#2D3748]">{vulnerability.cve_id || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Source</h4>
                                    <p className="text-sm text-[#2D3748]">{vulnerability.source || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Due Date</h4>
                                    <p className="text-sm text-[#2D3748]">{vulnerability.due_date || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Created</h4>
                                    <p className="text-sm text-[#2D3748]">{vulnerability.created_at || '--'}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'tickets' && (
                        <div>
                            {vulnerability.tickets?.length > 0 ? (
                                <div className="space-y-2">
                                    {vulnerability.tickets.map(ticket => {
                                        const ticketStatusMap = {
                                            open: 'bg-[#DD6B20]/10 text-[#DD6B20]',
                                            in_progress: 'bg-[#319795]/10 text-[#319795]',
                                            resolved: 'bg-[#2D7D46]/10 text-[#2D7D46]',
                                            closed: 'bg-gray-100 text-gray-500',
                                        };
                                        return (
                                            <div key={ticket.id} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                                <div>
                                                    <p className="text-sm font-medium text-[#2D3748]">{ticket.title}</p>
                                                    <p className="text-xs text-[#718096]">
                                                        {ticket.assignee?.name || 'Unassigned'} {ticket.due_date ? `- Due: ${ticket.due_date}` : ''}
                                                    </p>
                                                </div>
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${ticketStatusMap[ticket.status] || ticketStatusMap.open}`}>
                                                    {(ticket.status || '').replace(/_/g, ' ')}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="text-sm text-[#718096] py-8 text-center">No tickets linked to this vulnerability.</p>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
