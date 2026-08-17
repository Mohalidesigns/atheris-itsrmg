import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip,
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Legend,
} from 'recharts';

const SEVERITY_COLORS = {
    Critical: '#C53030',
    High: '#DD6B20',
    Medium: '#D4AF37',
    Low: '#2D7D46',
};

const EFFECTIVENESS_COLORS = {
    effective: '#2D7D46',
    partially_effective: '#D4AF37',
    ineffective: '#C53030',
    not_assessed: '#E2E8F0',
};

const CUSTOM_TOOLTIP_STYLE = {
    backgroundColor: '#1A365D',
    border: 'none',
    borderRadius: '8px',
    color: '#fff',
    fontSize: '12px',
    padding: '8px 12px',
};

function ScoreColor(score) {
    if (score === null || score === undefined) return '#718096';
    if (score >= 80) return '#2D7D46';
    if (score >= 60) return '#D4AF37';
    if (score >= 40) return '#DD6B20';
    return '#C53030';
}

function FrameworkPostureCard({ framework }) {
    const score = framework.score;
    const color = ScoreColor(score);
    const total = framework.compliant + framework.partial + framework.non_compliant + framework.not_applicable;
    const assessed = total > 0;

    return (
        <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
            <div className="flex items-start justify-between mb-3">
                <div>
                    <h4 className="text-sm font-semibold text-[#2D3748]">{framework.name}</h4>
                    <p className="text-xs text-[#718096] mt-0.5">
                        {framework.short_name} {framework.jurisdiction && `- ${framework.jurisdiction}`}
                    </p>
                </div>
                <div className="text-right">
                    {score !== null && score !== undefined ? (
                        <span className="text-2xl font-bold font-mono-data" style={{ color }}>
                            {score}%
                        </span>
                    ) : (
                        <span className="text-sm text-[#718096] font-medium">Not Assessed</span>
                    )}
                </div>
            </div>

            {/* Progress bar */}
            {assessed && (
                <div className="mb-3">
                    <div className="flex h-2.5 rounded-full overflow-hidden bg-gray-100">
                        {framework.compliant > 0 && (
                            <div
                                className="bg-[#2D7D46]"
                                style={{ width: `${(framework.compliant / total) * 100}%` }}
                                title={`Compliant: ${framework.compliant}`}
                            />
                        )}
                        {framework.partial > 0 && (
                            <div
                                className="bg-[#D4AF37]"
                                style={{ width: `${(framework.partial / total) * 100}%` }}
                                title={`Partial: ${framework.partial}`}
                            />
                        )}
                        {framework.non_compliant > 0 && (
                            <div
                                className="bg-[#C53030]"
                                style={{ width: `${(framework.non_compliant / total) * 100}%` }}
                                title={`Non-Compliant: ${framework.non_compliant}`}
                            />
                        )}
                        {framework.not_applicable > 0 && (
                            <div
                                className="bg-gray-300"
                                style={{ width: `${(framework.not_applicable / total) * 100}%` }}
                                title={`N/A: ${framework.not_applicable}`}
                            />
                        )}
                    </div>
                </div>
            )}

            {/* Counts */}
            <div className="grid grid-cols-4 gap-2 text-center">
                {[
                    { label: 'Compliant', value: framework.compliant, color: '#2D7D46' },
                    { label: 'Partial', value: framework.partial, color: '#D4AF37' },
                    { label: 'Non-Compl.', value: framework.non_compliant, color: '#C53030' },
                    { label: 'N/A', value: framework.not_applicable, color: '#718096' },
                ].map(item => (
                    <div key={item.label}>
                        <p className="text-lg font-bold font-mono-data" style={{ color: item.color }}>
                            {item.value}
                        </p>
                        <p className="text-[10px] text-[#718096] uppercase">{item.label}</p>
                    </div>
                ))}
            </div>

            {/* Last assessed */}
            {framework.last_assessed && (
                <p className="text-[10px] text-[#718096] mt-3 pt-2 border-t border-gray-50">
                    Last assessed: {framework.last_assessed}
                </p>
            )}
        </div>
    );
}

export default function ComplianceReport({
    frameworkPosture,
    gapSummary,
    controlStats,
    overallScore,
}) {
    // Control effectiveness donut data
    const controlPieData = [
        { name: 'Effective', value: controlStats.effective, color: EFFECTIVENESS_COLORS.effective },
        { name: 'Partial', value: controlStats.partially_effective, color: EFFECTIVENESS_COLORS.partially_effective },
        { name: 'Ineffective', value: controlStats.ineffective, color: EFFECTIVENESS_COLORS.ineffective },
        { name: 'Not Assessed', value: controlStats.not_assessed, color: EFFECTIVENESS_COLORS.not_assessed },
    ].filter(d => d.value > 0);

    // Gap bar chart data
    const gapBarData = gapSummary.map(g => ({
        severity: g.severity,
        Open: g.open,
        Closed: g.closed,
    }));

    const totalOpenGaps = gapSummary.reduce((sum, g) => sum + g.open, 0);
    const overallColor = ScoreColor(overallScore);

    return (
        <AuthenticatedLayout header="Compliance Analytics">
            <Head title="Compliance Report" />

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-bold text-[#2D3748]">Compliance Analytics Report</h2>
                    <p className="text-sm text-[#718096]">Framework posture and gap analysis</p>
                </div>
                <Link
                    href={route('reports.executive')}
                    className="px-4 py-2 text-xs font-semibold text-[#1A365D] border border-[#1A365D]/20 rounded-lg hover:bg-[#1A365D]/5 transition-colors"
                >
                    Back to Executive Dashboard
                </Link>
            </div>

            {/* Overall Score Banner */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-xs text-[#718096] uppercase tracking-wide font-medium">Overall Compliance Score</p>
                        <div className="flex items-end gap-3 mt-1">
                            <span className="text-4xl font-bold font-mono-data" style={{ color: overallColor }}>
                                {overallScore !== null && overallScore !== undefined ? `${overallScore}%` : 'N/A'}
                            </span>
                            {overallScore !== null && (
                                <span className="text-sm text-[#718096] mb-1">
                                    across {frameworkPosture.filter(f => f.score !== null).length} assessed framework{frameworkPosture.filter(f => f.score !== null).length !== 1 ? 's' : ''}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-6 text-center">
                        <div>
                            <p className="text-2xl font-bold font-mono-data text-[#2D3748]">{frameworkPosture.length}</p>
                            <p className="text-[10px] text-[#718096] uppercase">Frameworks</p>
                        </div>
                        <div>
                            <p className="text-2xl font-bold font-mono-data text-[#2D3748]">{controlStats.total}</p>
                            <p className="text-[10px] text-[#718096] uppercase">Controls</p>
                        </div>
                        <div>
                            <p className="text-2xl font-bold font-mono-data" style={{ color: totalOpenGaps > 0 ? '#C53030' : '#2D7D46' }}>
                                {totalOpenGaps}
                            </p>
                            <p className="text-[10px] text-[#718096] uppercase">Open Gaps</p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Framework Posture Cards */}
            {frameworkPosture.length > 0 && (
                <div className="mb-6">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Framework Posture</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        {frameworkPosture.map(fw => (
                            <FrameworkPostureCard key={fw.framework_id} framework={fw} />
                        ))}
                    </div>
                </div>
            )}

            {/* Two Columns: Gap Summary + Control Effectiveness */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Gap Summary by Severity */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Gap Summary by Severity</h3>
                    {gapBarData.some(d => d.Open > 0 || d.Closed > 0) ? (
                        <ResponsiveContainer width="100%" height={280}>
                            <BarChart
                                data={gapBarData}
                                layout="vertical"
                                margin={{ top: 5, right: 20, left: 10, bottom: 5 }}
                            >
                                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                <XAxis type="number" tick={{ fontSize: 11, fill: '#718096' }} />
                                <YAxis
                                    type="category"
                                    dataKey="severity"
                                    tick={{ fontSize: 12, fill: '#2D3748', fontWeight: 500 }}
                                    width={60}
                                />
                                <Tooltip contentStyle={CUSTOM_TOOLTIP_STYLE} />
                                <Legend
                                    wrapperStyle={{ fontSize: '11px' }}
                                    iconType="circle"
                                    iconSize={8}
                                />
                                <Bar dataKey="Open" fill="#C53030" radius={[0, 4, 4, 0]} barSize={16} />
                                <Bar dataKey="Closed" fill="#2D7D46" radius={[0, 4, 4, 0]} barSize={16} />
                            </BarChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No gap data available
                        </div>
                    )}
                </div>

                {/* Control Effectiveness */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Control Effectiveness</h3>
                    {controlPieData.length > 0 ? (
                        <div>
                            <ResponsiveContainer width="100%" height={220}>
                                <PieChart>
                                    <Pie
                                        data={controlPieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={55}
                                        outerRadius={85}
                                        dataKey="value"
                                        paddingAngle={2}
                                    >
                                        {controlPieData.map((entry, i) => (
                                            <Cell key={i} fill={entry.color} />
                                        ))}
                                    </Pie>
                                    <Tooltip contentStyle={CUSTOM_TOOLTIP_STYLE} />
                                </PieChart>
                            </ResponsiveContainer>

                            {/* Legend */}
                            <div className="space-y-2 mt-4">
                                {controlPieData.map(d => {
                                    const pct = controlStats.total > 0
                                        ? Math.round((d.value / controlStats.total) * 100)
                                        : 0;
                                    return (
                                        <div key={d.name} className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <span className="w-3 h-3 rounded" style={{ backgroundColor: d.color }} />
                                                <span className="text-xs text-[#718096]">{d.name}</span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono-data font-semibold text-sm text-[#2D3748]">{d.value}</span>
                                                <span className="text-xs text-[#718096]">({pct}%)</span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Total */}
                            <div className="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                                <span className="text-sm font-medium text-[#2D3748]">Total Active Controls</span>
                                <span className="text-xl font-bold font-mono-data text-[#1A365D]">{controlStats.total}</span>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No control data available
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
