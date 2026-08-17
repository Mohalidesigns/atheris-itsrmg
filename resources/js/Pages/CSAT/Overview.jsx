import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from 'recharts';

const riskColors = { least: '#2D7D46', minimal: '#319795', moderate: '#D4AF37', significant: '#DD6B20', most: '#C53030' };
const maturityLabels = { 0: 'Sub-Baseline', 1: 'Baseline', 2: 'Evolving', 3: 'Intermediate', 4: 'Advanced', 5: 'Innovative' };

function ProgressRing({ pct, label, size = 80 }) {
    const r = (size - 8) / 2;
    const c = 2 * Math.PI * r;
    const offset = c - (pct / 100) * c;
    return (
        <div className="flex flex-col items-center">
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="#E2E8F0" strokeWidth="6" />
                <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="#1A365D" strokeWidth="6"
                    strokeDasharray={c} strokeDashoffset={offset} strokeLinecap="round" className="transition-all duration-500" />
            </svg>
            <span className="text-lg font-bold text-[#2D3748] -mt-12">{pct}%</span>
            <span className="text-xs text-[#718096] mt-6">{label}</span>
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

export default function CsatOverview({ assessment, profile, irScores, maScores, stats }) {
    const irChartData = (irScores || []).map(s => ({
        name: `Cat ${s.category_code}`,
        score: parseFloat(s.average_score),
        level: s.risk_level,
    }));

    const maChartData = (maScores || []).map(s => ({
        name: s.scope_name?.split(' ').slice(0, 2).join(' ') || s.scope_code,
        level: s.achieved_maturity_level,
    }));

    return (
        <AuthenticatedLayout header={`${assessment.assessment_year} CBN-CSAT Assessment`}>
            <Head title={`CSAT ${assessment.assessment_year}`} />

            {/* Status Banner */}
            <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm mb-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <span className="text-sm font-medium text-[#718096]">Status:</span>
                        <span className="px-3 py-1 rounded-full text-xs font-bold bg-[#1A365D] text-white">
                            {assessment.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                        </span>
                        {assessment.composite_risk_level && (
                            <span className={`px-3 py-1 rounded-full text-xs font-bold text-white`}
                                style={{ backgroundColor: riskColors[assessment.composite_risk_level] }}>
                                Inherent Risk: {assessment.composite_risk_level.charAt(0).toUpperCase() + assessment.composite_risk_level.slice(1)}
                            </span>
                        )}
                        {assessment.overall_maturity_level && (
                            <span className="px-3 py-1 rounded-full text-xs font-bold bg-[#D4AF37] text-white">
                                Maturity: {assessment.overall_maturity_level.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                            </span>
                        )}
                    </div>
                    {assessment.submission_deadline && (
                        <span className="text-sm text-[#718096]">
                            Deadline: {new Date(assessment.submission_deadline).toLocaleDateString()}
                        </span>
                    )}
                </div>
            </div>

            {/* Progress Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-center justify-center">
                    <ProgressRing pct={stats.ir_pct} label="Inherent Risk" />
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-center justify-center">
                    <ProgressRing pct={stats.ma_pct} label="Maturity" />
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p className="text-3xl font-bold font-mono text-[#2D3748]">{stats.threats_count}</p>
                    <p className="text-xs text-[#718096] mt-1">Threats Registered</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p className="text-3xl font-bold font-mono text-[#2D3748]">{stats.vulnerabilities_count}</p>
                    <p className="text-xs text-[#718096] mt-1">Vulnerabilities</p>
                </div>
            </div>

            {/* Charts */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {irChartData.length > 0 && (
                    <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Inherent Risk by Category</h3>
                        <ResponsiveContainer width="100%" height={200}>
                            <BarChart data={irChartData}>
                                <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                <YAxis domain={[0, 5]} tick={{ fontSize: 11 }} />
                                <Tooltip />
                                <Bar dataKey="score" radius={[4, 4, 0, 0]}>
                                    {irChartData.map((entry, i) => (
                                        <Cell key={i} fill={riskColors[entry.level] || '#718096'} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}

                {maChartData.length > 0 && (
                    <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Maturity by Domain</h3>
                        <ResponsiveContainer width="100%" height={200}>
                            <BarChart data={maChartData}>
                                <XAxis dataKey="name" tick={{ fontSize: 10 }} />
                                <YAxis domain={[0, 5]} tick={{ fontSize: 11 }} tickFormatter={v => maturityLabels[v]?.substring(0, 4) || v} />
                                <Tooltip formatter={(v) => maturityLabels[v] || v} />
                                <Bar dataKey="level" fill="#1A365D" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </div>

            {/* Navigation Grid */}
            <h3 className="text-sm font-semibold text-[#718096] uppercase tracking-wider mb-3">Assessment Sections</h3>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <NavCard title="Institution Profile" description="CBN licence details, CISO info, stakeholder matrix" href={route('csat.institution-profile', assessment.id)} color="#1A365D" />
                <NavCard title="Inherent Risk" description="47 questions across 5 risk categories" href={route('csat.ir.dashboard', assessment.id)} color="#DD6B20" />
                <NavCard title="Maturity Assessment" description="494 declarative statements, 5 domains" href={route('csat.ma.dashboard', assessment.id)} color="#2D7D46" />
                <NavCard title="Threat Register" description="Structured threat catalogue & risk matrix" href={route('csat.threats', assessment.id)} color="#C53030" />
                <NavCard title="Vulnerabilities" description="Vulnerability tracking & remediation" href={route('csat.vulnerabilities', assessment.id)} color="#DD6B20" />
                <NavCard title="Approval Workflow" description="Multi-stage approval & digital signatures" href={route('csat.workflow', assessment.id)} color="#319795" />
                <NavCard title="Reports" description="Executive dashboard, gap analysis, CBN export" href={route('csat.reports', assessment.id)} color="#D4AF37" />
                <NavCard title="AI Insights" description="Gap analysis, readiness scoring, recommendations" href={route('csat.ai-insights', assessment.id)} color="#7C3AED" />
            </div>
        </AuthenticatedLayout>
    );
}
