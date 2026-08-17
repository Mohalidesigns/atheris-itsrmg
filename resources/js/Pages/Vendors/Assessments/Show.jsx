import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowVendorAssessment({ assessment }) {
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

            <div className="flex gap-2 mb-4">
                <Link href={route('vendor-assessments.edit', assessment.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
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
                    <DetailRow label="Assessment Date" value={assessment.assessment_date ? new Date(assessment.assessment_date).toLocaleDateString() : null} />
                    <DetailRow label="Next Review" value={assessment.next_review_date ? new Date(assessment.next_review_date).toLocaleDateString() : null} />
                    <DetailRow label="Findings" value={assessment.findings} />
                    <DetailRow label="Recommendations" value={assessment.recommendations} />
                    <DetailRow label="Vendor Risk Level" value={assessment.vendor?.risk_level ? cap(assessment.vendor.risk_level) : null} />
                    <DetailRow label="Created" value={new Date(assessment.created_at).toLocaleString()} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
