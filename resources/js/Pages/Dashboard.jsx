import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import {
    ShieldExclamationIcon,
    ServerStackIcon,
    BuildingOfficeIcon,
    DocumentTextIcon,
    BugAntIcon,
    ExclamationTriangleIcon,
    DocumentArrowDownIcon,
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon,
    ClipboardDocumentCheckIcon,
} from '@heroicons/react/24/outline';

const fmtDate = (d) => d ? new Date(d).toISOString().slice(0, 10) : '—';

/* ============================== Heat Map (5×5) ============================== */
function RiskHeatMap({ risks = [] }) {
    const matrix = Array.from({ length: 5 }, () => Array.from({ length: 5 }, () => []));
    risks.forEach((r) => {
        const l = Math.min(5, Math.max(1, r.inherent_likelihood || 3));
        const i = Math.min(5, Math.max(1, r.inherent_impact || 3));
        matrix[5 - i][l - 1].push(r);
    });
    const cellColor = (l, i) => {
        const score = l * i;
        if (score >= 20) return 'bg-[#B3261E]/90';
        if (score >= 12) return 'bg-[#E5A100]/85';
        if (score >= 6) return 'bg-[#FFCD3C]/70';
        return 'bg-[#2D7D46]/75';
    };
    return (
        <div>
            <div className="grid grid-cols-[auto_1fr] gap-1 text-[10px] text-[#718096]">
                <div></div>
                <div className="grid grid-cols-5 gap-1 text-center">
                    {['1 Rare', '2 Unlikely', '3 Possible', '4 Likely', '5 Certain'].map((l) => <div key={l}>{l}</div>)}
                </div>
                {[5, 4, 3, 2, 1].map((impact, rowIdx) => (
                    <div key={impact} className="contents">
                        <div className="flex items-center justify-end pr-1 text-[10px] text-[#718096]">{impact} {['Extreme','Major','Moderate','Minor','Insignificant'][5 - impact]}</div>
                        <div className="grid grid-cols-5 gap-1">
                            {[1, 2, 3, 4, 5].map((l) => {
                                const items = matrix[rowIdx][l - 1];
                                return (
                                    <div key={l} className={`rounded-md h-16 text-white p-1 text-[10px] ${cellColor(l, impact)} relative overflow-hidden`}>
                                        <span className="absolute top-1 right-1 font-bold text-white/80">{items.length}</span>
                                        <div className="flex flex-wrap gap-0.5 mt-4">
                                            {items.slice(0, 5).map((r) => (
                                                <Link key={r.id} href={route('risks.show', r.id)}
                                                    title={`${r.risk_id_code} — ${r.title}`}
                                                    className="w-2 h-2 rounded-full bg-white/80 hover:bg-white" />
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
            <p className="text-[10px] text-[#718096] text-center mt-2">Likelihood →</p>
        </div>
    );
}

function Donut({ data, colors, size = 140 }) {
    const entries = Object.entries(data || {});
    const total = entries.reduce((a, [, v]) => a + Number(v), 0) || 1;
    let cum = 0;
    const r = size / 2 - 16;
    const cx = size / 2, cy = size / 2;
    const arcs = entries.map(([k, v], idx) => {
        const start = (cum / total) * 360;
        cum += Number(v);
        const end = (cum / total) * 360;
        const large = end - start > 180 ? 1 : 0;
        const x1 = cx + r * Math.cos((start - 90) * Math.PI / 180);
        const y1 = cy + r * Math.sin((start - 90) * Math.PI / 180);
        const x2 = cx + r * Math.cos((end - 90) * Math.PI / 180);
        const y2 = cy + r * Math.sin((end - 90) * Math.PI / 180);
        return <path key={k}
            d={`M${cx},${cy} L${x1},${y1} A${r},${r} 0 ${large} 1 ${x2},${y2} Z`}
            fill={colors[idx % colors.length]} />;
    });
    return <svg width={size} height={size}>{arcs}<circle cx={cx} cy={cy} r={r * 0.55} fill="#fff" />
        <text x={cx} y={cy - 4} textAnchor="middle" fontSize="16" fontWeight="700" fill="#0A1F44">{total}</text>
        <text x={cx} y={cy + 12} textAnchor="middle" fontSize="9" fill="#718096">total</text>
    </svg>;
}

function CsatRadar({ csat = {} }) {
    const size = 240, cx = size/2, cy = size/2;
    const levels = 5;
    const domains = csat.domains || [];
    const current = csat.current || [];
    const target = csat.target || [];
    if (domains.length === 0) return null;
    const angle = (i) => (i / domains.length) * Math.PI * 2 - Math.PI / 2;
    const point = (val, i) => {
        const r = (val / levels) * (size/2 - 30);
        return [cx + r * Math.cos(angle(i)), cy + r * Math.sin(angle(i))];
    };
    const path = (series) => 'M' + series.map((v, i) => point(v, i).join(',')).join(' L') + ' Z';
    return (
        <svg width={size} height={size}>
            {[1,2,3,4,5].map((l) => (
                <polygon key={l} points={domains.map((_, i) => point(l, i).join(',')).join(' ')}
                    fill="none" stroke="#E2E8F0" strokeWidth="1" />
            ))}
            {domains.map((d, i) => (
                <g key={d}>
                    <line x1={cx} y1={cy} x2={point(5, i)[0]} y2={point(5, i)[1]} stroke="#E2E8F0" />
                    <text x={cx + (size/2 - 10) * Math.cos(angle(i))}
                        y={cy + (size/2 - 10) * Math.sin(angle(i))}
                        textAnchor="middle" dy="0.35em" fontSize="9" fill="#2D3748">{d}</text>
                </g>
            ))}
            <path d={path(target)} fill="#C9A86A" fillOpacity="0.25" stroke="#C9A86A" strokeWidth="1.5" />
            <path d={path(current)} fill="#0A1F44" fillOpacity="0.35" stroke="#0A1F44" strokeWidth="1.5" />
        </svg>
    );
}

export default function Dashboard({ orgName = 'Kano Heritage Bank Plc', counts = {}, heatmapRisks = [], top10Risks = [], kris = [], ctrlEff = {}, ccmByStatus = {}, incidents = [], vulnsBySeverity = {}, obligations = [], vendorScoreboard = [], attestationByDept = [], csat = {} }) {
    const exportBoardPack = () => router.post(route('dashboard.board-pack-export'));
    const severityOrder = ['critical', 'high', 'medium', 'low'];
    const vulnFunnel = severityOrder.map((s) => ({ level: s, n: Number(vulnsBySeverity[s] || 0) }));
    const maxVulns = Math.max(...vulnFunnel.map((v) => v.n), 1);

    return (
        <AuthenticatedLayout header="Executive Dashboard">
            <Head title="Executive Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'Executive Dashboard' }]}
                title={`Executive Dashboard · ${orgName}`}
                subtitle="Atheris ITSRM&G — live view of cyber, IT, ops-tech, third-party, data protection, fraud, physical and regulatory risk."
                actions={
                    <button onClick={exportBoardPack}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold hover:bg-[#D9BE85]">
                        <DocumentArrowDownIcon className="w-4 h-4" /> Export Board Pack (PPTX + PDF)
                    </button>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
                <KpiCard label="Risks" value={counts.risks || 0} tone="navy" icon={ExclamationTriangleIcon} />
                <KpiCard label="Controls" value={counts.controls || 0} tone="gold" icon={ClipboardDocumentCheckIcon} />
                <KpiCard label="Assets" value={counts.assets || 0} tone="white" icon={ServerStackIcon} />
                <KpiCard label="Vendors" value={counts.vendors || 0} tone="white" icon={BuildingOfficeIcon} />
                <KpiCard label="Policies" value={counts.policies || 0} tone="white" icon={DocumentTextIcon} />
                <KpiCard label="Vulnerabilities" value={counts.vulns || 0} tone="red" icon={BugAntIcon} />
                <KpiCard label="Incidents (90d)" value={incidents.length} tone="white" icon={ShieldExclamationIcon} />
                <KpiCard label="Compliance Assmts" value={counts.compliance_assessments || 0} tone="white" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:col-span-2">
                    <div className="flex items-start justify-between mb-3">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">Enterprise Risk Heat Map</h3>
                            <p className="text-xs text-[#718096]">All {heatmapRisks.length} risks plotted on the 5×5 matrix (inherent). Click a dot for detail.</p>
                        </div>
                        <Link href={route('risks.index')} className="text-xs text-[#0A1F44] underline">View register →</Link>
                    </div>
                    <RiskHeatMap risks={heatmapRisks} />
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Top 10 Risks (Inherent)</h3>
                    <ul className="mt-3 divide-y divide-gray-100">
                        {top10Risks.map((r) => (
                            <li key={r.id} className="py-2">
                                <Link href={route('risks.show', r.id)} className="flex items-start justify-between gap-3 hover:bg-gray-50 -mx-2 px-2 py-1 rounded">
                                    <div className="min-w-0">
                                        <p className="text-xs font-mono text-[#0A1F44]">{r.risk_id_code}</p>
                                        <p className="text-xs font-medium text-[#2D3748] truncate">{r.title}</p>
                                        <p className="text-[10px] text-[#718096]">Owner: {r.owner?.name || '—'}</p>
                                    </div>
                                    <div className="text-right">
                                        <StatusBadge status={r.inherent_rating || 'medium'} label={`I:${r.inherent_score}`} />
                                        <p className="text-[10px] text-[#718096] mt-0.5">R:{r.residual_score}</p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:col-span-2">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">KRI Panel — Nigerian Bank Indicators</h3>
                        <Link href={route('kri.index')} className="text-xs text-[#0A1F44] underline">Full KRI dashboard →</Link>
                    </div>
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                        {kris.map((k) => {
                            const latest = k.readings?.[0];
                            const trend = k.readings?.slice(0, 6) || [];
                            const max = Math.max(...trend.map((r) => Number(r.value)), 1);
                            return (
                                <div key={k.id} className="border border-gray-100 rounded-lg p-2">
                                    <p className="text-[10px] text-[#718096] font-mono">{k.code}</p>
                                    <p className="text-xs font-semibold text-[#2D3748] truncate">{k.name}</p>
                                    <div className="flex items-end justify-between mt-1">
                                        <p className="text-lg font-bold text-[#0A1F44]">{latest ? Number(latest.value).toFixed(1) : '—'}</p>
                                        {latest && <StatusBadge status={latest.status} />}
                                    </div>
                                    <div className="flex items-end gap-0.5 h-6 mt-1">
                                        {trend.slice().reverse().map((r, idx) => (
                                            <div key={idx} className="flex-1 rounded-t"
                                                style={{
                                                    height: `${Math.min(100, (Number(r.value) / max) * 100)}%`,
                                                    background: r.status === 'red' ? '#B3261E' : r.status === 'amber' ? '#E5A100' : '#2D7D46',
                                                }} />
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Control Effectiveness</h3>
                    <div className="flex items-center gap-3 mt-3">
                        <Donut data={ctrlEff} colors={['#2D7D46', '#E5A100', '#B3261E', '#718096']} />
                        <ul className="text-xs space-y-1">
                            {Object.entries(ctrlEff).map(([k, v], idx) => (
                                <li key={k} className="flex items-center gap-1">
                                    <span className="w-3 h-3 rounded" style={{ background: ['#2D7D46', '#E5A100', '#B3261E', '#718096'][idx % 4] }} />
                                    <span className="text-[#2D3748]">{(k || '').replace('_', ' ')}:</span>
                                    <span className="font-semibold text-[#0A1F44]">{v}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="mt-4 pt-3 border-t border-gray-100">
                        <h4 className="text-xs font-semibold text-[#2D3748]">CCM — this week</h4>
                        <div className="flex items-end gap-2 h-16 mt-2">
                            {['pass', 'warn', 'fail', 'error'].map((s) => (
                                <div key={s} className="flex-1 flex flex-col items-center gap-1">
                                    <div className="flex-1 w-full rounded-t"
                                        style={{
                                            height: `${Math.min(100, (Number(ccmByStatus[s] || 0) / Math.max(...Object.values(ccmByStatus), 1)) * 100)}%`,
                                            background: s === 'pass' ? '#2D7D46' : s === 'warn' ? '#E5A100' : s === 'fail' ? '#B3261E' : '#718096',
                                        }} />
                                    <span className="text-[10px] text-[#718096] capitalize">{s} · {ccmByStatus[s] || 0}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Incident Timeline (90 days)</h3>
                        <Link href={route('incidents.index')} className="text-xs text-[#0A1F44] underline">All incidents →</Link>
                    </div>
                    <ul className="divide-y divide-gray-100 text-xs max-h-80 overflow-y-auto">
                        {incidents.map((i) => (
                            <li key={i.id} className="py-2">
                                <div className="flex items-center justify-between">
                                    <span className="font-mono text-[#0A1F44]">{i.incident_id_code}</span>
                                    <span className="text-[#718096]">{fmtDate(i.detected_at)}</span>
                                </div>
                                <p className="text-[#2D3748] truncate mt-0.5">{i.title}</p>
                                <div className="flex items-center gap-1 mt-1 flex-wrap">
                                    <StatusBadge status={i.severity} />
                                    <StatusBadge status={i.status} />
                                    {i.is_data_breach && <span className="px-1.5 py-0.5 rounded bg-[#B3261E] text-white text-[10px]">CBN 24h / NDPC 72h</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Open Vulnerability Funnel</h3>
                        <Link href={route('vuln-prioritiser.index')} className="text-xs text-[#0A1F44] underline">Prioritiser →</Link>
                    </div>
                    <ul className="space-y-2 mt-3">
                        {vulnFunnel.map((v) => (
                            <li key={v.level}>
                                <div className="flex justify-between text-xs">
                                    <span className="capitalize font-medium text-[#2D3748]">{v.level}</span>
                                    <span className="text-[#0A1F44] font-semibold">{v.n}</span>
                                </div>
                                <div className="h-3 bg-gray-100 rounded overflow-hidden mt-0.5">
                                    <div className="h-3 rounded"
                                        style={{
                                            width: `${(v.n / maxVulns) * 100}%`,
                                            background: v.level === 'critical' ? '#B3261E' : v.level === 'high' ? '#E5A100' : v.level === 'medium' ? '#1D4ED8' : '#718096',
                                        }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                    <p className="text-[10px] text-[#718096] mt-3">EPSS × KEV enrichment active · CBN/ngCERT SLA monitoring live</p>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Obligations — Next 90 Days</h3>
                        <Link href={route('obligations.index')} className="text-xs text-[#0A1F44] underline">Register →</Link>
                    </div>
                    <ul className="divide-y divide-gray-100 text-xs max-h-80 overflow-y-auto">
                        {obligations.map((o) => (
                            <li key={o.id} className="py-2">
                                <div className="flex items-center justify-between">
                                    <span className="px-2 py-0.5 rounded bg-[#0A1F44] text-white text-[10px]">{o.regulator}</span>
                                    <span className={`text-[10px] ${o.days_to_due < 15 ? 'text-[#B3261E] font-semibold' : 'text-[#718096]'}`}>
                                        {o.days_to_due < 0 ? `${Math.abs(o.days_to_due)}d overdue` : `${o.days_to_due}d`}
                                    </span>
                                </div>
                                <p className="text-[#2D3748] truncate mt-1">{o.title}</p>
                                <p className="text-[10px] text-[#718096]">Due {o.next_due} · {o.owner_role}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Top 10 Vendor Ratings</h3>
                        <Link href={route('security-ratings.index')} className="text-xs text-[#0A1F44] underline">TPRM →</Link>
                    </div>
                    <ul className="divide-y divide-gray-100 text-xs">
                        {vendorScoreboard.map((v) => (
                            <li key={v.id} className="py-2 flex items-center justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-[#2D3748] truncate">{v.name}</p>
                                    <StatusBadge status={v.risk_level} />
                                </div>
                                <div className="flex items-center gap-2 ml-2">
                                    <span className="text-lg font-bold text-[#0A1F44]">{v.grade}</span>
                                    {v.rating_value && <span className="text-xs text-[#718096]">{v.rating_value}</span>}
                                    {v.delta !== 0 && (
                                        v.delta > 0
                                            ? <ArrowTrendingUpIcon className="w-3 h-3 text-[#2D7D46]" />
                                            : <ArrowTrendingDownIcon className="w-3 h-3 text-[#B3261E]" />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Policy Attestation Coverage</h3>
                        <Link href={route('policy-attestations.index')} className="text-xs text-[#0A1F44] underline">Attestations →</Link>
                    </div>
                    <ul className="space-y-3 text-xs">
                        {attestationByDept.map((d) => (
                            <li key={d.department}>
                                <div className="flex justify-between">
                                    <span className="text-[#2D3748]">{d.department}</span>
                                    <span className={`font-semibold ${d.coverage >= 95 ? 'text-[#2D7D46]' : d.coverage >= 80 ? 'text-[#E5A100]' : 'text-[#B3261E]'}`}>{d.coverage}%</span>
                                </div>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-1">
                                    <div className="h-2 rounded"
                                        style={{
                                            width: `${d.coverage}%`,
                                            background: d.coverage >= 95 ? '#2D7D46' : d.coverage >= 80 ? '#E5A100' : '#B3261E',
                                        }} />
                                </div>
                                <p className="text-[10px] text-[#718096]">{d.completed}/{d.total}</p>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">CBN-CSAT Maturity</h3>
                            <p className="text-xs text-[#718096]">Overall {csat.overall} / {csat.target_overall} · {csat.completion_percent}% complete</p>
                        </div>
                        <Link href={route('csat.index')} className="text-xs text-[#0A1F44] underline">CSAT →</Link>
                    </div>
                    <div className="flex justify-center">
                        <CsatRadar csat={csat} />
                    </div>
                    <div className="flex items-center justify-center gap-4 text-[10px] mt-2">
                        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#0A1F44]" /> Current</span>
                        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded bg-[#C9A86A]" /> Target</span>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
