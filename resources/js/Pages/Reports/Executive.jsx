import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip,
    BarChart, Bar, XAxis, YAxis,
} from 'recharts';
import RiskHeatMap from '@/Components/Risk/RiskHeatMap';
import {
    ChartBarIcon,
    ShieldCheckIcon,
    ExclamationTriangleIcon,
    BoltIcon,
    Cog6ToothIcon,
    DocumentMagnifyingGlassIcon,
} from '@heroicons/react/24/outline';

const RATING_COLORS = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
    very_low: '#319795',
};

const EFFECTIVENESS_COLORS = {
    effective: '#2D7D46',
    partially_effective: '#D4AF37',
    ineffective: '#C53030',
    not_assessed: '#E2E8F0',
};

function KpiCard({ title, value, subtitle, icon: Icon, color = 'navy' }) {
    const colorMap = {
        navy: { bg: 'bg-[#1A365D]/5', border: 'border-[#1A365D]/10', icon: 'text-[#1A365D]', accent: '#1A365D' },
        green: { bg: 'bg-[#2D7D46]/5', border: 'border-[#2D7D46]/10', icon: 'text-[#2D7D46]', accent: '#2D7D46' },
        red: { bg: 'bg-[#C53030]/5', border: 'border-[#C53030]/10', icon: 'text-[#C53030]', accent: '#C53030' },
        orange: { bg: 'bg-[#DD6B20]/5', border: 'border-[#DD6B20]/10', icon: 'text-[#DD6B20]', accent: '#DD6B20' },
        teal: { bg: 'bg-[#319795]/5', border: 'border-[#319795]/10', icon: 'text-[#319795]', accent: '#319795' },
        gold: { bg: 'bg-[#D4AF37]/5', border: 'border-[#D4AF37]/10', icon: 'text-[#D4AF37]', accent: '#D4AF37' },
    };
    const c = colorMap[color] || colorMap.navy;

    return (
        <div className={`${c.bg} rounded-xl border ${c.border} p-5 shadow-sm`}>
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-xs text-[#718096] uppercase tracking-wide font-medium">{title}</p>
                    <p className="text-3xl font-bold font-mono-data text-[#2D3748] mt-1">{value}</p>
                    {subtitle && <p className="text-xs text-[#718096] mt-1">{subtitle}</p>}
                </div>
                {Icon && (
                    <div className={`p-2.5 rounded-lg ${c.bg}`}>
                        <Icon className={`w-5 h-5 ${c.icon}`} />
                    </div>
                )}
            </div>
        </div>
    );
}

function ComplianceScoreDisplay({ score }) {
    if (score === null || score === undefined) {
        return <span className="text-sm text-[#718096]">N/A</span>;
    }
    const color = score >= 80 ? '#2D7D46' : score >= 60 ? '#D4AF37' : '#C53030';
    return (
        <span className="text-sm font-bold font-mono-data" style={{ color }}>
            {score}%
        </span>
    );
}

function FrameworkScoreBar({ name, score }) {
    const barColor = score === null ? '#E2E8F0'
        : score >= 80 ? '#2D7D46'
        : score >= 60 ? '#D4AF37'
        : score >= 40 ? '#DD6B20'
        : '#C53030';
    const displayScore = score ?? 0;

    return (
        <div className="flex items-center gap-3">
            <span className="text-xs text-[#2D3748] font-medium w-24 truncate" title={name}>
                {name}
            </span>
            <div className="flex-1 h-3 bg-gray-100 rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full transition-all duration-500"
                    style={{ width: `${displayScore}%`, backgroundColor: barColor }}
                />
            </div>
            <span className="text-xs font-mono-data font-bold w-10 text-right" style={{ color: barColor }}>
                {score !== null && score !== undefined ? `${score}%` : 'N/A'}
            </span>
        </div>
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

export default function Executive({
    riskStats,
    complianceStats,
    securityStats,
    controlStats,
    assetStats,
    policyStats,
    bcpStats,
    heatMapData,
    distribution,
    openGaps,
}) {
    const { auth } = usePage().props;
    const [heatMapType, setHeatMapType] = useState('inherent');

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
    });

    // Compliance score for KPI
    const complianceScore = complianceStats.average_score;
    const complianceColor = complianceScore === null ? 'navy'
        : complianceScore >= 80 ? 'green'
        : complianceScore >= 60 ? 'gold'
        : 'red';
    const complianceSubtitle = complianceScore !== null
        ? `${complianceStats.assessed_frameworks} of ${complianceStats.total_frameworks} frameworks assessed`
        : 'No assessments completed';

    // Control effectiveness percentage
    const effectivenessPct = controlStats.total > 0
        ? Math.round((controlStats.effective / controlStats.total) * 100)
        : 0;

    // Risk distribution donut data
    const riskPieData = Object.entries(distribution)
        .map(([key, val]) => ({
            name: val.label,
            value: val.count,
            color: val.color,
        }))
        .filter(d => d.value > 0);

    // Control effectiveness donut data
    const controlPieData = [
        { name: 'Effective', value: controlStats.effective, color: EFFECTIVENESS_COLORS.effective },
        { name: 'Partial', value: controlStats.partially_effective, color: EFFECTIVENESS_COLORS.partially_effective },
        { name: 'Ineffective', value: controlStats.ineffective, color: EFFECTIVENESS_COLORS.ineffective },
        { name: 'Not Assessed', value: controlStats.not_assessed, color: EFFECTIVENESS_COLORS.not_assessed },
    ].filter(d => d.value > 0);

    // Framework posture data
    const frameworks = complianceStats.frameworks || [];

    return (
        <AuthenticatedLayout header="Executive Dashboard">
            <Head title="Executive Dashboard" />

            {/* Welcome Banner */}
            <div className="bg-gradient-to-r from-[#1A365D] to-[#2D4A7A] rounded-xl p-6 mb-6 shadow-md">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-bold text-white">
                            GRC Executive Overview
                        </h1>
                        <p className="text-blue-200 text-sm mt-1">{today}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={route('reports.risks')}
                            className="px-4 py-2 text-xs font-semibold text-[#1A365D] bg-white rounded-lg hover:bg-blue-50 transition-colors"
                        >
                            Risk Report
                        </Link>
                        <Link
                            href={route('reports.compliance')}
                            className="px-4 py-2 text-xs font-semibold text-white border border-white/30 rounded-lg hover:bg-white/10 transition-colors"
                        >
                            Compliance Report
                        </Link>
                    </div>
                </div>
            </div>

            {/* KPI Row */}
            <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
                <KpiCard
                    title="Total Risks"
                    value={riskStats.total}
                    subtitle={`${riskStats.critical} critical, ${riskStats.high} high`}
                    icon={ExclamationTriangleIcon}
                    color="navy"
                />
                <KpiCard
                    title="Compliance Score"
                    value={complianceScore !== null ? `${complianceScore}%` : 'N/A'}
                    subtitle={complianceSubtitle}
                    icon={ShieldCheckIcon}
                    color={complianceColor}
                />
                <KpiCard
                    title="Open Vulnerabilities"
                    value={securityStats.open_vulnerabilities}
                    subtitle={`${securityStats.critical_vulnerabilities} critical`}
                    icon={BoltIcon}
                    color={securityStats.critical_vulnerabilities > 0 ? 'red' : 'teal'}
                />
                <KpiCard
                    title="Active Incidents"
                    value={securityStats.active_incidents}
                    subtitle={securityStats.active_breaches > 0 ? `${securityStats.active_breaches} breach${securityStats.active_breaches !== 1 ? 'es' : ''}` : 'No active breaches'}
                    icon={BoltIcon}
                    color={securityStats.active_incidents > 0 ? 'orange' : 'green'}
                />
                <KpiCard
                    title="Total Controls"
                    value={controlStats.total}
                    subtitle={`${effectivenessPct}% effective`}
                    icon={Cog6ToothIcon}
                    color="teal"
                />
                <KpiCard
                    title="Open Gaps"
                    value={openGaps}
                    subtitle="Requiring remediation"
                    icon={DocumentMagnifyingGlassIcon}
                    color={openGaps > 10 ? 'red' : openGaps > 0 ? 'gold' : 'green'}
                />
            </div>

            {/* Middle Row: Heat Map + Compliance Posture */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                {/* Risk Heat Map (2/3) */}
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
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
                    {riskStats.total > 0 ? (
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

                {/* Compliance Posture (1/3) */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Compliance Posture</h3>
                    {frameworks.length > 0 ? (
                        <div className="space-y-3">
                            {frameworks.map(fw => (
                                <FrameworkScoreBar
                                    key={fw.framework_id}
                                    name={fw.short_name || fw.name}
                                    score={fw.score}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-48 text-sm text-[#718096]">
                            No frameworks configured
                        </div>
                    )}
                </div>
            </div>

            {/* Bottom Row: 3 columns */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Risk Distribution Donut */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Risk Distribution</h3>
                    {riskPieData.length > 0 ? (
                        <div>
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie
                                        data={riskPieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={50}
                                        outerRadius={80}
                                        dataKey="value"
                                        paddingAngle={2}
                                    >
                                        {riskPieData.map((entry, i) => (
                                            <Cell key={i} fill={entry.color} />
                                        ))}
                                    </Pie>
                                    <Tooltip contentStyle={CUSTOM_TOOLTIP_STYLE} />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="space-y-1.5 mt-3">
                                {riskPieData.map(d => (
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
                            No risk data available
                        </div>
                    )}
                </div>

                {/* Control Effectiveness Donut */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Control Effectiveness</h3>
                    {controlPieData.length > 0 ? (
                        <div>
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie
                                        data={controlPieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={50}
                                        outerRadius={80}
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
                            <div className="space-y-1.5 mt-3">
                                {controlPieData.map(d => (
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
                            No control data available
                        </div>
                    )}
                </div>

                {/* Quick Stats */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Quick Stats</h3>
                    <div className="space-y-4">
                        {[
                            { label: 'Published Policies', value: policyStats.published, icon: DocumentMagnifyingGlassIcon, color: '#1A365D' },
                            { label: 'Total Assets', value: assetStats.total_assets, icon: Cog6ToothIcon, color: '#319795' },
                            { label: 'Total Vendors', value: assetStats.total_vendors, icon: ShieldCheckIcon, color: '#D4AF37' },
                            { label: 'BCP Plans', value: bcpStats.total_plans, icon: ChartBarIcon, color: '#2D7D46' },
                            { label: 'Active Breaches', value: securityStats.active_breaches, icon: BoltIcon, color: '#C53030' },
                            { label: 'Frameworks Assessed', value: complianceStats.assessed_frameworks, icon: ShieldCheckIcon, color: '#1A365D' },
                        ].map(item => (
                            <div key={item.label} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                <div className="flex items-center gap-3">
                                    <div className="p-1.5 rounded-lg" style={{ backgroundColor: item.color + '10' }}>
                                        <item.icon className="w-4 h-4" style={{ color: item.color }} />
                                    </div>
                                    <span className="text-sm text-[#718096]">{item.label}</span>
                                </div>
                                <span className="text-lg font-bold font-mono-data text-[#2D3748]">{item.value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
