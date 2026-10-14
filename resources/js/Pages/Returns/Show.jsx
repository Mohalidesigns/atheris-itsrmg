import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDownTrayIcon, ArrowPathIcon } from '@/Components/Material/Symbols';
import { RUN_STATUS } from './Index';

const STEPS = ['draft', 'in_review', 'approved', 'submitted'];
const input = 'w-full rounded-lg border-gray-200 text-sm focus:border-[#0A1F44] focus:ring-[#0A1F44]/30';

function OverrideEditor({ run, field, onDone }) {
    const form = useForm({ key: field.key, value: field.override?.value ?? String(field.computed ?? ''), reason: field.override?.reason ?? '' });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('returns.override', run.id), { preserveScroll: true, onSuccess: onDone });
            }}
            className="mt-2 space-y-2 rounded-lg bg-[#F7FAFC] p-3"
        >
            <input aria-label={`Override ${field.label}`} className={input} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
            <input aria-label="Reason for override" className={input} placeholder="Reason (required — reviewers see it)"
                value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            <InputError message={form.errors.reason || form.errors.value} />
            <div className="flex gap-2">
                <button disabled={form.processing} className="rounded bg-[#0A1F44] px-2 py-1 text-xs text-white">Save override</button>
                <button type="button" onClick={onDone} className="rounded border border-gray-200 px-2 py-1 text-xs">Cancel</button>
            </div>
        </form>
    );
}

export default function ReturnShow({ run, fields = [], can = {} }) {
    const [editing, setEditing] = useState(null);
    const submit = useForm({ action: 'submit', submission_reference: '' });
    const [tone, label] = RUN_STATUS[run.status] || ['draft', run.status];
    const step = STEPS.indexOf(run.status);

    const transition = (action) => router.post(route('returns.transition', run.id), { action }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header="Returns Centre">
            <Head title={`${run.template} ${run.period}`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Regulatory Intelligence' },
                    { label: 'Returns Centre', href: route('returns.index') },
                    { label: `${run.return_code} ${run.period}` },
                ]}
                title={`${run.template} — ${run.period}`}
                subtitle={`${run.regulator_code} · ${run.cadence} return${run.populated_at ? ` · populated ${run.populated_at}` : ''}`}
                actions={
                    <div className="flex items-center gap-2">
                        <StatusBadge status={tone} label={label} />
                        <a href={route('returns.export', run.id)}
                            className="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50">
                            <ArrowDownTrayIcon className="h-4 w-4" /> CSV
                        </a>
                    </div>
                }
            />

            <ol className="mb-5 grid grid-cols-4 gap-2 text-center text-xs">
                {STEPS.map((s, i) => (
                    <li key={s} className={`rounded-lg border px-2 py-2 ${i < step ? 'border-[#2D7D46]/30 bg-[#2D7D46]/5 text-[#2D7D46]' : i === step ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#718096]'}`}>
                        {RUN_STATUS[s][1]}
                    </li>
                ))}
            </ol>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm lg:col-span-2">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Return fields</h3>
                        {can.edit && (
                            <button type="button" onClick={() => router.post(route('returns.refresh', run.id), {}, { preserveScroll: true })}
                                className="inline-flex items-center gap-1 text-xs text-[#0A1F44] underline">
                                <ArrowPathIcon className="h-3.5 w-3.5" /> Recompute from register
                            </button>
                        )}
                    </div>
                    <ul className="divide-y divide-gray-100">
                        {fields.map((f) => (
                            <li key={f.key} className="px-4 py-3">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <p className="text-sm text-[#2D3748]">{f.label}</p>
                                        <p className="text-[10px] uppercase text-[#718096]">
                                            {f.override ? 'Manual override' : f.source ? 'Auto-populated from your register' : 'Manual entry'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className={`text-sm font-semibold ${f.override ? 'text-[#8A6400]' : 'text-[#0A1F44]'}`}>
                                            {(f.override ? f.override.value : f.computed) ?? <span className="font-normal text-[#B3261E]">Not provided</span>}
                                        </p>
                                        {f.override && f.computed !== null && f.computed !== undefined && (
                                            <p className="text-[10px] text-[#718096] line-through">computed {String(f.computed)}</p>
                                        )}
                                    </div>
                                </div>
                                {f.override && (
                                    <p className="mt-1 text-xs text-[#718096]">
                                        “{f.override.reason}” — {f.override.by}
                                        {can.edit && (
                                            <button type="button" className="ml-2 underline"
                                                onClick={() => router.post(route('returns.override', run.id), { key: f.key, clear: true }, { preserveScroll: true })}>
                                                remove
                                            </button>
                                        )}
                                    </p>
                                )}
                                {can.edit && editing !== f.key && (
                                    <button type="button" onClick={() => setEditing(f.key)} className="mt-1 text-xs text-[#0A1F44] underline">
                                        {f.override ? 'Edit override' : f.source ? 'Override' : 'Enter value'}
                                    </button>
                                )}
                                {editing === f.key && <OverrideEditor run={run} field={f} onDone={() => setEditing(null)} />}
                            </li>
                        ))}
                    </ul>
                </div>

                <aside className="h-fit space-y-4">
                    <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 text-xs shadow-sm">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Sign-off</h3>
                        <dl className="space-y-1">
                            <div><dt className="inline text-[#718096]">Prepared by: </dt><dd className="inline">{run.prepared_by || '—'}</dd></div>
                            <div><dt className="inline text-[#718096]">Approved by: </dt><dd className="inline">{run.approved_by ? `${run.approved_by}, ${run.approved_at}` : '—'}</dd></div>
                            <div><dt className="inline text-[#718096]">Submitted: </dt><dd className="inline">{run.submitted_at ? `${run.submitted_at} by ${run.submitted_by}` : '—'}</dd></div>
                            {run.submission_reference && (
                                <div><dt className="inline text-[#718096]">Reference: </dt><dd className="inline font-mono">{run.submission_reference}</dd></div>
                            )}
                        </dl>

                        {run.status === 'draft' && can.edit && (
                            <button type="button" onClick={() => transition('submit_for_review')}
                                className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Send for review</button>
                        )}
                        {run.status === 'in_review' && can.approve && (
                            <div className="space-y-2">
                                {can.is_preparer && (
                                    <p className="rounded-lg bg-[#B3261E]/5 px-3 py-2 text-[#B3261E]">You prepared this return — another approver must sign it off.</p>
                                )}
                                <button type="button" disabled={can.is_preparer} onClick={() => transition('approve')}
                                    className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">Approve</button>
                                <button type="button" onClick={() => transition('return_to_draft')}
                                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-[#2D3748]">Send back to draft</button>
                            </div>
                        )}
                        {run.status === 'in_review' && !can.approve && <p className="text-[#718096]">Awaiting an approver.</p>}
                        {run.status === 'approved' && can.approve && (
                            <form onSubmit={(e) => { e.preventDefault(); submit.post(route('returns.transition', run.id), { preserveScroll: true }); }} className="space-y-2">
                                <label className="block text-[#718096]" htmlFor="submission_reference">Regulator acknowledgement reference</label>
                                <input id="submission_reference" className={input} value={submit.data.submission_reference}
                                    onChange={(e) => submit.setData('submission_reference', e.target.value)} />
                                <InputError message={submit.errors.submission_reference} />
                                <button disabled={submit.processing} className="w-full rounded-lg bg-[#2D7D46] px-3 py-2 text-sm text-white">Mark submitted</button>
                            </form>
                        )}
                    </div>
                    <Link href={route('returns.index')} className="block text-sm text-[#0A1F44] underline">← Returns Centre</Link>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
