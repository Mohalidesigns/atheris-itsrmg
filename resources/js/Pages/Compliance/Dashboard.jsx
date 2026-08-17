import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    ClipboardDocumentCheckIcon,
    ShieldCheckIcon,
    ExclamationCircleIcon,
    ArrowRightIcon,
    PlusIcon,
} from '@heroicons/react/24/outline';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip, BarChart, Bar, XAxis, YAxis } from 'recharts';

const statusColors = {
    compliant: '#2D7D46',
    partially_compliant: '#D4AF37',
    non_compliant: '#C53030',
    not_applicable: '#718096',
    not_assessed: '#E2E8F0',
};

function FrameworkCard({ fw }) {
    const score = fw.score;
    const color = score === null ? '#718096' : score >= 80 ? '#2D7D46' : score >= 60 ? '#D4AF37' : score >= 40 ? '#DD6B20' : '#C53030';

    return (
        <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm font-semibold text-[#2D3748]">{fw.short_name}</p>
                    <p className="text-xs text-[#718096] mt-0.5">{fw.name}</p>
                    <span className={`inline-block mt-1 text-xs px-2 py-0.5 rounded-full ${
                        fw.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'
                    }`}>
                        {fw.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}
                    </span>
                </div>
                <div className="text-right">
                    <div className="w-14 h-14 rounded-full border-4 flex items-center justify-center" style={{ borderColor: color }}>
                        <span className="font-mono-data font-bold text-sm" style={{ color }}>
                            {score !== null ? `${Math.round(score)}%` : '--'}
                        </span>
                    </div>
                </div>
            </div>

            <div className="mt-3">
                <div className="flex gap-1">
                    {['compliant', 'partially_compliant', 'non_compliant'].map(s => {
                        const val = fw[s === 'partially_compliant' ? 'partial' : s === 'non_compliant' ? 'non_compliant' : 'compliant'];
                        const total = fw.total_requirements - (fw.not_applicable || 0);
                        const pct = total > 0 ? (val / total) * 100 : 0;
                        return <div key={s} className="h-1.5 rounded-full" style={{ width: `${pct}%`, backgroundColor: statusColors[s], minWidth: val > 0 ? 4 : 0 }} />;
                    })}
                </div>
                <div className="flex justify-between mt-1 text-[10px] text-[#718096]">
                    <span>{fw.total_requirements} requirements</span>
                    <span>{fw.last_assessed ? `Last: ${fw.last_assessed}` : 'Not assessed'}</span>
                </div>
            </div>
        </div>
    );
}

export default function ComplianceDashboard({ stats, controlStats, recentAssessments, openGaps }) {
    const pieData = [
        { name: 'Effective', value: controlStats.effective, color: '#2D7D46' },
        { name: 'Partial', value: controlStats.partially_effective, color: '#D4AF37' },
        { name: 'Ineffective', value: controlStats.ineffective, color: '#C53030' },
        { name: 'Not Assessed', value: controlStats.not_assessed, color: '#E2E8F0' },
    ].filter(d => d.value > 0);

    return (
        <AuthenticatedLayout header="Compliance & Regulatory">
            <Head title="Compliance Dashboard" />

            {/* Stats Row */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Overall Compliance</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">
                        {stats.average_score !== null ? `${stats.average_score}%` : '--'}
                    </p>
                    <p className="text-xs text-[#718096]">Across {stats.assessed_frameworks} assessed frameworks</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Frameworks</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{stats.total_frameworks}</p>
                    <p className="text-xs text-[#718096]">{stats.assessed_frameworks} assessed</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Active Controls</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{controlStats.total}</p>
                    <p className="text-xs text-[#718096]">{controlStats.effective} effective</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Open Gaps</p>
                    <p className="text-2xl font-bold font-mono-data text-[#C53030] mt-1">{openGaps.length}</p>
                    <p className="text-xs text-[#718096]">Requiring remediation</p>
                </div>
            </div>

            {/* Actions */}
            <div className="flex gap-2 mb-6">
                <Link href={route('compliance-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    <PlusIcon className="w-4 h-4" /> Start Assessment
                </Link>
                <Link href={route('controls.index')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    Control Library <ArrowRightIcon className="w-4 h-4" />
                </Link>
                <Link href={route('frameworks.index')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    Frameworks <ArrowRightIcon className="w-4 h-4" />
                </Link>
            </div>

            {/* Framework Cards */}
            <div className="mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-3">Regulatory Compliance Posture</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {stats.frameworks.map(fw => (
                        <FrameworkCard key={fw.framework_id} fw={fw} />
                    ))}
                </div>
            </div>

            {/* Bottom Row */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Control Effectiveness */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">Control Effectiveness</h3>
                    {pieData.length > 0 ? (
                        <div className="flex items-center gap-6">
                            <ResponsiveContainer width={160} height={160}>
                                <PieChart>
                                    <Pie data={pieData} cx="50%" cy="50%" innerRadius={45} outerRadius={70} dataKey="value" paddingAngle={2}>
                                        {pieData.map((entry, i) => <Cell key={i} fill={entry.color} />)}
                                    </Pie>
                                    <Tooltip />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="space-y-2">
                                {pieData.map(d => (
                                    <div key={d.name} className="flex items-center gap-2 text-xs">
                                        <span className="w-3 h-3 rounded" style={{ backgroundColor: d.color }} />
                                        <span className="text-[#718096]">{d.name}</span>
                                        <span className="font-mono-data font-semibold text-[#2D3748]">{d.value}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No controls registered yet</p>
                    )}
                </div>

                {/* Open Gaps */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-base font-semibold text-[#2D3748]">Open Gaps</h3>
                        <Link href={route('gap-analysis.index')} className="text-xs text-[#1A365D] hover:underline">View All</Link>
                    </div>
                    {openGaps.length > 0 ? (
                        <div className="space-y-2">
                            {openGaps.slice(0, 5).map(gap => (
                                <div key={gap.id} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-[#2D3748] truncate">{gap.title}</p>
                                        <p className="text-xs text-[#718096]">{gap.requirement?.requirement_code}</p>
                                    </div>
                                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                                        gap.severity === 'critical' ? 'bg-red-50 text-red-700' :
                                        gap.severity === 'high' ? 'bg-orange-50 text-orange-700' :
                                        'bg-amber-50 text-amber-700'
                                    }`}>{gap.severity}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No open gaps</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
