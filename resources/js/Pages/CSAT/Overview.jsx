import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts';
import { CheckCircleIcon, LockClosedIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { IR_LEVEL_COLORS, MATURITY_COLORS, MATURITY_LABELS, STATUS_COLORS, STATUS_LABELS, formatDate, humanize, lockMessage } from '@/Utils/csat';

const IR_SHORT = { 1: 'Tech & Connections', 2: 'Delivery Channels', 3: 'Products & Services', 4: 'Org Characteristics', 5: 'External Threats' };

function Progress({ answered, total, label }) {
    const pct = total ? Math.round((answered / total) * 100) : 0;
    return (
        <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
            <p className="text-xs text-[#718096] uppercase font-medium">{label}</p>
            <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{pct}%</p>
            <div className="h-1.5 bg-gray-100 rounded mt-2"><div className="h-1.5 rounded bg-[#1A365D]" style={{ width: `${pct}%` }} /></div>
            <p className="text-xs text-[#718096] mt-1">{answered} / {total} answered</p>
        </div>
    );
}

function NavCard({ title, description, href, color = '#1A365D' }) {
    return (
        <Link href={href} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm hover:border-[#1A365D]/30 hover:shadow-md transition-all group">
            <div className="w-2 h-2 rounded-full mb-2" style={{ backgroundColor: color }} />
            <h4 className="text-sm font-semibold text-[#2D3748] group-hover:text-[#1A365D]">{title}</h4>
            <p className="text-xs text-[#718096] mt-1">{description}</p>
        </Link>
    );
}

export default function CsatOverview({ assessment, editable = true, checklist = [], irScores, maScores, stats }) {
    const irChartData = (irScores || []).map(s => ({
        name: IR_SHORT[s.category_code] || `Category ${s.category_code}`,
        score: parseFloat(s.average_score),
        level: s.risk_level,
    }));
    const maChartData = (maScores || []).map(s => ({
        name: s.scope_name || s.scope_code,
        level: s.achieved_maturity_level,
        target: s.target_maturity_level,
    }));
    const done = checklist.filter(i => i.done).length;

    return (
        <AuthenticatedLayout header={`${assessment.assessment_year} CBN-CSAT Assessment`}>
            <Head title={`CSAT ${assessment.assessment_year}`} />

            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm mb-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={`px-3 py-1 rounded-full text-xs font-bold ${STATUS_COLORS[assessment.status]}`}>{STATUS_LABELS[assessment.status] || humanize(assessment.status)}</span>
                        {assessment.composite_risk_level && (
                            <span className="px-3 py-1 rounded-full text-xs font-bold text-white" style={{ backgroundColor: IR_LEVEL_COLORS[assessment.composite_risk_level] }}>
                                Inherent Risk: {humanize(assessment.composite_risk_level)} ({Number(assessment.composite_risk_score).toFixed(2)})
                            </span>
                        )}
                        {assessment.overall_maturity_level && (
                            <span className="px-3 py-1 rounded-full text-xs font-bold bg-[#1A365D]/10 text-[#1A365D]">
                                Overall Maturity: {humanize(assessment.overall_maturity_level)}
                            </span>
                        )}
                    </div>
                    <span className="text-sm text-[#718096]">
                        {assessment.status === 'submitted'
                            ? `Submitted to CBN ${formatDate(assessment.submitted_at)}`
                            : `CBN deadline: ${formatDate(assessment.submission_deadline)}`}
                    </span>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <Progress label="Inherent risk questionnaire" answered={stats.ir_answered} total={stats.ir_total} />
                <Progress label="Maturity statements" answered={stats.ma_answered} total={stats.ma_total} />
                <Link href={route('csat.workflow', assessment.id)} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm hover:border-[#C9A86A]">
                    <p className="text-xs text-[#718096] uppercase font-medium">Submission checklist</p>
                    <p className={`text-2xl font-bold font-mono-data mt-1 ${done === checklist.length ? 'text-[#2D7D46]' : 'text-[#DD6B20]'}`}>{done} / {checklist.length}</p>
                    <p className="text-xs text-[#718096] mt-1">{done === checklist.length ? 'Ready to submit' : `${checklist.length - done} item(s) outstanding`}</p>
                </Link>
                <Link href={route('csat.threats', assessment.id)} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm hover:border-[#C9A86A]">
                    <p className="text-xs text-[#718096] uppercase font-medium">Threats registered</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{stats.threats_count}</p>
                </Link>
                <Link href={route('csat.vulnerabilities', assessment.id)} className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm hover:border-[#C9A86A]">
                    <p className="text-xs text-[#718096] uppercase font-medium">Vulnerabilities</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{stats.vulnerabilities_count}</p>
                </Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Inherent Risk by Category</h3>
                    {irChartData.length ? (
                        <ResponsiveContainer width="100%" height={220}>
                            <BarChart data={irChartData} layout="vertical" margin={{ left: 20 }}>
                                <XAxis type="number" domain={[0, 5]} tick={{ fontSize: 11 }} />
                                <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 11 }} />
                                <Tooltip formatter={(v, n, p) => [`${v} (${humanize(p.payload.level)})`, 'Average']} />
                                <Bar isAnimationActive={false} dataKey="score" radius={[0, 4, 4, 0]}>
                                    {irChartData.map((e, i) => <Cell key={i} fill={IR_LEVEL_COLORS[e.level] || '#718096'} />)}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    ) : <p className="text-xs text-[#718096] py-10 text-center">No inherent-risk answers yet.</p>}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Maturity by Domain (achieved vs target)</h3>
                    {maChartData.length ? (
                        <ul className="space-y-3">
                            {maChartData.map(d => (
                                <li key={d.name}>
                                    <div className="flex justify-between text-xs"><span className="text-[#2D3748]">{d.name}</span>
                                        <span style={{ color: MATURITY_COLORS[d.level] }} className="font-semibold">{MATURITY_LABELS[d.level]}{d.target ? ` → ${MATURITY_LABELS[d.target]}` : ''}</span></div>
                                    <div className="relative h-2 bg-gray-100 rounded mt-1">
                                        <div className="h-2 rounded" style={{ width: `${(d.level / 5) * 100}%`, backgroundColor: MATURITY_COLORS[d.level] }} />
                                        {d.target && <div className="absolute -top-0.5 h-3 w-0.5 bg-[#0A1F44]" style={{ left: `${(d.target / 5) * 100}%` }} title={`Target: ${MATURITY_LABELS[d.target]}`} />}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : <p className="text-xs text-[#718096] py-10 text-center">Maturity scores appear once statements are answered.</p>}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Items to Submit (Sheet 6)</h3>
                    <ul className="space-y-1.5">
                        {checklist.map(i => (
                            <li key={i.key} className="flex items-start gap-2 text-xs">
                                {i.done ? <CheckCircleIcon className="w-4 h-4 text-[#2D7D46] shrink-0" /> : <XCircleIcon className="w-4 h-4 text-[#C53030] shrink-0" />}
                                <Link href={route(i.route, assessment.id)} className={`hover:underline ${i.done ? 'text-[#718096]' : 'text-[#2D3748]'}`}>{i.label}</Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <h3 className="text-sm font-semibold text-[#718096] uppercase tracking-wider mb-3">Assessment Sections</h3>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <NavCard title="Institution Profile" description="CBN licence details, CISO info, stakeholder matrix" href={route('csat.institution-profile', assessment.id)} color="#1A365D" />
                <NavCard title="Inherent Risk" description={`${stats.ir_total} questions across ${stats.ir_categories} risk categories`} href={route('csat.ir.dashboard', assessment.id)} color="#DD6B20" />
                <NavCard title="Maturity Assessment" description={`${stats.ma_total} declarative statements, ${stats.ma_domains} domains`} href={route('csat.ma.dashboard', assessment.id)} color="#2D7D46" />
                <NavCard title="Threat Register" description="Threat catalogue, likelihood × impact matrix" href={route('csat.threats', assessment.id)} color="#C53030" />
                <NavCard title="Vulnerabilities" description="Vulnerability tracking & remediation" href={route('csat.vulnerabilities', assessment.id)} color="#DD6B20" />
                <NavCard title="Approval Workflow" description="Checklist, staged sign-off, submission to CBN" href={route('csat.workflow', assessment.id)} color="#319795" />
                <NavCard title="Reports" description="Executive report and CBN submission package" href={route('csat.reports', assessment.id)} color="#D4AF37" />
                <NavCard title="Insights" description="Gap analysis, readiness score, recommendations" href={route('csat.ai-insights', assessment.id)} color="#7C3AED" />
            </div>
        </AuthenticatedLayout>
    );
}
