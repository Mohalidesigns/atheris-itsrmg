import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    ExclamationTriangleIcon,
    PlusIcon,
    ArrowRightIcon,
} from '@heroicons/react/24/outline';
import { PieChart, Pie, Cell, ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip } from 'recharts';
import RiskHeatMap from '@/Components/Risk/RiskHeatMap';
import { RatingBadge, StatusBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';

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

export default function RiskDashboard({ stats, heatMapData, distribution, recentRisks, topRisks }) {
    const [heatMapType, setHeatMapType] = useState('inherent');

    const pieData = Object.entries(distribution).map(([key, val]) => ({
        name: val.label, value: val.count, color: val.color,
    })).filter(d => d.value > 0);

    const statusData = [
        { name: 'Identified', value: stats.total - stats.treating - stats.accepted },
        { name: 'Treating', value: stats.treating },
        { name: 'Accepted', value: stats.accepted },
    ].filter(d => d.value > 0);

    return (
        <AuthenticatedLayout header="IT Risk Management">
            <Head title="Risk Dashboard" />

            {/* Stats Row */}
            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
                <StatCard title="Total Risks" value={stats.total} color="navy" />
                <StatCard title="Critical" value={stats.critical} color="red" />
                <StatCard title="High" value={stats.high} color="orange" />
                <StatCard title="Medium" value={stats.medium} color="gold" />
                <StatCard title="Low" value={stats.low} color="green" />
                <StatCard title="Open" value={stats.open} color="teal" subtitle="Active risks" />
            </div>

            {/* Actions */}
            <div className="flex gap-2 mb-6">
                <Link href={route('risks.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    <PlusIcon className="w-4 h-4" /> Register Risk
                </Link>
                <Link href={route('risks.index')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    View All Risks <ArrowRightIcon className="w-4 h-4" />
                </Link>
            </div>

            {/* Main Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                {/* Heat Map */}
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-2">
                        <h3 className="text-base font-semibold text-[#2D3748]">Risk Heat Map</h3>
                        <div className="flex bg-gray-100 rounded-lg p-0.5">
                            {['inherent', 'residual'].map(type => (
                                <button key={type} onClick={() => setHeatMapType(type)}
                                    className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${
                                        heatMapType === type ? 'bg-white text-[#1A365D] shadow-sm' : 'text-[#718096]'
                                    }`}>
                                    {type.charAt(0).toUpperCase() + type.slice(1)}
                                </button>
                            ))}
                        </div>
                    </div>
                    {stats.total > 0 ? (
                        <RiskHeatMap data={heatMapData} type={heatMapType} />
                    ) : (
                        <div className="flex items-center justify-center h-64 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                            <div className="text-center">
                                <ExclamationTriangleIcon className="w-10 h-10 text-gray-300 mx-auto" />
                                <p className="text-sm text-[#718096] mt-2">No risks assessed yet</p>
                            </div>
                        </div>
                    )}
                </div>

                {/* Distribution Pie */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Risk Distribution</h3>
                    {pieData.length > 0 ? (
                        <div>
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie data={pieData} cx="50%" cy="50%" outerRadius={80} dataKey="value" label={({ name, value }) => `${name}: ${value}`}>
                                        {pieData.map((entry, i) => (
                                            <Cell key={i} fill={entry.color} />
                                        ))}
                                    </Pie>
                                    <Tooltip />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="space-y-1 mt-2">
                                {pieData.map(d => (
                                    <div key={d.name} className="flex items-center justify-between text-xs">
                                        <div className="flex items-center gap-2">
                                            <span className="w-3 h-3 rounded" style={{ backgroundColor: d.color }} />
                                            <span className="text-[#718096]">{d.name}</span>
                                        </div>
                                        <span className="font-mono-data font-semibold text-[#2D3748]">{d.value}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No data available
                        </div>
                    )}
                </div>
            </div>

            {/* Bottom Row */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Top Risks */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Top Risks by Score</h3>
                    {topRisks.length > 0 ? (
                        <div className="space-y-3">
                            {topRisks.map((risk, i) => (
                                <div key={risk.id} className="flex items-center gap-3">
                                    <span className="w-6 h-6 flex items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-[#718096]">
                                        {i + 1}
                                    </span>
                                    <div className="flex-1 min-w-0">
                                        <Link href={route('risks.show', risk.id)} className="text-sm font-medium text-[#2D3748] hover:text-[#1A365D] truncate block">
                                            {risk.title}
                                        </Link>
                                        <span className="text-xs text-[#718096]">{risk.risk_id_code}</span>
                                    </div>
                                    <ScoreDisplay score={risk.inherent_score} />
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No scored risks yet</p>
                    )}
                </div>

                {/* Recent Risks */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Recently Added</h3>
                    {recentRisks.length > 0 ? (
                        <div className="space-y-2">
                            {recentRisks.map(risk => (
                                <div key={risk.id} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                    <div className="flex items-center gap-3 min-w-0">
                                        <span className="font-mono-data text-xs text-[#1A365D] font-semibold shrink-0">{risk.risk_id_code}</span>
                                        <Link href={route('risks.show', risk.id)} className="text-sm text-[#2D3748] hover:text-[#1A365D] truncate">
                                            {risk.title}
                                        </Link>
                                    </div>
                                    <div className="flex items-center gap-2 shrink-0">
                                        <RatingBadge rating={risk.inherent_rating} />
                                        <StatusBadge status={risk.status} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No risks registered yet</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
