import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip,
    BarChart, Bar, XAxis, YAxis,
} from 'recharts';
import RiskHeatMap from '@/Components/Risk/RiskHeatMap';

const RATING_COLORS = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
    very_low: '#319795',
};

const TREATMENT_COLORS = {
    not_started: '#718096',
    in_progress: '#1A365D',
    completed: '#2D7D46',
    overdue: '#C53030',
};

function StatCard({ title, value, color, subtitle }) {
    const bgMap = {
        red: 'bg-[#C53030]', orange: 'bg-[#DD6B20]', gold: 'bg-[#D4AF37]',
        green: 'bg-[#2D7D46]', navy: 'bg-[#1A365D]', teal: 'bg-[#319795]',
    };
    return (
        <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
            <p className="text-xs text-[#718096] uppercase font-medium">{title}</p>
            <div className="flex items-end gap-2 mt-1">
                <span className="text-2xl font-bold font-mono-data text-[#2D3748]">{value}</span>
                {color && <span className={`w-2 h-2 rounded-full mb-1.5 ${bgMap[color]}`} />}
            </div>
            {subtitle && <p className="text-xs text-[#718096] mt-0.5">{subtitle}</p>}
        </div>
    );
}

function RatingBadge({ rating }) {
    if (!rating) return null;
    const colors = {
        critical: 'bg-[#C53030]/10 text-[#C53030]',
        high: 'bg-[#DD6B20]/10 text-[#DD6B20]',
        medium: 'bg-[#D4AF37]/10 text-[#D4AF37]',
        low: 'bg-[#2D7D46]/10 text-[#2D7D46]',
        very_low: 'bg-[#319795]/10 text-[#319795]',
    };
    return (
        <span className={`px-2 py-0.5 text-[10px] font-bold rounded-full uppercase ${colors[rating] || 'bg-gray-100 text-gray-500'}`}>
            {rating.replace('_', ' ')}
        </span>
    );
}

const CUSTOM_TOOLTIP_STYLE = {
    backgroundColor: '#1A365D',
    border: 'none',
    borderRadius: '8px',
    color: '#fff',
    fontSize: '12px',
    padding: '8px 12px',
};

export default function RiskReport({
    stats,
    distribution,
    categoryDistribution,
    statusDistribution,
    heatMapData,
    topRisks,
    treatmentStats,
}) {
    const [heatMapType, setHeatMapType] = useState('inherent');

    // Pie data from distribution
    const ratingPieData = Object.entries(distribution)
        .map(([key, val]) => ({
            name: val.label,
            value: val.count,
            color: val.color,
        }))
        .filter(d => d.value > 0);

    // Status bar chart data
    const statusBarData = statusDistribution.map(s => ({
        name: s.name,
        count: s.count,
    }));

    // Treatment data for display
    const treatmentItems = [
        { label: 'Not Started', value: treatmentStats.not_started, color: TREATMENT_COLORS.not_started },
        { label: 'In Progress', value: treatmentStats.in_progress, color: TREATMENT_COLORS.in_progress },
        { label: 'Completed', value: treatmentStats.completed, color: TREATMENT_COLORS.completed },
        { label: 'Overdue', value: treatmentStats.overdue, color: TREATMENT_COLORS.overdue },
    ];

    const totalTreatments = treatmentItems.reduce((sum, t) => sum + t.value, 0);

    return (
        <AuthenticatedLayout header="Risk Analytics">
            <Head title="Risk Report" />

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-bold text-[#2D3748]">Risk Analytics Report</h2>
                    <p className="text-sm text-[#718096]">Detailed risk posture analysis</p>
                </div>
                <Link
                    href={route('reports.executive')}
                    className="px-4 py-2 text-xs font-semibold text-[#1A365D] border border-[#1A365D]/20 rounded-lg hover:bg-[#1A365D]/5 transition-colors"
                >
                    Back to Executive Dashboard
                </Link>
            </div>

            {/* Stats Row */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <StatCard title="Total Risks" value={stats.total} color="navy" />
                <StatCard title="Critical" value={stats.critical} color="red" />
                <StatCard title="High" value={stats.high} color="orange" />
                <StatCard title="Medium" value={stats.medium} color="gold" />
                <StatCard title="Low" value={stats.low} color="green" />
                <StatCard title="Treating" value={stats.treating} color="teal" subtitle="Under treatment" />
            </div>

            {/* Risk Heat Map (full width) */}
            <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                <div className="flex items-center justify-between mb-3">
                    <h3 className="text-base font-semibold text-[#2D3748]">Risk Heat Map</h3>
                    <div className="flex bg-gray-100 rounded-lg p-0.5">
                        {['inherent', 'residual'].map(type => (
                            <button
                                key={type}
                                onClick={() => setHeatMapType(type)}
                                className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${
                                    heatMapType === type
                                        ? 'bg-white text-[#1A365D] shadow-sm'
                                        : 'text-[#718096]'
                                }`}
                            >
                                {type.charAt(0).toUpperCase() + type.slice(1)}
                            </button>
                        ))}
                    </div>
                </div>
                {stats.total > 0 ? (
                    <RiskHeatMap data={heatMapData} type={heatMapType} />
                ) : (
                    <div className="flex items-center justify-center h-64 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                        <p className="text-sm text-[#718096]">No risks assessed yet</p>
                    </div>
                )}
            </div>

            {/* Two Columns: Top Risks + Treatment Progress */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Top 10 Risks */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Top 10 Risks by Score</h3>
                    {topRisks.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="border-b border-gray-100">
                                        <th className="pb-2 text-[10px] font-semibold text-[#718096] uppercase">#</th>
                                        <th className="pb-2 text-[10px] font-semibold text-[#718096] uppercase">ID</th>
                                        <th className="pb-2 text-[10px] font-semibold text-[#718096] uppercase">Risk</th>
                                        <th className="pb-2 text-[10px] font-semibold text-[#718096] uppercase text-right">Score</th>
                                        <th className="pb-2 text-[10px] font-semibold text-[#718096] uppercase text-right">Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {topRisks.map((risk, i) => (
                                        <tr key={risk.id} className="border-b border-gray-50 last:border-0">
                                            <td className="py-2.5 text-xs text-[#718096] font-mono-data">{i + 1}</td>
                                            <td className="py-2.5">
                                                <span className="text-xs font-mono-data font-semibold text-[#1A365D]">
                                                    {risk.risk_id_code}
                                                </span>
                                            </td>
                                            <td className="py-2.5">
                                                <Link
                                                    href={route('risks.show', risk.id)}
                                                    className="text-sm text-[#2D3748] hover:text-[#1A365D] font-medium truncate block max-w-[200px]"
                                                >
                                                    {risk.title}
                                                </Link>
                                            </td>
                                            <td className="py-2.5 text-right">
                                                <span
                                                    className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-sm font-bold font-mono-data text-white"
                                                    style={{ backgroundColor: RATING_COLORS[risk.inherent_rating] || '#718096' }}
                                                >
                                                    {risk.inherent_score}
                                                </span>
                                            </td>
                                            <td className="py-2.5 text-right">
                                                <RatingBadge rating={risk.inherent_rating} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No scored risks yet
                        </div>
                    )}
                </div>

                {/* Treatment Progress */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Treatment Progress</h3>
                    {totalTreatments > 0 ? (
                        <div>
                            {/* Progress bar */}
                            <div className="flex h-4 rounded-full overflow-hidden mb-6">
                                {treatmentItems.filter(t => t.value > 0).map(t => (
                                    <div
                                        key={t.label}
                                        className="transition-all duration-500"
                                        style={{
                                            width: `${(t.value / totalTreatments) * 100}%`,
                                            backgroundColor: t.color,
                                        }}
                                        title={`${t.label}: ${t.value}`}
                                    />
                                ))}
                            </div>

                            {/* Treatment items */}
                            <div className="space-y-4">
                                {treatmentItems.map(t => (
                                    <div key={t.label} className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="w-3 h-3 rounded" style={{ backgroundColor: t.color }} />
                                            <span className="text-sm text-[#718096]">{t.label}</span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-lg font-bold font-mono-data text-[#2D3748]">{t.value}</span>
                                            <span className="text-xs text-[#718096] w-10 text-right">
                                                {totalTreatments > 0 ? `${Math.round((t.value / totalTreatments) * 100)}%` : '0%'}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Completion rate */}
                            <div className="mt-6 pt-4 border-t border-gray-100">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium text-[#2D3748]">Completion Rate</span>
                                    <span className="text-xl font-bold font-mono-data" style={{
                                        color: totalTreatments > 0 && (treatmentStats.completed / totalTreatments) >= 0.7
                                            ? '#2D7D46' : '#D4AF37'
                                    }}>
                                        {totalTreatments > 0
                                            ? `${Math.round((treatmentStats.completed / totalTreatments) * 100)}%`
                                            : '0%'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No treatments recorded yet
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
