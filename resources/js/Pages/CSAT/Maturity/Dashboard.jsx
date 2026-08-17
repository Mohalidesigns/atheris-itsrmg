import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';

const maturityColors = { 0: '#C53030', 1: '#DD6B20', 2: '#D4AF37', 3: '#319795', 4: '#2D7D46', 5: '#1A365D' };
const riskLevels = ['Least', 'Minimal', 'Moderate', 'Significant', 'Most'];

export default function MaturityDashboard({ assessment, domainScores, componentScores, irScores, domainNames, maturityLevels }) {
    const domainData = (domainScores || []).map(s => ({
        name: s.scope_name?.split(' ').slice(0, 3).join(' ') || s.scope_code,
        level: s.achieved_maturity_level,
        target: s.target_maturity_level,
        pct: parseFloat(s.completion_pct),
    }));

    // Heat map data: risk (x) vs maturity (y) per domain
    const heatMapDomains = (domainScores || []).map((ds, i) => {
        const irScore = (irScores || [])[i];
        const riskIdx = irScore ? Math.min(4, Math.max(0, Math.floor(parseFloat(irScore.average_score)) - 1)) : 0;
        return { name: ds.scope_name, maturity: ds.achieved_maturity_level, risk: riskIdx };
    });

    return (
        <AuthenticatedLayout header="Maturity Assessment Dashboard">
            <Head title="Maturity Dashboard" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                <div className="flex gap-2">
                    <Link href={route('csat.ma.assessment', assessment.id)}
                        className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        Assessment Tool
                    </Link>
                    <Link href={route('csat.ma.narratives', assessment.id)}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        Narratives
                    </Link>
                    <Link href={route('csat.ma.targets', assessment.id)}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        Targets & Gaps
                    </Link>
                </div>
            </div>

            {/* Overall Maturity */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6 text-center">
                <p className="text-sm text-[#718096] mb-2">Overall Maturity Level</p>
                <span className="inline-block px-6 py-2 rounded-lg text-lg font-bold text-white"
                    style={{ backgroundColor: maturityColors[assessment.overall_maturity_level ? ['sub_baseline','baseline','evolving','intermediate','advanced','innovative'].indexOf(assessment.overall_maturity_level) : 0] || '#718096' }}>
                    {assessment.overall_maturity_level?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Not Assessed'}
                </span>
            </div>

            {/* Domain Bar Chart */}
            {domainData.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Maturity by Domain</h3>
                    <ResponsiveContainer width="100%" height={250}>
                        <BarChart data={domainData} layout="vertical">
                            <XAxis type="number" domain={[0, 5]} tick={{ fontSize: 11 }}
                                tickFormatter={v => maturityLevels[v]?.substring(0, 5) || v} />
                            <YAxis dataKey="name" type="category" width={180} tick={{ fontSize: 11 }} />
                            <Tooltip formatter={(v) => maturityLevels[v] || v} />
                            <Bar dataKey="level" fill="#1A365D" radius={[0, 4, 4, 0]} name="Achieved" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}

            {/* Risk-Maturity Heat Map (Sheet 9) */}
            <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Risk-Maturity Matrix (Sheet 9)</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead>
                            <tr>
                                <th className="p-2 text-[#718096]">Maturity \ Risk</th>
                                {riskLevels.map(r => <th key={r} className="p-2 text-center text-[#718096]">{r}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {[5,4,3,2,1,0].map(ml => (
                                <tr key={ml} className="border-t border-gray-100">
                                    <td className="p-2 font-medium text-[#2D3748]">{maturityLevels[ml]}</td>
                                    {[0,1,2,3,4].map(ri => {
                                        const domains = heatMapDomains.filter(d => d.maturity === ml && d.risk === ri);
                                        const isTarget = ml <= ri; // below diagonal = risk
                                        return (
                                            <td key={ri} className={`p-2 text-center border-l border-gray-100 ${isTarget ? 'bg-red-50' : 'bg-green-50'}`}>
                                                {domains.map(d => (
                                                    <span key={d.name} className="inline-block px-1.5 py-0.5 bg-[#1A365D] text-white rounded text-[10px] font-bold m-0.5">
                                                        {d.name?.charAt(0)}
                                                    </span>
                                                ))}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Domain Cards */}
            <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
                {domainData.map(d => (
                    <div key={d.name} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p className="text-xs text-[#718096] mb-1 truncate">{d.name}</p>
                        <span className="inline-block px-2 py-0.5 rounded text-xs font-bold text-white"
                            style={{ backgroundColor: maturityColors[d.level] }}>
                            {maturityLevels[d.level]}
                        </span>
                        <p className="text-xs text-[#718096] mt-2">{d.pct}% complete</p>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
