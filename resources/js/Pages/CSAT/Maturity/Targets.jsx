import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { MATURITY_COLORS as maturityColors, lockMessage } from '@/Utils/csat';

export default function MaturityTargets({ assessment, domainScores, componentScores, gapStatements, domainNames, maturityLevels, editable = true }) {
    const saveTarget = (scopeCode, level) => {
        router.post(route('csat.ma.target.save', assessment.id), {
            scope_code: scopeCode, target_maturity_level: level,
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Targets & Gap Analysis">
            <Head title="Maturity Targets" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.ma.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Maturity Dashboard</Link>
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            {/* Domain Target Setting */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Domain Maturity Targets</h3>
                <div className="space-y-3">
                    {(domainScores || []).map(ds => {
                        const gap = (ds.target_maturity_level || 0) - ds.achieved_maturity_level;
                        return (
                            <div key={ds.scope_code} className="flex items-center gap-4 border-b border-gray-50 pb-3">
                                <div className="w-48">
                                    <p className="text-sm font-medium text-[#2D3748] truncate">{ds.scope_name}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-[#718096]">Current:</span>
                                    <span className="inline-block px-2 py-0.5 rounded text-xs font-bold text-white"
                                        style={{ backgroundColor: maturityColors[ds.achieved_maturity_level] }}>
                                        {maturityLevels[ds.achieved_maturity_level]}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-[#718096]">Target:</span>
                                    <select value={ds.target_maturity_level || ''} disabled={!editable} onChange={e => e.target.value && saveTarget(ds.scope_code, parseInt(e.target.value))}
                                        className="px-2 py-1 border border-gray-200 rounded text-sm disabled:bg-gray-50">
                                        <option value="">Set target</option>
                                        {[1,2,3,4,5].map(l => <option key={l} value={l}>{maturityLevels[l]}</option>)}
                                    </select>
                                </div>
                                {gap > 0 && (
                                    <span className="px-2 py-0.5 bg-red-50 text-[#C53030] text-xs font-bold rounded">Gap: {gap} level{gap > 1 ? 's' : ''}</span>
                                )}
                                {gap <= 0 && ds.target_maturity_level > 0 && (
                                    <span className="px-2 py-0.5 bg-green-50 text-[#2D7D46] text-xs font-bold rounded">Target Met</span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Component Scores */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Component Maturity Breakdown</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-200">
                                <th className="text-left py-2 text-[#718096] font-medium">Component</th>
                                <th className="text-center py-2 text-[#718096] font-medium">Achieved</th>
                                <th className="text-center py-2 text-[#718096] font-medium">Target</th>
                                <th className="text-center py-2 text-[#718096] font-medium">Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(componentScores || []).map(cs => (
                                <tr key={cs.scope_code} className="border-b border-gray-50">
                                    <td className="py-2 text-[#2D3748] text-xs">{cs.scope_name}</td>
                                    <td className="py-2 text-center">
                                        <span className="inline-block px-2 py-0.5 rounded text-xs font-bold text-white"
                                            style={{ backgroundColor: maturityColors[cs.achieved_maturity_level] }}>
                                            {maturityLevels[cs.achieved_maturity_level]}
                                        </span>
                                    </td>
                                    <td className="py-2 text-center text-xs text-[#718096]">
                                        {cs.target_maturity_level ? maturityLevels[cs.target_maturity_level] : '—'}
                                    </td>
                                    <td className="py-2 text-center text-xs text-[#718096]">{parseFloat(cs.completion_pct).toFixed(0)}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Gap Statements (No responses) */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">
                    Gap Statements <span className="text-sm font-normal text-[#718096]">({(gapStatements || []).length} "No" answers at or below each domain's target level)</span>
                </h3>
                {(gapStatements || []).length === 0 ? (
                    <p className="text-sm text-[#718096]">No gaps against the current targets.</p>
                ) : (
                    <div className="space-y-2 max-h-96 overflow-y-auto">
                        {(gapStatements || []).map(gs => (
                            <div key={gs.id} className="flex items-start gap-3 p-3 bg-red-50 rounded-lg">
                                <span className="text-xs font-bold text-white w-5 h-5 rounded-full flex items-center justify-center shrink-0"
                                    style={{ backgroundColor: maturityColors[gs.statement?.maturity_level] }}>
                                    {gs.statement?.maturity_level}
                                </span>
                                <div>
                                    <p className="text-sm text-[#2D3748]">{gs.statement?.statement_text}</p>
                                    <p className="text-xs text-[#718096] mt-1">{domainNames[gs.statement?.domain_code]} · {gs.statement?.factor_name} · {gs.statement?.component_name} · {maturityLevels[gs.statement?.maturity_level]}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
