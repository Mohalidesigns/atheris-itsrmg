import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CloudArrowUpIcon, DocumentTextIcon } from '@heroicons/react/24/outline';

export const JOB_STATUS = {
    uploaded: ['draft', 'Uploaded'],
    ocr_done: ['in_progress', 'Text extracted'],
    extraction_done: ['in_progress', 'Extracted'],
    awaiting_review: ['in_review', 'Awaiting review'],
    accepted: ['pass', 'Accepted'],
    rejected: ['fail', 'Rejected'],
};

export function ConfidenceBadge({ value, threshold = 0.9 }) {
    const pct = Math.round(Number(value || 0) * 100);
    const tone = pct === 0 ? 'fail' : pct / 100 < threshold ? 'warn' : 'pass';
    return <StatusBadge status={tone} label={`${pct}%`} />;
}

export default function DocIntelIndex({ jobs = [], types = {}, filters = {}, stats = {}, threshold = 0.9 }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const canUpload = (auth?.user?.roles || []).includes('Super Admin') || perms.includes('create platform');
    const { data, setData, post, processing, errors, progress, reset } = useForm({ doc_type: 'circular', file: null });
    const apply = (params) => router.get(route('doc-intel.index'), { ...filters, ...params }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header="Document Intelligence">
            <Head title="Document Intelligence" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence' }, { label: 'Document Intelligence' }]}
                title="Document Intelligence"
                subtitle="Upload circulars, NDPC decisions, audit reports or vendor DDQs. Pipeline: text extraction → structured fields → human review → circular feed or vendor record."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Documents" value={stats.total ?? 0} tone="navy" />
                <KpiCard label="Awaiting review" value={stats.awaiting_review ?? 0} tone="gold" />
                <KpiCard label="Below 90% confidence" value={stats.low_confidence ?? 0} tone={stats.low_confidence ? 'amber' : 'white'} />
                <KpiCard label="Accepted" value={stats.accepted ?? 0} tone="green" />
                <KpiCard label="Rejected" value={stats.rejected ?? 0} tone="white" />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {canUpload && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(route('doc-intel.upload'), { forceFormData: true, onSuccess: () => reset('file') });
                        }}
                        className="h-fit rounded-xl border border-gray-100 bg-white p-5 shadow-sm"
                    >
                        <CloudArrowUpIcon className="h-8 w-8 text-[#C9A86A]" />
                        <h3 className="mt-2 text-sm font-semibold text-[#2D3748]">Upload a document</h3>
                        <label className="mt-3 block text-xs text-[#718096]" htmlFor="doc_type">Document type</label>
                        <select id="doc_type" value={data.doc_type} onChange={(e) => setData('doc_type', e.target.value)}
                            className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            {Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </select>
                        <label htmlFor="file"
                            className="mt-3 block cursor-pointer rounded-lg border-2 border-dashed border-gray-300 p-6 text-center hover:border-[#C9A86A]">
                            <DocumentTextIcon className="mx-auto h-6 w-6 text-[#718096]" />
                            <span className="mt-1 block text-xs text-[#718096]">
                                {data.file ? data.file.name : 'Choose a PDF, DOCX or text file (max 20 MB)'}
                            </span>
                            <input id="file" type="file" accept=".pdf,.docx,.txt,.md,.csv" className="sr-only"
                                onChange={(e) => setData('file', e.target.files[0] || null)} />
                        </label>
                        <InputError message={errors.file || errors.doc_type} className="mt-1" />
                        {progress && <p className="mt-2 text-xs text-[#718096]">Uploading {progress.percentage}%</p>}
                        <button disabled={processing || !data.file}
                            className="mt-4 w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                            Upload and extract
                        </button>
                        <p className="mt-3 text-[11px] text-[#718096]">
                            Scanned PDFs have no text layer and arrive with 0% confidence — the reviewer enters the fields.
                            Anything under {Math.round(threshold * 100)}% needs every field confirmed before acceptance.
                        </p>
                    </form>
                )}

                <div className={`overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm ${canUpload ? 'lg:col-span-2' : 'lg:col-span-3'}`}>
                    <div className="flex flex-wrap items-center justify-between gap-2 p-4">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Jobs</h3>
                        <div className="flex gap-2">
                            <select aria-label="Filter by status" value={filters.status || ''} onChange={(e) => apply({ status: e.target.value || undefined })}
                                className="rounded-lg border-gray-200 py-1 text-xs">
                                <option value="">All statuses</option>
                                {Object.entries(JOB_STATUS).map(([k, [, l]]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                            <select aria-label="Filter by type" value={filters.type || ''} onChange={(e) => apply({ type: e.target.value || undefined })}
                                className="rounded-lg border-gray-200 py-1 text-xs">
                                <option value="">All types</option>
                                {Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </div>
                    </div>
                    {jobs.length === 0 ? (
                        <p className="px-4 pb-8 text-center text-xs text-[#718096]">No documents match this filter.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead className="bg-[#F7FAFC]">
                                    <tr className="text-left text-xs uppercase text-[#718096]">
                                        <th className="px-3 py-2">Document</th>
                                        <th className="px-3 py-2">Type</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">Confidence</th>
                                        <th className="px-3 py-2">Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {jobs.map((j) => {
                                        const [tone, label] = JOB_STATUS[j.status] || ['draft', j.status];
                                        return (
                                            <tr key={j.id} className="hover:bg-gray-50/60">
                                                <td className="max-w-[260px] px-3 py-2">
                                                    <Link href={route('doc-intel.show', j.id)} className="block truncate text-[#0A1F44] underline">{j.name}</Link>
                                                </td>
                                                <td className="px-3 py-2 text-xs">{j.doc_type_label}</td>
                                                <td className="px-3 py-2"><StatusBadge status={tone} label={label} /></td>
                                                <td className="px-3 py-2"><ConfidenceBadge value={j.confidence} threshold={threshold} /></td>
                                                <td className="px-3 py-2 text-xs text-[#718096]">
                                                    {j.created_at}
                                                    {j.uploaded_by && <span className="block">{j.uploaded_by}</span>}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
