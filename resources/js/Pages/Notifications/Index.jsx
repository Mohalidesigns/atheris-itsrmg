import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

function Countdown({ deadline, submitted }) {
    if (submitted || !deadline) return null;
    const ms = new Date(deadline).getTime() - Date.now();
    const h = Math.round(Math.abs(ms) / 36e5);
    const text = h >= 48 ? `${Math.round(h / 24)}d` : `${h}h`;
    return ms < 0
        ? <span className="block font-semibold text-[#B3261E]">{text} overdue</span>
        : <span className={`block ${h <= 24 ? 'font-semibold text-[#8A6400]' : 'text-[#718096]'}`}>{text} left</span>;
}

export default function NotificationsIndex({ notifications = [], templates = [], incidents = [], counts = {}, deadlineHours = {}, filters = {} }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const canDraft = (auth?.user?.roles || []).includes('Super Admin') || perms.includes('create incidents');
    const { data, setData, post, processing, errors } = useForm({
        incident_id: incidents[0]?.id || '',
        template_key: templates[0]?.template_key || '',
    });
    const chosen = incidents.find((i) => String(i.id) === String(data.incident_id));

    return (
        <AuthenticatedLayout header="Regulatory Notifications">
            <Head title="Regulatory Notifications" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Regulatory Notifications' }]}
                title="Regulatory Notifications"
                subtitle="Draft CBN, NDPC, NFIU and NAICOM notifications from the incident record, route them for approval, and record the submission against the regulator's deadline."
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                {[
                    ['Open', counts.open, ''],
                    ['Overdue', counts.overdue, 'overdue'],
                    ['Due in 24h', counts.due_24h, ''],
                    ['Submitted', counts.submitted, 'submitted'],
                ].map(([label, n, status]) => (
                    <button key={label} type="button" onClick={() => router.get(route('notifications.index'), { status: status || undefined }, { preserveState: true, replace: true })} className="text-left">
                        <KpiCard label={label} value={n ?? 0} tone={label === 'Overdue' && n ? 'red' : (filters.status || '') === status ? 'navy' : 'white'} />
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4">
                    {canDraft && (
                        <form onSubmit={(e) => { e.preventDefault(); post(route('notifications.draft')); }}
                            className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Draft a notification</h3>
                            <label className="mt-3 block text-xs text-[#718096]">Incident</label>
                            <select value={data.incident_id} onChange={(e) => setData('incident_id', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                {incidents.length === 0 && <option value="">No active or recent incidents</option>}
                                {incidents.map((i) => (
                                    <option key={i.id} value={i.id}>{i.incident_id_code} · {i.title}</option>
                                ))}
                            </select>
                            <InputError message={errors.incident_id} className="mt-1" />
                            {chosen && (
                                <p className="mt-1 text-[11px] text-[#718096]">
                                    {humanize(chosen.severity)} · {humanize(chosen.status)}{chosen.is_data_breach ? ' · personal data breach: NDPC notification applies' : ''}
                                </p>
                            )}
                            <label className="mt-3 block text-xs text-[#718096]">Regulator template</label>
                            <select value={data.template_key} onChange={(e) => setData('template_key', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                {templates.map((t) => (
                                    <option key={t.id} value={t.template_key}>{t.title} ({deadlineHours[t.regulator_code] ?? '?'}h)</option>
                                ))}
                            </select>
                            <InputError message={errors.template_key} className="mt-1" />
                            <button disabled={processing || !data.incident_id}
                                className="mt-4 w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                                Draft from incident record
                            </button>
                            <p className="mt-2 text-[11px] text-[#718096]">
                                The draft is filled from the incident (and its data breach, if any). The deadline runs from when the incident was detected.
                            </p>
                        </form>
                    )}
                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-xs text-[#4A5568] shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Approval route</h3>
                        <p className="mt-2">Draft → In review → Approved (signed by someone other than the preparer) → Submitted (with the regulator's reference).</p>
                        <p className="mt-2">Deadlines: {Object.entries(deadlineHours).map(([r, h]) => `${r} ${h}h`).join(' · ')}</p>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm lg:col-span-2">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">
                        {filters.status ? `${humanize(filters.status)} notifications` : 'Notifications'}
                    </h3>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Regulator</th>
                                    <th className="px-3 py-2">Incident</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Deadline</th>
                                    <th className="px-3 py-2">Prepared / approved</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {notifications.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-[#718096]">No notifications.</td></tr>}
                                {notifications.map((n) => (
                                    <tr key={n.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2">
                                            <Link href={route('notifications.show', n.id)} className="text-[#0A1F44] hover:underline">
                                                <span className="mr-1 rounded bg-[#0A1F44] px-1.5 py-0.5 text-[10px] text-white">{n.regulator_code}</span>
                                                {n.template?.title || n.template_key}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            {n.incident ? (
                                                <Link href={route('incidents.show', n.incident.id)} className="text-[#0A1F44] underline">{n.incident.incident_id_code}</Link>
                                            ) : '—'}
                                            <span className="block max-w-[14rem] truncate text-[#718096]">{n.incident?.title}</span>
                                        </td>
                                        <td className="px-3 py-2"><StatusBadge status={n.delivery_status === 'submitted' ? 'delivered' : n.delivery_status} label={humanize(n.delivery_status)} /></td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">
                                            {formatDate(n.deadline_at, true)}
                                            <Countdown deadline={n.deadline_at} submitted={n.delivery_status === 'submitted'} />
                                        </td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {n.preparer?.name || '—'}
                                            <span className="block">{n.approver ? `✓ ${n.approver.name}` : 'not approved'}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
