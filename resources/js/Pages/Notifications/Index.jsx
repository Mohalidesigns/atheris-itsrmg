import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';

export default function NotificationsIndex({ templates = [], notifications = [] }) {
    const { data, setData, post, processing } = useForm({ incident_id: 1, template_key: templates[0]?.template_key || 'cbn_24h' });
    return (
        <AuthenticatedLayout header="Regulatory Notifications">
            <Head title="Regulatory Notifications" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Regulatory Notifications' }]}
                title="Regulatory Notifications"
                subtitle="One-click auto-draft CBN 24h / NDPC 72h / NFIU SAR / NAICOM notes with digital signature routing (Preparer → CISO → CRO → CEO)."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form onSubmit={(e) => { e.preventDefault(); post(route('notifications.draft')); }}
                    className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Draft a notification</h3>
                    <label className="block text-xs text-[#718096] mt-3">Incident ID</label>
                    <input type="number" value={data.incident_id}
                        onChange={(e) => setData('incident_id', e.target.value)}
                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                    <label className="block text-xs text-[#718096] mt-3">Template</label>
                    <select value={data.template_key} onChange={(e) => setData('template_key', e.target.value)}
                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        {templates.map((t) => <option key={t.id} value={t.template_key}>{t.title}</option>)}
                    </select>
                    <button disabled={processing}
                        className="mt-4 w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                        LLM-Draft notification
                    </button>
                </form>
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Active notifications</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Incident</th>
                                <th className="px-3 py-2">Template</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Deadline</th>
                                <th className="px-3 py-2">Signed</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {notifications.map((n) => (
                                <tr key={n.id}>
                                    <td className="px-3 py-2">#{n.incident_id}</td>
                                    <td className="px-3 py-2 text-xs">{n.template_key}</td>
                                    <td className="px-3 py-2"><StatusBadge status={n.delivery_status} /></td>
                                    <td className="px-3 py-2 text-xs text-[#B3261E]">{n.deadline_at}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{n.signed_at || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
