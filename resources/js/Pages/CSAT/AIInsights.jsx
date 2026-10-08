import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowPathIcon } from '@heroicons/react/24/outline';
import { RAG_COLORS, formatDate, humanize } from '@/Utils/csat';

// Matches csat_ai_recommendations.recommendation_type.
const TYPES = {
    readiness_flag: { label: 'Submission blockers', color: '#C53030' },
    gap_analysis: { label: 'Maturity gaps vs target', color: '#DD6B20' },
    policy_gap: { label: 'Compensating control follow-ups', color: '#D4AF37' },
    threat_enrichment: { label: 'Threat register', color: '#1A365D' },
    narrative_draft: { label: 'Narratives', color: '#319795' },
    yoy_summary: { label: 'Year-on-year', color: '#718096' },
};

export default function AIInsights({ assessment, recommendations = [], readiness, engine }) {
    const [busy, setBusy] = useState(false);
    const [showDismissed, setShowDismissed] = useState(false);
    const perms = usePage().props.auth?.user?.permissions || [];
    const canEdit = perms.includes('edit csat') || (usePage().props.auth?.user?.roles || []).includes('Super Admin');

    const active = recommendations.filter(r => !r.is_dismissed);
    const dismissed = recommendations.filter(r => r.is_dismissed);
    const grouped = Object.keys(TYPES).map(t => [t, active.filter(r => r.recommendation_type === t)]).filter(([, rs]) => rs.length);
    const generatedAt = active[0]?.generated_at || dismissed[0]?.generated_at;

    const regenerate = () => router.post(route('csat.ai.generate', assessment.id), {}, {
        preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false),
    });
    const dismiss = (id) => router.put(route('csat.ai.dismiss', [assessment.id, id]), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header="Assessment Insights">
            <Head title="Insights" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                {canEdit && (
                    <button onClick={regenerate} disabled={busy} className="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg bg-[#1A365D] text-white disabled:opacity-50">
                        <ArrowPathIcon className={`w-4 h-4 ${busy ? 'animate-spin' : ''}`} /> {busy ? 'Analysing…' : 'Refresh insights'}
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Submission readiness</p>
                    <p className="text-3xl font-bold font-mono-data mt-1" style={{ color: RAG_COLORS[readiness?.rag] }}>{readiness?.score ?? 0}<span className="text-base">/100</span></p>
                    <p className="text-xs mt-1" style={{ color: RAG_COLORS[readiness?.rag] }}>{humanize(readiness?.rag)} · ≥85 green, 65–84 amber, &lt;65 red</p>
                </div>
                <div className="md:col-span-2 bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-sm text-[#718096]">
                    <p>
                        Recommendations are derived deterministically from this assessment's answers, targets, compensating controls,
                        narratives and registers by the <span className="font-mono text-xs">{engine}</span> engine — every item traces back to a record you can open.
                    </p>
                    <p className="text-xs mt-2">{generatedAt ? `Last generated ${formatDate(generatedAt, true)}` : 'Not generated yet — click Refresh insights.'}</p>
                </div>
            </div>

            {grouped.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center text-sm text-[#718096]">
                    No open recommendations{active.length === 0 && dismissed.length ? ' — all have been dismissed' : ''}.
                </div>
            ) : grouped.map(([type, items]) => (
                <div key={type} className="mb-6">
                    <h3 className="text-sm font-semibold mb-2" style={{ color: TYPES[type].color }}>{TYPES[type].label} ({items.length})</h3>
                    <div className="space-y-2">
                        {items.map(r => (
                            <div key={r.id} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    {r.scope_reference && <p className="text-xs font-semibold text-[#2D3748]">{r.scope_reference}</p>}
                                    <p className="text-sm text-[#2D3748] mt-0.5">{r.recommendation_text}</p>
                                    <p className="text-xs text-[#718096] mt-1">
                                        {r.cbn_framework_ref}{r.effort_estimate ? ` · effort: ${humanize(r.effort_estimate)}` : ''}
                                    </p>
                                </div>
                                {canEdit && <button onClick={() => dismiss(r.id)} className="text-xs text-[#718096] hover:text-[#C53030] shrink-0">Dismiss</button>}
                            </div>
                        ))}
                    </div>
                </div>
            ))}

            {dismissed.length > 0 && (
                <div className="mt-2">
                    <button onClick={() => setShowDismissed(!showDismissed)} className="text-xs text-[#718096] underline">{showDismissed ? 'Hide' : 'Show'} {dismissed.length} dismissed</button>
                    {showDismissed && (
                        <ul className="mt-2 space-y-1">
                            {dismissed.map(r => <li key={r.id} className="text-xs text-[#A0AEC0] line-through">{r.recommendation_text}</li>)}
                        </ul>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
