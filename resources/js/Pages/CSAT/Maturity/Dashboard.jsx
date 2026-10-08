import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { IR_LEVELS, MATURITY_COLORS, MATURITY_KEYS, MATURITY_LABELS, humanize } from '@/Utils/csat';

// FFIEC CAT expectation: maturity should rise with inherent risk (Least → Baseline … Most → Innovative).
const expectedMaturity = (riskIdx) => riskIdx + 1;

function MaturityBadge({ level }) {
    return (
        <span className="inline-block px-2 py-0.5 rounded text-xs font-bold text-white" style={{ backgroundColor: MATURITY_COLORS[level] }}>
            {MATURITY_LABELS[level]}
        </span>
    );
}

export default function MaturityDashboard({ assessment, domainScores = [], factorScores = [], irComposite }) {
    const riskIdx = irComposite?.level ? IR_LEVELS.indexOf(irComposite.level) : null;
    const overall = assessment.overall_maturity_level ? MATURITY_KEYS.indexOf(assessment.overall_maturity_level) : null;
    const factorsByDomain = (code) => factorScores.filter(f => f.scope_code.startsWith(`${code}-`));

    return (
        <AuthenticatedLayout header="Maturity Assessment Dashboard">
            <Head title="Maturity Dashboard" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                <div className="flex gap-2">
                    <Link href={route('csat.ma.assessment', assessment.id)} className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">Assessment Tool</Link>
                    <Link href={route('csat.ma.narratives', assessment.id)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">Narratives</Link>
                    <Link href={route('csat.ma.targets', assessment.id)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">Targets & Gaps</Link>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm text-center">
                    <p className="text-sm text-[#718096] mb-2">Overall Maturity <span className="text-xs">(lowest domain)</span></p>
                    {overall !== null && overall >= 0 ? (
                        <span className="inline-block px-6 py-2 rounded-lg text-lg font-bold text-white" style={{ backgroundColor: MATURITY_COLORS[overall] }}>{MATURITY_LABELS[overall]}</span>
                    ) : <span className="text-sm text-[#718096]">Not assessed</span>}
                    {riskIdx !== null && riskIdx >= 0 && overall !== null && (
                        <p className={`text-xs mt-3 ${overall >= expectedMaturity(riskIdx) ? 'text-[#2D7D46]' : 'text-[#C53030]'}`}>
                            {humanize(irComposite.level)} inherent risk expects at least {MATURITY_LABELS[expectedMaturity(riskIdx)]} —{' '}
                            {overall >= expectedMaturity(riskIdx) ? 'aligned.' : 'maturity is below what the risk profile requires.'}
                        </p>
                    )}
                </div>

                {/* Risk-Maturity Matrix (Sheet 9) */}
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-baseline justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Risk-Maturity Matrix (Sheet 9)</h3>
                        <p className="text-[11px] text-[#718096]">
                            Column = composite inherent risk{irComposite?.level ? ` (${humanize(irComposite.level)}, ${Number(irComposite.score).toFixed(2)})` : ''} · ● achieved · ○ target
                        </p>
                    </div>
                    <table className="w-full text-xs">
                        <thead>
                            <tr>
                                <th className="p-2 text-left text-[#718096]">Maturity \ Inherent risk</th>
                                {IR_LEVELS.map((r, i) => (
                                    <th key={r} className={`p-2 text-center ${i === riskIdx ? 'text-[#0A1F44] font-bold underline' : 'text-[#718096]'}`}>{humanize(r)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {[5, 4, 3, 2, 1, 0].map(ml => (
                                <tr key={ml} className="border-t border-gray-100">
                                    <td className="p-2 font-medium text-[#2D3748] whitespace-nowrap">{MATURITY_LABELS[ml]}</td>
                                    {IR_LEVELS.map((r, ri) => {
                                        const aligned = ml >= expectedMaturity(ri);
                                        const here = ri === riskIdx ? domainScores.filter(d => d.achieved_maturity_level === ml) : [];
                                        const targets = ri === riskIdx ? domainScores.filter(d => d.target_maturity_level === ml) : [];
                                        return (
                                            <td key={r} className={`p-1.5 text-center border-l border-gray-100 h-9 ${aligned ? 'bg-green-50' : 'bg-red-50'} ${ri === riskIdx ? 'ring-1 ring-inset ring-[#0A1F44]/20' : ''}`}>
                                                {here.map(d => (
                                                    <span key={'a' + d.scope_code} title={`${d.scope_name}: ${MATURITY_LABELS[ml]}`} className="inline-block px-1.5 py-0.5 bg-[#1A365D] text-white rounded-full text-[10px] font-bold m-0.5">{d.scope_code}</span>
                                                ))}
                                                {targets.map(d => (
                                                    <span key={'t' + d.scope_code} title={`${d.scope_name} target: ${MATURITY_LABELS[ml]}`} className="inline-block px-1.5 py-0.5 border border-dashed border-[#1A365D] text-[#1A365D] rounded-full text-[10px] font-bold m-0.5">{d.scope_code}</span>
                                                ))}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="text-[10px] text-[#718096] mt-2">Green = maturity meets the FFIEC expectation for that inherent-risk level; red = below it.</p>
                </div>
            </div>

            {/* Domain → factor breakdown (BR-MA-05: factor = average of components, domain = lowest factor) */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
                {domainScores.map(d => (
                    <div key={d.scope_code} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p className="text-xs font-semibold text-[#2D3748]">{d.scope_code} · {d.scope_name}</p>
                        <div className="flex items-center gap-2 mt-2">
                            <MaturityBadge level={d.achieved_maturity_level} />
                            {d.target_maturity_level && <span className="text-[11px] text-[#718096]">target {MATURITY_LABELS[d.target_maturity_level]}</span>}
                        </div>
                        <p className="text-[11px] text-[#718096] mt-1">{Number(d.completion_pct).toFixed(0)}% answered</p>
                        <ul className="mt-3 space-y-1 border-t border-gray-100 pt-2">
                            {factorsByDomain(d.scope_code).map(f => (
                                <li key={f.scope_code} className="flex items-center justify-between gap-2 text-[11px]">
                                    <span className="text-[#718096] truncate" title={f.scope_name}>{f.scope_name}</span>
                                    <span className="font-medium shrink-0" style={{ color: MATURITY_COLORS[f.achieved_maturity_level] }}>{MATURITY_LABELS[f.achieved_maturity_level]}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            {domainScores.length === 0 && (
                <p className="text-sm text-[#718096] bg-white rounded-xl border border-gray-100 p-8 text-center">Maturity scores appear once declarative statements are answered.</p>
            )}
        </AuthenticatedLayout>
    );
}
