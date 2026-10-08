import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';
import { RatingBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';

import { formatDate } from '@/Utils/risk';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
const ngn = (n) => n == null ? null : new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(Number(n));

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value ?? '--'}</dd>
        </div>
    );
}

export default function ShowRiskAssessment({ assessment, likelihoodLabels = {}, impactLabels = {} }) {
    const destroy = () => {
        if (confirm('Delete this assessment? If it set the risk\'s current score, the risk reverts to its previous assessment.')) {
            router.delete(route('risk-assessments.destroy', assessment.id));
        }
    };

    return (
        <AuthenticatedLayout header="Risk Assessment Detail">
            <Head title="Risk Assessment Detail" />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Risk</p>
                    <p className="mt-1 text-sm font-medium">
                        {assessment.risk ? (
                            <Link href={route('risks.show', assessment.risk.id)} className="font-mono-data text-[#1A365D] hover:underline">
                                {assessment.risk.risk_id_code}
                            </Link>
                        ) : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Methodology</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748] capitalize">{assessment.methodology}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748] capitalize">{assessment.assessment_type}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Score</p>
                    <div className="mt-1 flex items-center gap-2">
                        <ScoreDisplay score={assessment.score} />
                        {assessment.rating && <RatingBadge rating={assessment.rating} />}
                    </div>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('risk-assessments.edit', assessment.id)}
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
                    <DetailRow label="Risk" value={assessment.risk ? `${assessment.risk.risk_id_code} — ${assessment.risk.title}` : null} />
                    <DetailRow label="Assessor" value={assessment.assessor?.name} />
                    <DetailRow label="Assessment Date" value={formatDate(assessment.assessment_date)} />
                    {assessment.methodology === 'qualitative' && (
                        <>
                            <DetailRow label="Likelihood" value={assessment.likelihood != null ? `${assessment.likelihood} — ${likelihoodLabels[assessment.likelihood] || ''}` : null} />
                            <DetailRow label="Impact" value={assessment.impact != null ? `${assessment.impact} — ${impactLabels[assessment.impact] || ''}` : null} />
                            <DetailRow label="Financial Impact" value={assessment.impact_financial} />
                            <DetailRow label="Operational Impact" value={assessment.impact_operational} />
                            <DetailRow label="Reputational Impact" value={assessment.impact_reputational} />
                            <DetailRow label="Regulatory Impact" value={assessment.impact_regulatory} />
                            <DetailRow label="Safety Impact" value={assessment.impact_safety} />
                        </>
                    )}
                    {assessment.methodology === 'fair' && (
                        <>
                            <DetailRow label="Threat Event Frequency" value={assessment.fair_tef} />
                            <DetailRow label="Vulnerability" value={assessment.fair_vul} />
                            <DetailRow label="Loss Event Frequency" value={assessment.fair_lef} />
                            <DetailRow label="Annualised Loss Expectancy" value={ngn(assessment.fair_ale)} />
                        </>
                    )}
                    <DetailRow label="Justification" value={assessment.justification} />
                    <DetailRow label="Notes" value={assessment.notes} />
                    <DetailRow label="Next Review" value={formatDate(assessment.next_review_date)} />
                    <DetailRow label="Created" value={formatDate(assessment.created_at, true)} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
