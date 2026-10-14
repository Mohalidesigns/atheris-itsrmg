import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowDownTrayIcon } from '@/Components/Material/Symbols';
import { ConfidenceBadge, JOB_STATUS } from './Index';

const input = 'mt-1 w-full rounded-lg border-gray-200 text-sm focus:border-[#0A1F44] focus:ring-[#0A1F44]/30';
const label = (k) => k.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export default function DocIntelShow({ job, fields = [], required = [], vendors = [], isUploader = false }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const canReview = (auth?.user?.roles || []).includes('Super Admin') || perms.includes('edit platform');
    const editable = canReview && job.reviewable;

    const edit = useForm({ fields: Object.fromEntries(fields.map((f) => [f, job.structured_output?.[f] ?? ''])) });
    const accept = useForm({ vendor_id: '', confirm_fields: false, review_notes: '' });
    const reject = useForm({ review_notes: '' });
    const [tone, statusLabel] = JOB_STATUS[job.status] || ['draft', job.status];

    return (
        <AuthenticatedLayout header="Document Intelligence">
            <Head title={job.name} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Regulatory Intelligence' },
                    { label: 'Document Intelligence', href: route('doc-intel.index') },
                    { label: job.name },
                ]}
                title={job.name}
                subtitle={`${job.doc_type_label} · uploaded ${job.created_at}${job.uploaded_by ? ` by ${job.uploaded_by}` : ''}`}
                actions={
                    <div className="flex items-center gap-2">
                        <StatusBadge status={tone} label={statusLabel} />
                        {job.has_file && (
                            <a href={route('doc-intel.download', job.id)}
                                className="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50">
                                <ArrowDownTrayIcon className="h-4 w-4" /> Source file
                            </a>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">Extracted fields</h3>
                            <span className="flex items-center gap-2 text-xs text-[#718096]">
                                Confidence <ConfidenceBadge value={job.confidence} />
                            </span>
                        </div>
                        {job.needs_confirmation && job.reviewable && (
                            <p className="mx-5 mt-4 rounded-lg bg-[#E5A100]/10 px-3 py-2 text-xs text-[#8A6400]">
                                {Number(job.confidence) === 0
                                    ? 'No text layer was found — this looks like a scanned document. Enter the fields from the source file.'
                                    : 'Confidence is below 90%. Check every field against the source document before accepting.'}
                            </p>
                        )}
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                edit.patch(route('doc-intel.update', job.id), { preserveScroll: true });
                            }}
                            className="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2"
                        >
                            {fields.map((f) => (
                                <div key={f} className={f === 'summary' || f === 'title' ? 'sm:col-span-2' : ''}>
                                    <label className="block text-xs text-[#718096]" htmlFor={`f-${f}`}>
                                        {label(f)} {required.includes(f) && <span className="text-[#B3261E]">*</span>}
                                    </label>
                                    {editable ? (
                                        f === 'summary' ? (
                                            <textarea id={`f-${f}`} rows={3} className={input} value={edit.data.fields[f] ?? ''}
                                                onChange={(e) => edit.setData('fields', { ...edit.data.fields, [f]: e.target.value })} />
                                        ) : (
                                            <input id={`f-${f}`} className={input} value={edit.data.fields[f] ?? ''}
                                                onChange={(e) => edit.setData('fields', { ...edit.data.fields, [f]: e.target.value })} />
                                        )
                                    ) : (
                                        <p id={`f-${f}`} className="mt-1 text-sm text-[#2D3748]">{job.structured_output?.[f] ?? '—'}</p>
                                    )}
                                    {!job.structured_output?.[f] && editable && <p className="mt-0.5 text-[10px] text-[#8A6400]">Not found in the text</p>}
                                    <InputError message={edit.errors[`fields.${f}`]} className="mt-1" />
                                </div>
                            ))}
                            {editable && (
                                <div className="sm:col-span-2">
                                    <button disabled={edit.processing || !edit.isDirty}
                                        className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-[#0A1F44] hover:bg-gray-50 disabled:opacity-50">
                                        Save corrections
                                    </button>
                                </div>
                            )}
                        </form>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Extracted text</h3>
                        <pre className="mt-2 max-h-80 overflow-y-auto whitespace-pre-wrap font-sans text-xs text-[#718096]">
                            {job.ocr_excerpt || 'No text layer.'}
                        </pre>
                    </div>
                </div>

                <aside className="h-fit space-y-4">
                    {job.reviewable ? (
                        canReview && (
                            <>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        accept.post(route('doc-intel.accept', job.id), { preserveScroll: true });
                                    }}
                                    className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm"
                                >
                                    <h3 className="text-sm font-semibold text-[#0A1F44]">Accept extraction</h3>
                                    <p className="text-xs text-[#718096]">
                                        {['circular', 'ndpc_decision'].includes(job.doc_type) && 'Accepting adds the document to your circular feed for triage.'}
                                        {job.doc_type === 'vendor_ddq' && 'Accepting records the DDQ as the vendor’s latest assessment.'}
                                        {job.doc_type === 'audit_report' && 'Accepting records the extracted findings against the report.'}
                                    </p>
                                    {isUploader && (
                                        <p className="rounded-lg bg-[#B3261E]/5 px-3 py-2 text-xs text-[#B3261E]">
                                            You uploaded this document — another reviewer must accept it.
                                        </p>
                                    )}
                                    {job.doc_type === 'vendor_ddq' && (
                                        <div>
                                            <label className="block text-xs text-[#718096]" htmlFor="vendor_id">Vendor</label>
                                            <select id="vendor_id" className={input} value={accept.data.vendor_id} onChange={(e) => accept.setData('vendor_id', e.target.value)}>
                                                <option value="">Choose…</option>
                                                {vendors.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
                                            </select>
                                            <InputError message={accept.errors.vendor_id} className="mt-1" />
                                        </div>
                                    )}
                                    {job.needs_confirmation && (
                                        <label className="flex items-start gap-2 text-xs text-[#2D3748]">
                                            <input type="checkbox" className="mt-0.5 rounded border-gray-300" checked={accept.data.confirm_fields}
                                                onChange={(e) => accept.setData('confirm_fields', e.target.checked)} />
                                            I have checked every field against the source document.
                                        </label>
                                    )}
                                    <InputError message={accept.errors.confirm_fields} />
                                    <textarea rows={2} className={input} placeholder="Review notes (optional)" aria-label="Review notes"
                                        value={accept.data.review_notes} onChange={(e) => accept.setData('review_notes', e.target.value)} />
                                    <button disabled={accept.processing || isUploader}
                                        className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                                        Accept
                                    </button>
                                </form>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        reject.post(route('doc-intel.reject', job.id), { preserveScroll: true });
                                    }}
                                    className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm"
                                >
                                    <h3 className="text-sm font-semibold text-[#2D3748]">Reject</h3>
                                    <textarea rows={2} className={input} placeholder="Why is it rejected?" aria-label="Rejection reason"
                                        value={reject.data.review_notes} onChange={(e) => reject.setData('review_notes', e.target.value)} />
                                    <InputError message={reject.errors.review_notes} />
                                    <button disabled={reject.processing}
                                        className="w-full rounded-lg border border-[#B3261E]/30 px-3 py-2 text-sm text-[#B3261E] hover:bg-[#B3261E]/5 disabled:opacity-50">
                                        Reject
                                    </button>
                                </form>
                            </>
                        )
                    ) : (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 text-xs shadow-sm">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">Review</h3>
                            <p className="mt-2 text-[#2D3748]">
                                {statusLabel} by {job.reviewed_by || '—'}{job.reviewed_at ? `, ${job.reviewed_at}` : ''}
                            </p>
                            {job.review_notes && <p className="mt-1 text-[#718096]">{job.review_notes}</p>}
                            {job.circular && (
                                <Link href={route('reg-intel.show', job.circular.id)} className="mt-3 block text-[#0A1F44] underline">
                                    Open in the circular feed →
                                </Link>
                            )}
                            {job.vendor && <p className="mt-3 text-[#2D3748]">Recorded against vendor <strong>{job.vendor.name}</strong>.</p>}
                        </div>
                    )}
                    <Link href={route('doc-intel.index')} className="block text-sm text-[#0A1F44] underline">← All documents</Link>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
