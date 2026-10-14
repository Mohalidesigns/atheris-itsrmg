import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@/Components/Material/Symbols';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

function CompleteForm({ assessment }) {
    const form = useForm({
        overall_score: assessment.overall_score ?? '',
        findings: assessment.findings ?? '',
        recommendations: assessment.recommendations ?? '',
    });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('vendor-assessments.complete', assessment.id), { preserveScroll: true }); }}
            className="mb-6 rounded-xl border border-[#0A1F44]/20 bg-[#0A1F44]/5 p-4 space-y-3">
            <h3 className="text-sm font-semibold text-[#0A1F44]">Complete this assessment</h3>
            <p className="text-xs text-[#718096]">Completing records the residual rating the score implies and resets the vendor's review clock to its tier's cadence.</p>
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label className="text-xs text-[#718096]">Overall score (0-100)</label>
                    <input type="number" min="0" max="100" step="0.5" value={form.data.overall_score} onChange={(e) => form.setData('overall_score', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    {form.errors.overall_score && <p className="text-[11px] text-[#C53030]">{form.errors.overall_score}</p>}
                </div>
                <div className="sm:col-span-3">
                    <label className="text-xs text-[#718096]">Findings</label>
                    <textarea rows={2} value={form.data.findings} onChange={(e) => form.setData('findings', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    {form.errors.findings && <p className="text-[11px] text-[#C53030]">{form.errors.findings}</p>}
                </div>
            </div>
            <textarea rows={2} placeholder="Recommendations (optional)" value={form.data.recommendations} onChange={(e) => form.setData('recommendations', e.target.value)} className="w-full rounded-lg border-gray-300 text-sm" />
            <div className="flex justify-end">
                <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Complete assessment</button>
            </div>
        </form>
    );
}

export default function ShowVendorAssessment({ assessment, impliedResidual = null, can = {} }) {
    const destroy = () => {
        if (confirm('Delete this vendor assessment? This cannot be undone.')) {
            router.delete(route('vendor-assessments.destroy', assessment.id));
        }
    };

    return (
        <AuthenticatedLayout header={assessment.title}>
            <Head title={assessment.title} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Vendor</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {assessment.vendor ? (
                            <Link href={route('vendors.show', assessment.vendor.id)} className="text-[#1A365D] hover:underline">
                                {assessment.vendor.name}
                            </Link>
                        ) : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{cap(assessment.assessment_type)}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><StatusBadge status={assessment.status} label={cap(assessment.status)} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Overall Score</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">
                        {assessment.overall_score != null ? Number(assessment.overall_score).toFixed(1) : '--'}
                    </p>
                </div>
            </div>

            {can.edit && assessment.status !== 'completed' && <CompleteForm assessment={assessment} />}

            {assessment.breach_event && (
                <div className="mb-4 rounded-xl border border-[#E5A100]/40 bg-[#FFF8E6] p-3 text-xs text-[#8A6400]">
                    Opened from a breach-watch event: <strong>{assessment.breach_event.headline}</strong> ({assessment.breach_event.severity}, {new Date(assessment.breach_event.discovered_at).toLocaleDateString('en-GB')}).
                </div>
            )}

            <div className="flex gap-2 mb-4">
                {can.edit && <Link href={route('vendor-assessments.edit', assessment.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>}
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Assessment Detail</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Assessor" value={assessment.assessor?.name} />
                    <DetailRow label="Assessment Date" value={assessment.assessment_date ? new Date(assessment.assessment_date).toLocaleDateString('en-GB') : null} />
                    <DetailRow label="Next Review" value={assessment.next_review_date ? new Date(assessment.next_review_date).toLocaleDateString('en-GB') : null} />
                    <DetailRow label="Findings" value={assessment.findings} />
                    <DetailRow label="Recommendations" value={assessment.recommendations} />
                    <DetailRow label="Residual rating" value={assessment.residual_rating ? cap(assessment.residual_rating) : (impliedResidual ? `${cap(impliedResidual)} (implied by the score; recorded on completion)` : null)} />
                    <DetailRow label="Completed" value={assessment.completed_at ? new Date(assessment.completed_at).toLocaleString('en-GB') : null} />
                    <DetailRow label="Vendor tier / risk" value={assessment.vendor ? `Tier ${assessment.vendor.tier ?? '—'} · ${cap(assessment.vendor.risk_level)}` : null} />
                    <DetailRow label="Vendor next review" value={assessment.vendor?.next_review_date ? new Date(assessment.vendor.next_review_date).toLocaleDateString('en-GB') : null} />
                    <DetailRow label="Created" value={new Date(assessment.created_at).toLocaleString('en-GB')} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
