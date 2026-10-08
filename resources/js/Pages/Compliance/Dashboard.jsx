import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRightIcon, PlusIcon } from '@heroicons/react/24/outline';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';
import Chip from '@/Components/Compliance/Chip';
import { RESULT_STYLES, ASSESSMENT_STATUS, GAP_SEVERITY, GAP_STATUS, EFFECTIVENESS, scoreColor, formatDate } from '@/Utils/compliance';

function FrameworkCard({ fw }) {
    const score = fw.score;
    const color = scoreColor(score);
    const applicable = (fw.compliant + fw.partial + fw.non_compliant) || 0;
    const target = fw.assessment_id ? route('compliance-assessments.show', fw.assessment_id) : route('frameworks.show', fw.framework_id);

    return (
        <Link href={target} className="block bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-[#2D3748]">{fw.short_name}</p>
                    <p className="text-xs text-[#718096] mt-0.5 line-clamp-2">{fw.name}</p>
                    <span className={`inline-block mt-1 text-xs px-2 py-0.5 rounded-full ${fw.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}`}>
                        {fw.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}
                    </span>
                </div>
                <div className="w-14 h-14 rounded-full border-4 flex items-center justify-center shrink-0" style={{ borderColor: color }}>
                    <span className="font-mono-data font-bold text-sm" style={{ color }}>{score !== null ? `${Math.round(score)}%` : '--'}</span>
                </div>
            </div>

            <div className="mt-3">
                <div className="flex h-1.5 rounded-full overflow-hidden bg-gray-100">
                    {[['compliant', fw.compliant], ['partially_compliant', fw.partial], ['non_compliant', fw.non_compliant]].map(([s, val]) => (
                        applicable > 0 && val > 0 ? <div key={s} style={{ width: `${(val / applicable) * 100}%`, backgroundColor: RESULT_STYLES[s].color }} title={`${RESULT_STYLES[s].label}: ${val}`} /> : null
                    ))}
                </div>
                <div className="flex justify-between mt-1 text-[10px] text-[#718096]">
                    <span>{fw.compliant}/{applicable} compliant</span>
                    <span>{fw.last_assessed ? `Assessed ${formatDate(fw.last_assessed)}` : 'Not assessed'}</span>
                </div>
                <div className="flex items-center justify-between mt-2 text-[10px] text-[#718096]">
                    <span>Control coverage</span>
                    <span className="font-mono-data text-[#1A365D] font-semibold">{fw.coverage_percent}%</span>
                </div>
                <div className="h-1 bg-gray-100 rounded-full"><div className="h-1 rounded-full bg-[#1A365D]" style={{ width: `${fw.coverage_percent}%` }} /></div>
                {fw.in_progress_id && <p className="text-[10px] text-blue-700 mt-2">Assessment in progress</p>}
            </div>
        </Link>
    );
}

export default function ComplianceDashboard({ stats, controlStats, recentAssessments, openGaps, gapStats, evidenceStats }) {
    const pieData = ['effective', 'partially_effective', 'ineffective', 'not_assessed']
        .map((k) => ({ key: k, name: EFFECTIVENESS[k].label, value: controlStats[k], color: EFFECTIVENESS[k].color }))
        .filter((d) => d.value > 0);

    const kpi = (label, value, sub, href, tone = 'text-[#2D3748]') => (
        <Link href={href} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm hover:shadow">
            <p className="text-xs text-[#718096] uppercase font-medium">{label}</p>
            <p className={`text-2xl font-bold font-mono-data mt-1 ${tone}`}>{value}</p>
            <p className="text-xs text-[#718096]">{sub}</p>
        </Link>
    );

    return (
        <AuthenticatedLayout header="Compliance & Regulatory">
            <Head title="Compliance Dashboard" />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Overall compliance</p>
                    <p className="text-2xl font-bold font-mono-data mt-1" style={{ color: scoreColor(stats.average_score) }}>{stats.average_score !== null ? `${stats.average_score}%` : '--'}</p>
                    <p className="text-xs text-[#718096]">Average of {stats.assessed_frameworks} of {stats.total_frameworks} frameworks' latest completed assessment</p>
                </div>
                {kpi('Operational controls', controlStats.total, `${controlStats.effective} effective · ${controlStats.ineffective} ineffective`, route('controls.index'))}
                {kpi('Open gaps', gapStats.open, `${gapStats.critical_high} critical/high · ${gapStats.unassigned} unassigned`, route('gap-analysis.index'), gapStats.open ? 'text-[#C53030]' : 'text-[#2D7D46]')}
                {kpi('Overdue gaps', gapStats.overdue, 'Past their remediation target date', route('gap-analysis.index', { overdue: 1 }), gapStats.overdue ? 'text-[#C53030]' : 'text-[#2D7D46]')}
                {kpi('Evidence', `${evidenceStats.approved}/${evidenceStats.total}`, `${evidenceStats.pending} pending review · ${evidenceStats.expired} expired`, route('evidence.index'))}
            </div>

            <div className="flex flex-wrap gap-2 mb-6">
                <Link href={route('compliance-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    <PlusIcon className="w-4 h-4" /> Start Assessment
                </Link>
                {[['Control Library', 'controls.index'], ['Frameworks', 'frameworks.index'], ['Gap register', 'gap-analysis.index'], ['Evidence', 'evidence.index']].map(([label, r]) => (
                    <Link key={r} href={route(r)} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        {label} <ArrowRightIcon className="w-4 h-4" />
                    </Link>
                ))}
            </div>

            <div className="mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-3">Regulatory compliance posture</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {stats.frameworks.map((fw) => <FrameworkCard key={fw.framework_id} fw={fw} />)}
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-1">Control effectiveness</h3>
                    <p className="text-xs text-[#718096] mb-3">Active and under-review controls</p>
                    {pieData.length > 0 ? (
                        <div className="flex items-center gap-6">
                            <ResponsiveContainer width={150} height={150}>
                                <PieChart>
                                    <Pie data={pieData} cx="50%" cy="50%" innerRadius={42} outerRadius={66} dataKey="value" paddingAngle={2}>
                                        {pieData.map((d) => <Cell key={d.key} fill={d.color} />)}
                                    </Pie>
                                    <Tooltip />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="space-y-2">
                                {pieData.map((d) => (
                                    <Link key={d.key} href={route('controls.index', { effectiveness: d.key })} className="flex items-center gap-2 text-xs hover:underline">
                                        <span className="w-3 h-3 rounded" style={{ backgroundColor: d.color }} />
                                        <span className="text-[#718096]">{d.name}</span>
                                        <span className="font-mono-data font-semibold text-[#2D3748]">{d.value}</span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    ) : <p className="text-sm text-[#718096] py-8 text-center">No controls registered yet</p>}
                </div>

                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="text-base font-semibold text-[#2D3748]">Priority open gaps</h3>
                        <Link href={route('gap-analysis.index')} className="text-xs text-[#1A365D] hover:underline">View all {gapStats.open}</Link>
                    </div>
                    {openGaps.length > 0 ? (
                        <div className="divide-y divide-gray-50">
                            {openGaps.map((gap) => (
                                <Link key={gap.id} href={route('gap-analysis.index', { search: gap.gap_code })} className="flex items-center justify-between gap-2 py-2 hover:bg-gray-50 -mx-2 px-2 rounded">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-[#2D3748] truncate">{gap.title}</p>
                                        <p className="text-xs text-[#718096]">{gap.gap_code} · {GAP_STATUS[gap.status]?.label} · {gap.assignee?.name || 'Unassigned'}{gap.is_overdue ? ' · ' : ''}{gap.is_overdue && <span className="text-red-600 font-semibold">overdue</span>}</p>
                                    </div>
                                    <Chip className={GAP_SEVERITY[gap.severity]?.chip}>{GAP_SEVERITY[gap.severity]?.label || gap.severity}</Chip>
                                </Link>
                            ))}
                        </div>
                    ) : <p className="text-sm text-[#718096] py-8 text-center">No open gaps</p>}
                </div>

                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="text-base font-semibold text-[#2D3748]">Recent assessments</h3>
                        <Link href={route('compliance-assessments.index')} className="text-xs text-[#1A365D] hover:underline">View all</Link>
                    </div>
                    {recentAssessments.length > 0 ? (
                        <div className="divide-y divide-gray-50">
                            {recentAssessments.map((a) => {
                                const done = a.compliant_count + a.partial_count + a.non_compliant_count + a.not_applicable_count;
                                return (
                                    <Link key={a.id} href={route('compliance-assessments.show', a.id)} className="flex items-center justify-between gap-2 py-2 hover:bg-gray-50 -mx-2 px-2 rounded">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-[#2D3748] truncate">{a.title}</p>
                                            <p className="text-xs text-[#718096]">{ASSESSMENT_STATUS[a.status]?.label} · {done}/{a.total_requirements} assessed</p>
                                        </div>
                                        <span className="font-mono-data text-sm font-bold" style={{ color: scoreColor(a.overall_score) }}>{a.overall_score !== null ? `${Math.round(a.overall_score)}%` : '--'}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    ) : <p className="text-sm text-[#718096] py-8 text-center">No assessments yet</p>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
