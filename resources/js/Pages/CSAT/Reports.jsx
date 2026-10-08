import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts';

import { DocumentArrowDownIcon } from '@heroicons/react/24/outline';
import { IR_LEVEL_COLORS as riskColors, MATURITY_COLORS as maturityColors, MATURITY_KEYS, RAG_COLORS, humanize } from '@/Utils/csat';

export default function Reports({ assessment, irScores, domainScores, componentScores, gapCount, recommendations, domainNames, maturityLevels, readiness }) {
    const irChartData = (irScores?.category_scores || []).map(s => ({
        name: s.category_name || `Category ${s.category_code}`,
        score: parseFloat(s.average_score),
        level: s.risk_level,
    }));

    const matChartData = (domainScores || []).map(ds => ({
        name: ds.scope_name?.split(' ').slice(0, 3).join(' ') || ds.scope_code,
        achieved: ds.achieved_maturity_level,
        target: ds.target_maturity_level || 0,
    }));

    return (
        <AuthenticatedLayout header="Executive Report">
            <Head title="Reports" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                <Link href={route('csat.submission-package', assessment.id)} className="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg bg-[#1A365D] text-white">
                    <DocumentArrowDownIcon className="w-4 h-4" /> CBN Submission Package
                </Link>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-center">
                    <p className="text-xs text-[#718096] mb-1">Inherent Risk</p>
                    <span className="inline-block px-4 py-1 rounded-lg text-sm font-bold text-white"
                        style={{ backgroundColor: riskColors[irScores?.level] || '#718096' }}>
                        {irScores?.level ? humanize(irScores.level) : 'N/A'}
                    </span>
                    <p className="text-2xl font-bold font-mono text-[#2D3748] mt-1">{irScores?.score || '—'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-center">
                    <p className="text-xs text-[#718096] mb-1">Maturity Level</p>
                    <span className="inline-block px-4 py-1 rounded-lg text-sm font-bold text-white"
                        style={{ backgroundColor: maturityColors[MATURITY_KEYS.indexOf(assessment.overall_maturity_level)] || '#718096' }}>
                        {assessment.overall_maturity_level?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'N/A'}
                    </span>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-center">
                    <p className="text-xs text-[#718096] mb-1">Gap Statements</p>
                    <p className="text-3xl font-bold font-mono text-[#C53030]">{gapCount}</p>
                    <p className="text-xs text-[#718096]">marked "No"</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-center">
                    <p className="text-xs text-[#718096] mb-1">Submission readiness</p>
                    <p className="text-3xl font-bold font-mono" style={{ color: RAG_COLORS[readiness?.rag] }}>{readiness?.score ?? 0}</p>
                    <p className="text-xs text-[#718096]">{(recommendations || []).length} open recommendation(s)</p>
                </div>
            </div>

            {/* IR Chart */}
            {irChartData.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Inherent Risk by Category</h3>
                    <ResponsiveContainer width="100%" height={200}>
                        <BarChart data={irChartData} layout="vertical">
                            <XAxis type="number" domain={[0, 5]} tick={{ fontSize: 11 }} />
                            <YAxis type="category" dataKey="name" width={230} tick={{ fontSize: 11 }} />
                            <Tooltip />
                            <Bar isAnimationActive={false} dataKey="score" radius={[0, 4, 4, 0]}>
                                {irChartData.map((e, i) => <Cell key={i} fill={riskColors[e.level] || '#718096'} />)}
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}

            {/* Maturity Chart */}
            {matChartData.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Maturity: Achieved vs Target</h3>
                    <ResponsiveContainer width="100%" height={250}>
                        <BarChart data={matChartData} layout="vertical">
                            <XAxis type="number" domain={[0, 5]} tick={{ fontSize: 11 }}
                                tickFormatter={v => maturityLevels[v]?.substring(0, 5) || v} />
                            <YAxis dataKey="name" type="category" width={180} tick={{ fontSize: 11 }} />
                            <Tooltip formatter={(v) => maturityLevels[v] || v} />
                            <Bar isAnimationActive={false} dataKey="achieved" fill="#1A365D" radius={[0, 4, 4, 0]} name="Achieved" />
                            <Bar isAnimationActive={false} dataKey="target" fill="#D4AF37" radius={[0, 4, 4, 0]} name="Target" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}

            {/* Top Recommendations */}
            {(recommendations || []).length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Top Recommendations</h3>
                    <div className="space-y-2">
                        {(recommendations || []).slice(0, 10).map(r => (
                            <div key={r.id} className="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                <span className="w-6 h-6 rounded-full bg-[#1A365D] text-white text-xs font-bold flex items-center justify-center shrink-0">
                                    {r.priority_rank}
                                </span>
                                <div>
                                    <p className="text-sm font-medium text-[#2D3748]">{r.recommendation_text}</p>
                                    <p className="text-xs text-[#718096] mt-1">{humanize(r.recommendation_type)} — {r.scope_reference}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Component Detail Table */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Component Maturity Detail</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-200">
                                <th className="text-left py-2 text-[#718096] font-medium">Component</th>
                                <th className="text-center py-2 text-[#718096] font-medium">Level</th>
                                <th className="text-center py-2 text-[#718096] font-medium">Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(componentScores || []).map(cs => (
                                <tr key={cs.scope_code} className="border-b border-gray-50">
                                    <td className="py-2 text-xs text-[#2D3748]">{cs.scope_name}</td>
                                    <td className="py-2 text-center">
                                        <span className="px-2 py-0.5 rounded text-xs font-bold text-white"
                                            style={{ backgroundColor: maturityColors[cs.achieved_maturity_level] }}>
                                            {maturityLevels[cs.achieved_maturity_level]}
                                        </span>
                                    </td>
                                    <td className="py-2 text-center text-xs text-[#718096]">{parseFloat(cs.completion_pct).toFixed(0)}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
