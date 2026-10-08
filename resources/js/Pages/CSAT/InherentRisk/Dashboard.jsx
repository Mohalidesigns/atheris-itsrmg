import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts';

const riskColors = { least: '#2D7D46', minimal: '#319795', moderate: '#D4AF37', significant: '#DD6B20', most: '#C53030' };
const categoryNames = {
    1: 'Technologies & Connections', 2: 'Delivery Channels',
    3: 'Products & Services', 4: 'Org Characteristics', 5: 'External Threats',
};

export default function InherentRiskDashboard({ assessment, scores }) {
    const chartData = (scores.category_scores || []).map(s => ({
        name: categoryNames[s.category_code] || `Cat ${s.category_code}`,
        score: parseFloat(s.average_score),
        level: s.risk_level,
        answered: s.answered_count,
        total: s.question_count,
        pct: parseFloat(s.completion_pct),
    }));

    return (
        <AuthenticatedLayout header="Inherent Risk Profile">
            <Head title="Inherent Risk Dashboard" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                <div className="flex gap-2">
                    <Link href={route('csat.ir.questions', assessment.id)}
                        className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        Answer Questions
                    </Link>
                    <Link href={route('csat.ir.narratives', assessment.id)}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        Narratives
                    </Link>
                </div>
            </div>

            {/* Composite Risk */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6 text-center">
                <p className="text-sm text-[#718096] mb-2">Composite Inherent Risk <span className="text-xs">(equal-weighted mean of the 5 category averages)</span></p>
                <div className="inline-flex items-center gap-3">
                    <span className="text-4xl font-bold font-mono text-[#2D3748]">{scores.score}</span>
                    <span className={`px-4 py-2 rounded-lg text-sm font-bold text-white`}
                        style={{ backgroundColor: riskColors[scores.level] || '#718096' }}>
                        {scores.level ? scores.level.charAt(0).toUpperCase() + scores.level.slice(1) : 'Not scored'}
                    </span>
                </div>
                {!scores.is_complete && (
                    <p className="text-xs text-[#DD6B20] mt-2">Provisional — {scores.answered} of {scores.total} questions answered. CBN submission is blocked until every question is answered (BR-IR-06).</p>
                )}
            </div>

            {/* Category Chart */}
            <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Risk Level by Category</h3>
                <ResponsiveContainer width="100%" height={280}>
                    <BarChart data={chartData} layout="vertical">
                        <XAxis type="number" domain={[0, 5]} tick={{ fontSize: 11 }} />
                        <YAxis dataKey="name" type="category" width={150} tick={{ fontSize: 11 }} />
                        <Tooltip formatter={(v, name) => [v.toFixed(3), 'Average Score']} />
                        <Bar isAnimationActive={false} dataKey="score" radius={[0, 4, 4, 0]}>
                            {chartData.map((entry, i) => (
                                <Cell key={i} fill={riskColors[entry.level] || '#718096'} />
                            ))}
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>

            {/* Category Cards */}
            <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
                {chartData.map(c => (
                    <div key={c.name} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <p className="text-xs text-[#718096] mb-1">{c.name}</p>
                        <p className="text-xl font-bold font-mono text-[#2D3748]">{c.score.toFixed(2)}</p>
                        <span className="inline-block px-2 py-0.5 rounded text-xs font-bold text-white mt-1"
                            style={{ backgroundColor: riskColors[c.level] }}>
                            {c.level?.charAt(0).toUpperCase() + c.level?.slice(1)}
                        </span>
                        <p className="text-xs text-[#718096] mt-2">{c.answered}/{c.total} answered ({c.pct}%)</p>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
