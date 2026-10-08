import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { STATUS_LABELS, formatDate } from '@/Utils/csat';
import { PlusIcon } from '@heroicons/react/24/outline';

const statusColors = {
    draft: 'bg-gray-100 text-gray-700',
    in_progress: 'bg-blue-100 text-blue-700',
    pending_approval: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    submitted: 'bg-purple-100 text-purple-700',
};

const riskLevelColors = {
    least: 'bg-[#2D7D46] text-white',
    minimal: 'bg-[#319795] text-white',
    moderate: 'bg-[#D4AF37] text-white',
    significant: 'bg-[#DD6B20] text-white',
    most: 'bg-[#C53030] text-white',
};

export default function CsatIndex({ assessments }) {
    return (
        <AuthenticatedLayout header="CBN Cybersecurity Self-Assessment">
            <Head title="CBN-CSAT Assessments" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-xl font-bold text-[#2D3748]">Assessment Cycles</h2>
                    <p className="text-sm text-[#718096]">CBN-mandated annual cybersecurity self-assessment (FFIEC CAT v1.1)</p>
                </div>
                <Link
                    href={route('csat.create')}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]"
                >
                    <PlusIcon className="w-4 h-4" /> New Assessment
                </Link>
            </div>

            {assessments.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-100 p-12 text-center shadow-sm">
                    <div className="w-16 h-16 bg-[#1A365D]/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg className="w-8 h-8 text-[#1A365D]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-1">No assessments yet</h3>
                    <p className="text-sm text-[#718096] mb-4">Create your first CBN Cybersecurity Self-Assessment to get started.</p>
                    <Link
                        href={route('csat.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]"
                    >
                        <PlusIcon className="w-4 h-4" /> Create Assessment
                    </Link>
                </div>
            ) : (
                <div className="grid gap-4">
                    {assessments.map(a => (
                        <Link
                            key={a.id}
                            href={route('csat.overview', a.id)}
                            className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:border-[#1A365D]/30 hover:shadow-md transition-all"
                        >
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="flex items-center gap-3 mb-1">
                                        <h3 className="text-lg font-bold text-[#2D3748]">
                                            {a.assessment_year} Assessment
                                        </h3>
                                        <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${statusColors[a.status] || 'bg-gray-100'}`}>
                                            {STATUS_LABELS[a.status] || a.status}
                                        </span>
                                    </div>
                                    <p className="text-sm text-[#718096]">
                                        Framework: {a.framework_version} | Created by {a.creator?.name}
                                    </p>
                                    {a.submission_deadline && (
                                        <p className="text-xs text-[#718096] mt-1">
                                            {a.status === 'submitted' ? `Submitted: ${formatDate(a.submitted_at)}` : `Deadline: ${formatDate(a.submission_deadline)}`}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    {a.composite_risk_level && (
                                        <span className={`px-3 py-1 rounded-lg text-xs font-bold ${riskLevelColors[a.composite_risk_level]}`}>
                                            {a.composite_risk_level.charAt(0).toUpperCase() + a.composite_risk_level.slice(1)} Risk
                                        </span>
                                    )}
                                    {a.overall_maturity_level && (
                                        <span className="px-3 py-1 rounded-lg text-xs font-bold bg-[#1A365D] text-white">
                                            {a.overall_maturity_level.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
