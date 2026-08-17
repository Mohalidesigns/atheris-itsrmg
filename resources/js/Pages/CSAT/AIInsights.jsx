import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const typeColors = { gap_analysis: '#C53030', best_practice: '#1A365D', cbn_compliance: '#D4AF37', quick_win: '#2D7D46' };
const typeLabels = { gap_analysis: 'Gap Analysis', best_practice: 'Best Practice', cbn_compliance: 'CBN Compliance', quick_win: 'Quick Win' };

export default function AIInsights({ assessment, recommendations }) {
    const dismiss = (id) => {
        router.put(route('csat.ai.dismiss', [assessment.id, id]), {}, { preserveScroll: true });
    };

    const grouped = {};
    (recommendations || []).forEach(r => {
        const t = r.recommendation_type || 'other';
        if (!grouped[t]) grouped[t] = [];
        grouped[t].push(r);
    });

    return (
        <AuthenticatedLayout header="AI-Powered Insights">
            <Head title="AI Insights" />

            <div className="mb-4">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
            </div>

            <p className="text-sm text-[#718096] mb-6">
                AI-generated recommendations based on your assessment responses, maturity gaps, and CBN compliance requirements.
            </p>

            {(recommendations || []).length === 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                    <p className="text-sm text-[#718096]">No AI recommendations generated yet. Complete the assessment to generate insights.</p>
                </div>
            )}

            {Object.entries(grouped).map(([type, recs]) => (
                <div key={type} className="mb-6">
                    <div className="flex items-center gap-2 mb-3">
                        <span className="w-3 h-3 rounded-full" style={{ backgroundColor: typeColors[type] || '#718096' }} />
                        <h3 className="text-sm font-semibold text-[#2D3748]">{typeLabels[type] || type}</h3>
                        <span className="text-xs text-[#718096]">({recs.length})</span>
                    </div>
                    <div className="space-y-2">
                        {recs.map(r => (
                            <div key={r.id} className={`bg-white rounded-xl border border-gray-100 p-4 shadow-sm ${r.is_dismissed ? 'opacity-50' : ''}`}>
                                <div className="flex items-start justify-between">
                                    <div className="flex items-start gap-3 flex-1">
                                        <span className="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
                                            style={{ backgroundColor: typeColors[type] || '#718096' }}>
                                            {r.priority_rank}
                                        </span>
                                        <div>
                                            <p className="text-sm text-[#2D3748]">{r.recommendation_text}</p>
                                            {r.scope_reference && (
                                                <p className="text-xs text-[#718096] mt-1">Scope: {r.scope_reference}</p>
                                            )}
                                            {r.estimated_effort && (
                                                <span className="inline-block mt-1 px-2 py-0.5 bg-gray-100 text-xs text-[#718096] rounded">
                                                    Effort: {r.estimated_effort}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {!r.is_dismissed && (
                                        <button onClick={() => dismiss(r.id)}
                                            className="px-2 py-1 text-xs text-[#718096] hover:text-[#C53030] hover:bg-red-50 rounded ml-2">
                                            Dismiss
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </AuthenticatedLayout>
    );
}
