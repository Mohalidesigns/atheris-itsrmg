import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function AuditTrail({ logs = [], byAction = {}, entity = null }) {
    const [filter, setFilter] = useState(entity || '');
    const submit = () => router.get(route('ea.audit-trail'), { entity_type: filter });

    return (
        <AuthenticatedLayout header="EA Audit Trail">
            <Head title="EA Audit Trail" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Operations' }, { label: 'Audit Trail' }]}
                title="EA Append-Only Audit Log"
                subtitle="Every create, update, delete and relate action across the EA repository is recorded with actor, before/after diff and IP."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.audit-trail')} current="ea.audit-trail" />

            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                {Object.entries(byAction).map(([a, c]) => <KpiCard key={a} label={a} value={c} tone="white" />)}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4 flex items-center gap-2">
                <input className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Filter by entity_type (e.g. App\\Models\\Ea\\Capability)" value={filter} onChange={(e) => setFilter(e.target.value)} />
                <button onClick={submit} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Filter</button>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <table className="w-full text-xs">
                    <thead className="text-[10px] text-gray-500 border-b border-gray-100">
                        <tr><th className="text-left py-2">When</th><th className="text-left">Actor</th><th className="text-left">Action</th><th className="text-left">Entity</th><th className="text-left">ID</th><th className="text-left">IP</th></tr>
                    </thead>
                    <tbody>
                        {logs.map((l) => (
                            <tr key={l.id} className="border-b border-gray-50">
                                <td className="py-2">{l.created_at}</td>
                                <td>{l.actor_email || `#${l.actor_id}`}</td>
                                <td><StatusBadge status={l.action === 'delete' ? 'critical' : l.action === 'create' ? 'compliant' : 'in_progress'} label={l.action} /></td>
                                <td className="font-mono">{(l.entity_type || '').split('\\').pop()}</td>
                                <td>{l.entity_id}</td>
                                <td>{l.ip}</td>
                            </tr>
                        ))}
                        {logs.length === 0 && <tr><td colSpan={6} className="py-6 text-center text-gray-400 text-sm">No log entries.</td></tr>}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
