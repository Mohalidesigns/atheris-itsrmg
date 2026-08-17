import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon, ArrowUpCircleIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowSecurityAlert({ alert }) {
    const destroy = () => {
        if (confirm(`Delete alert ${alert.alert_id_code}? This cannot be undone.`)) {
            router.delete(route('security-alerts.destroy', alert.id));
        }
    };

    const promote = () => {
        if (confirm('Promote this alert to a full incident?')) {
            router.post(route('security-alerts.promote', alert.id));
        }
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{alert.alert_id_code}</span>
                <span>{alert.title}</span>
            </div>
        }>
            <Head title={`${alert.alert_id_code} - ${alert.title}`} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Severity</p>
                    <div className="mt-1"><StatusBadge status={alert.severity} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><StatusBadge status={alert.status} label={cap(alert.status)} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Source</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{alert.source || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Received</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {alert.received_at ? new Date(alert.received_at).toLocaleString() : '--'}
                    </p>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('security-alerts.edit', alert.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
                {!alert.incident_id && (
                    <button onClick={promote}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-lg bg-[#0A1F44] text-white hover:bg-[#1A2F54]">
                        <ArrowUpCircleIcon className="w-4 h-4" /> Promote to Incident
                    </button>
                )}
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Alert Information</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Description" value={alert.description} />
                    <DetailRow label="Linked Incident" value={alert.incident ? (
                        <Link href={route('incidents.show', alert.incident.id)} className="font-mono-data text-[#1A365D] hover:underline">
                            {alert.incident.incident_id_code} — {alert.incident.title}
                        </Link>
                    ) : null} />
                    <DetailRow label="Acknowledged By" value={(typeof alert.acknowledged_by === 'object' && alert.acknowledged_by?.name) || (alert.acknowledged_at ? 'Yes' : null)} />
                    <DetailRow label="Acknowledged At" value={alert.acknowledged_at ? new Date(alert.acknowledged_at).toLocaleString() : null} />
                    <DetailRow label="Created" value={new Date(alert.created_at).toLocaleString()} />
                    <DetailRow label="Last Updated" value={new Date(alert.updated_at).toLocaleString()} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
