import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STEPS = ['draft', 'in_review', 'approved', 'submitted'];

export default function NotificationShow({ notification: n, canApprove = false }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const isSuper = (auth?.user?.roles || []).includes('Super Admin');
    const canEdit = isSuper || perms.includes('edit incidents');
    const editable = canEdit && ['draft', 'in_review'].includes(n.delivery_status);

    const body = useForm({ draft_body: n.draft_body || '' });
    const [reference, setReference] = useState('');
    const [refError, setRefError] = useState(null);
    const transition = (action, extra = {}) => router.post(route('notifications.transition', n.id), { action, ...extra }, {
        preserveScroll: true,
        onError: (e) => setRefError(e.submission_reference || null),
    });

    const overdue = n.delivery_status !== 'submitted' && n.deadline_at && new Date(n.deadline_at) < new Date();
    const stepIndex = STEPS.indexOf(n.delivery_status);

    return (
        <AuthenticatedLayout header="Regulatory Notifications">
            <Head title={`${n.regulator_code} notification`} />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Regulatory Notifications', href: route('notifications.index') }, { label: n.incident?.incident_id_code || `#${n.id}` }]}
                title={n.template?.title || `${n.regulator_code} notification`}
                subtitle={n.incident ? `${n.incident.incident_id_code} · ${n.incident.title}` : undefined}
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                {STEPS.map((s, i) => (
                    <div key={s} className="flex items-center gap-2">
                        <span className={`rounded-lg px-3 py-1 text-xs ${i < stepIndex ? 'bg-[#2D7D46]/10 text-[#2D7D46]' : i === stepIndex ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#718096]'} whitespace-nowrap`}>
                            {humanize(s)}
                        </span>
                        {i < STEPS.length - 1 && <span className="text-gray-300">→</span>}
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm lg:col-span-2">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Notification text</h3>
                        {!editable && <span className="text-xs text-[#718096]">Locked{n.delivery_status === 'approved' ? ': return to draft to edit' : ''}</span>}
                    </div>
                    <form onSubmit={(e) => { e.preventDefault(); body.put(route('notifications.update', n.id), { preserveScroll: true }); }}>
                        <textarea value={body.data.draft_body} onChange={(e) => body.setData('draft_body', e.target.value)} readOnly={!editable}
                            rows={22} className={`w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs leading-relaxed ${editable ? '' : 'bg-gray-50'}`} />
                        <InputError message={body.errors.draft_body} className="mt-1" />
                        {editable && (
                            <button disabled={body.processing || !body.isDirty} className="mt-2 rounded-lg border border-[#0A1F44]/20 px-3 py-1.5 text-xs text-[#0A1F44] disabled:opacity-50">
                                Save draft
                            </button>
                        )}
                    </form>
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <dl className="space-y-2">
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Status</dt><dd><StatusBadge status={n.delivery_status === 'submitted' ? 'delivered' : n.delivery_status} label={humanize(n.delivery_status)} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Deadline</dt>
                                <dd className={overdue ? 'font-semibold text-[#B3261E]' : 'text-[#2D3748]'}>{formatDate(n.deadline_at, true)}{overdue ? ' · overdue' : ''}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Clock started</dt>
                                <dd className="text-[#2D3748]">{formatDate(n.regulator_code === 'NDPC' && n.incident?.breach?.discovered_at ? n.incident.breach.discovered_at : n.incident?.detected_at, true)}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Prepared by</dt><dd className="text-[#2D3748]">{n.preparer?.name || '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Approved by</dt><dd className="text-[#2D3748]">{n.approver ? `${n.approver.name}, ${formatDate(n.signed_at, true)}` : '—'}</dd></div>
                            {n.submitted_at && (
                                <div className="flex justify-between gap-2"><dt className="text-[#718096]">Submitted</dt>
                                    <dd className="text-right text-[#2D3748]">{formatDate(n.submitted_at, true)} by {n.submitter?.name}<span className="block font-mono text-xs">{n.submission_reference}</span></dd></div>
                            )}
                        </dl>
                        {n.incident && (
                            <Link href={route('incidents.show', n.incident.id)} className="mt-3 block text-xs text-[#0A1F44] underline">Open incident {n.incident.incident_id_code} →</Link>
                        )}
                    </div>

                    {canEdit && n.delivery_status !== 'submitted' && (
                        <div className="space-y-2 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Next step</h3>
                            {n.delivery_status === 'draft' && (
                                <button type="button" onClick={() => transition('submit_review')} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Send for approval</button>
                            )}
                            {n.delivery_status === 'in_review' && (canApprove ? (
                                <button type="button" onClick={() => transition('approve')} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Approve and sign</button>
                            ) : (
                                <p className="text-xs text-[#718096]">Awaiting approval by someone other than the preparer, holding the approve incidents permission.</p>
                            ))}
                            {n.delivery_status === 'approved' && (
                                <form onSubmit={(e) => { e.preventDefault(); transition('submit', { submission_reference: reference }); }}>
                                    <label className="block text-xs text-[#718096]">Regulator's acknowledgement / reference</label>
                                    <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder={`e.g. ${({ CBN: "CBN/BSD/ITS", NDPC: "NDPC/BR", NFIU: "NFIU/STR", NAICOM: "NAICOM/INC" })[n.regulator_code] || n.regulator_code}/2026/0412`}
                                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                                    <InputError message={refError} className="mt-1" />
                                    <button className="mt-2 w-full rounded-lg bg-[#2D7D46] px-3 py-2 text-sm text-white">Record submission</button>
                                </form>
                            )}
                            {['in_review', 'approved'].includes(n.delivery_status) && (
                                <button type="button" onClick={() => transition('return')} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs text-[#2D3748]">Return to draft</button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
