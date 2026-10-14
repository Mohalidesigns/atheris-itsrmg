import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { IMPACT_LABELS, LIKELIHOOD_LABELS, humanize, ratingColor } from '@/Utils/risk';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
// Same orientation and rating scale as the IT Risk dashboard: rows = likelihood, columns = impact.
function RiskHeatMap({ risks = [] }) {
    const matrix = Array.from({ length: 5 }, () => Array.from({ length: 5 }, () => []));
    risks.forEach((r) => {
        if (!r.inherent_likelihood || !r.inherent_impact) return;
        matrix[5 - r.inherent_likelihood][r.inherent_impact - 1].push(r);
    });
    return (
        <div>
            <div className="grid grid-cols-[auto_1fr] gap-1 text-[10px] text-[#718096]">
                {[5, 4, 3, 2, 1].map((likelihood, rowIdx) => (
                    <div key={likelihood} className="contents">
                        <div className="flex items-center justify-end pr-1 text-[10px] text-[#718096]">{likelihood} {LIKELIHOOD_LABELS[likelihood]}</div>
                        <div className="grid grid-cols-5 gap-1">
                            {[1, 2, 3, 4, 5].map((impact) => {
                                const items = matrix[rowIdx][impact - 1];
                                return (
                                    <div key={impact}
                                        onClick={() => items.length && router.visit(route('risks.index', { likelihood, impact }))}
                                        className={`rounded-md h-16 text-white p-1 text-[10px] relative overflow-hidden ${items.length ? 'cursor-pointer hover:ring-2 hover:ring-[#0A1F44]' : ''}`}
                                        style={{ backgroundColor: ratingColor(likelihood * impact) + (items.length ? 'E6' : '55') }}>
                                        <span className="absolute top-1 right-1 font-bold text-white/90">{items.length || ''}</span>
                                        <div className="flex flex-wrap gap-0.5 mt-4">
                                            {items.slice(0, 8).map((r) => (
                                                <Link key={r.id} href={route('risks.show', r.id)} onClick={(e) => e.stopPropagation()}
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
                <div></div>
                <div className="grid grid-cols-5 gap-1 text-center">
                    {[1, 2, 3, 4, 5].map((i) => <div key={i}>{i} {IMPACT_LABELS[i]}</div>)}
                </div>
            </div>
            <p className="text-[10px] text-[#718096] text-center mt-2">Impact → · Likelihood ↑</p>
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

function CsatRadar({ csat }) {
    const size = 240, cx = size/2, cy = size/2;
    const levels = 5;
    const domains = csat?.domains || [];
    const current = csat?.current || [];
    const target = csat?.target || [];
    if (domains.length === 0) return <p className="text-xs text-[#718096] py-16 text-center">No maturity scores yet.</p>;
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

export default function Dashboard({ orgName = 'Kano Heritage Bank Plc', counts = {}, heatmapRisks = [], top10Risks = [], kris = [], ctrlEff = {}, ccmByStatus = {}, incidents = [], vulnsBySeverity = {}, obligations = [], vendorScoreboard = [], attestationByDept = [], csat = null, regulatory = null, secops = null, issues = null, changes = null, assets = null, vendors = null, policyPulse = null, continuity = null, monitoring = null }) {
    const { auth } = usePage().props;
    const exportBoardPack = () => router.post(route('dashboard.board-pack-export'));
    const canBoardPack = (usePage().props.auth?.user?.permissions || []).includes('create reports');
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
                actions={canBoardPack &&
                    <button onClick={exportBoardPack}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold hover:bg-[#D9BE85]">
                        <DocumentArrowDownIcon className="w-4 h-4" /> Generate Board Pack
                    </button>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
                <Link href={route('risks.dashboard')}><KpiCard label="Open Risks" value={counts.risks || 0} sublabel={counts.risks_above_appetite ? `${counts.risks_above_appetite} above appetite` : null} tone="navy" icon={ExclamationTriangleIcon} /></Link>
                <Link href={route('controls.index')}><KpiCard label="Controls" value={counts.controls || 0} tone="gold" icon={ClipboardDocumentCheckIcon} /></Link>
                <KpiCard label="Assets" value={counts.assets || 0} tone="white" icon={ServerStackIcon} />
                <KpiCard label="Vendors" value={counts.vendors || 0} tone="white" icon={BuildingOfficeIcon} />
                <KpiCard label="Policies" value={counts.policies || 0} tone="white" icon={DocumentTextIcon} />
                <KpiCard label="Vulnerabilities" value={counts.vulns || 0} tone="red" icon={BugAntIcon} />
                <KpiCard label="Incidents (90d)" value={incidents.length} tone="white" icon={ShieldExclamationIcon} />
                <Link href={route('compliance.dashboard')}><KpiCard label="Compliance" value={counts.compliance_score != null ? `${Math.round(counts.compliance_score)}%` : '--'} sublabel={counts.open_gaps ? `${counts.open_gaps} open gaps` : null} tone="white" /></Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:col-span-2">
                    <div className="flex items-start justify-between mb-3">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">Enterprise Risk Heat Map</h3>
                            <p className="text-xs text-[#718096]">{heatmapRisks.length} open risks plotted on the 5×5 matrix (inherent). Click a dot for the risk, or a cell to filter the register.</p>
                        </div>
                        <Link href={route('risks.index')} className="text-xs text-[#0A1F44] underline">View register →</Link>
                    </div>
                    <RiskHeatMap risks={heatmapRisks} />
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Top 10 Open Risks (Inherent)</h3>
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
                                        <StatusBadge status={r.inherent_rating === 'medium' ? 'moderate' : (r.inherent_rating || 'low')} label={`I:${r.inherent_score ?? '—'}`} />
                                        <p className="text-[10px] text-[#718096] mt-0.5">R:{r.residual_score ?? '—'}</p>
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
                        <h4 className="text-xs font-semibold text-[#2D3748]">CCM — latest results</h4>
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

            {regulatory && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Regulatory Intelligence</h3>
                        <Link href={route('reg-intel.index')} className="text-xs text-[#0A1F44] underline">Circular feed →</Link>
                    </div>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="grid grid-cols-2 gap-2 lg:col-span-2 sm:grid-cols-3">
                            {[
                                ['Unread circulars', regulatory.unread, route('reg-intel.index', { status: 'unread' }), regulatory.unread > 0],
                                ['Awaiting action', regulatory.awaiting_action, route('reg-intel.index', { status: 'assessed' }), false],
                                ['Overdue circular actions', regulatory.overdue_actions, route('reg-intel.index', { status: 'assessed' }), regulatory.overdue_actions > 0],
                                ['Documents to review', regulatory.documents_to_review, route('doc-intel.index', { status: 'awaiting_review' }), false],
                                ['Returns not yet filed', regulatory.returns_open, route('returns.index'), false],
                                ['Architecture returns open', regulatory.architecture_returns_open, route('regulatory-returns.index'), false],
                            ].map(([label, n, href, alert]) => (
                                <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                    <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                    <p className="text-[11px] text-[#718096]">{label}</p>
                                </Link>
                            ))}
                        </div>
                        <ul className="divide-y divide-gray-100 text-xs">
                            {(regulatory.returns || []).length === 0 && <li className="py-2 text-[#718096]">Every return is filed.</li>}
                            {(regulatory.returns || []).map((r) => (
                                <li key={r.id} className="flex items-center justify-between py-2">
                                    <Link href={route('returns.show', r.id)} className="min-w-0 truncate text-[#2D3748] hover:underline">
                                        <span className="mr-1 rounded bg-[#0A1F44] px-1.5 py-0.5 text-[10px] text-white">{r.regulator}</span>
                                        {r.name} · {r.period}
                                    </Link>
                                    <StatusBadge status={r.status === 'in_review' ? 'in_review' : r.status === 'approved' ? 'approved' : 'draft'} label={r.status.replace(/_/g, ' ')} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            {secops && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Security Operations</h3>
                        <Link href={route('security-ops.dashboard')} className="text-xs text-[#0A1F44] underline">SOC dashboard →</Link>
                    </div>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="grid grid-cols-2 gap-2 lg:col-span-2 sm:grid-cols-3">
                            {[
                                ['Known-exploited (KEV) open', secops.stats.vulns_kev, route('vuln-prioritiser.index', { kev: 1 }), secops.stats.vulns_kev > 0],
                                ['Findings past SLA', secops.stats.vulns_sla_breached, route('vulnerabilities.index', { overdue: 1 }), secops.stats.vulns_sla_breached > 0],
                                ['Active incidents', secops.stats.incidents_active, route('incidents.index', { status: 'active' }), secops.stats.incidents_critical > 0],
                                ['New high/critical alerts', secops.stats.alerts_new_high, route('security-alerts.index', { status: 'new' }), secops.stats.alerts_new_high > 0],
                                ['NDPA notices pending', secops.stats.ndpa_pending, route('data-breaches.index', { ndpa_pending: 1 }), secops.stats.ndpa_pending > 0],
                                ['Notifications overdue', secops.stats.notifications_overdue, route('notifications.index', { status: 'overdue' }), secops.stats.notifications_overdue > 0],
                            ].map(([label, n, href, alert]) => (
                                <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                    <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                    <p className="text-[11px] text-[#718096]">{label}</p>
                                </Link>
                            ))}
                        </div>
                        <div>
                            <h4 className="text-xs font-semibold text-[#2D3748]">Regulatory clocks</h4>
                            <ul className="mt-1 divide-y divide-gray-100 text-xs">
                                {secops.clocks.length === 0 && <li className="py-2 text-[#718096]">No regulatory deadlines running.</li>}
                                {secops.clocks.map((c) => (
                                    <li key={`${c.kind}-${c.id}`} className="py-2">
                                        <Link href={c.href} className="block hover:underline">
                                            <span className="font-medium text-[#2D3748]">{c.label}</span>
                                            <span className={`ml-1 ${c.overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                                {c.overdue ? 'overdue · ' : 'due '}{fmtDate(c.deadline_at)}
                                            </span>
                                            <p className="truncate text-[#718096]">{c.subject}</p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            )}

            {issues && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Issues &amp; Remediation</h3>
                        <Link href={route('issues.index')} className="text-xs text-[#0A1F44] underline">Issues Console →</Link>
                    </div>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {[
                                    ['Open issues', issues.stats.open, route('issues.index', { status: 'active' }), false],
                                    ['Past SLA', issues.stats.overdue, route('issues.index', { status: 'overdue' }), issues.stats.overdue > 0],
                                    ['Escalated', issues.stats.escalated, route('issues.index', { status: 'escalated' }), issues.stats.escalated > 0],
                                    ['Critical open', issues.stats.open_critical, route('issues.index', { status: 'active', severity: 'critical' }), issues.stats.open_critical > 0],
                                    ['Awaiting verification', issues.stats.awaiting_verification, route('issues.index', { status: 'remediated' }), false],
                                    ['Fixed within SLA (180 d)', issues.stats.sla_compliance_pct === null ? '—' : `${issues.stats.sla_compliance_pct}%`, route('issues.sla-policies'), issues.stats.sla_compliance_pct !== null && issues.stats.sla_compliance_pct < 80],
                                ].map(([label, n, href, alert]) => (
                                    <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                        <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                        <p className="text-[11px] text-[#718096]">{label}</p>
                                    </Link>
                                ))}
                            </div>
                            {Object.keys(issues.bySource || {}).length > 0 && (
                                <p className="mt-3 text-[11px] text-[#718096]">
                                    Open by source: {Object.entries(issues.bySource).map(([k, v]) => `${k} ${v}`).join(' · ')}
                                </p>
                            )}
                        </div>
                        <div>
                            <h4 className="text-xs font-semibold text-[#2D3748]">Most overdue</h4>
                            <ul className="mt-1 divide-y divide-gray-100 text-xs">
                                {issues.overdue.length === 0 && <li className="py-2 text-[#718096]">No issue is past its SLA.</li>}
                                {issues.overdue.map((i) => (
                                    <li key={i.id} className="py-2">
                                        <Link href={route('issues.show', i.id)} className="block hover:underline">
                                            <span className="font-mono text-[#2D3748]">{i.code}</span>
                                            <span className="ml-1 font-semibold text-[#B3261E]">{i.days_overdue} d over</span>
                                            {i.escalation_level > 0 && <span className="ml-1 text-[#8A6400]">· L{i.escalation_level}</span>}
                                            <p className="truncate text-[#718096]">{i.title}{i.owner ? ` · ${i.owner}` : ' · unassigned'}</p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            )}

            {assets && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Asset Management</h3>
                        <Link href={route('assets.index')} className="text-xs text-[#0A1F44] underline">Asset Register →</Link>
                    </div>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {[
                                    ['Critical assets', assets.stats.by_criticality?.critical, route('assets.index', { criticality: 'critical' }), false],
                                    ['Critical with open crit/high vulns', assets.stats.critical_with_open_vulns, route('vuln-prioritiser.index'), assets.stats.critical_with_open_vulns > 0],
                                    ['Past end of life', assets.stats.eol_passed, route('assets.index', { flag: 'eol_passed' }), assets.stats.eol_passed > 0],
                                    ['Unowned', assets.stats.unowned, route('assets.index', { flag: 'unowned' }), assets.stats.unowned > 0],
                                    ['Discovered, awaiting review', assets.stats.pending_review, route('asset-discovery.index'), assets.stats.pending_review > 0],
                                    ['Crit/high not mapped to a service', assets.stats.important_unmapped, route('assets.index', { flag: 'unmapped' }), assets.stats.important_unmapped > 0],
                                ].map(([label, n, href, alert]) => (
                                    <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                        <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                        <p className="text-[11px] text-[#718096]">{label}</p>
                                    </Link>
                                ))}
                            </div>
                            <p className="mt-3 text-[11px] text-[#718096]">
                                {assets.stats.total} live assets · {assets.stats.inherited_uplift} rated up by the services they support · {assets.stats.services_without_assets} of {assets.stats.services} business services have no supporting asset
                            </p>
                        </div>
                        <div>
                            <h4 className="text-xs font-semibold text-[#2D3748]">End-of-life watch</h4>
                            <ul className="mt-1 divide-y divide-gray-100 text-xs">
                                {assets.eol.length === 0 && <li className="py-2 text-[#718096]">Nothing reaches end of life in the next 90 days.</li>}
                                {assets.eol.map((a) => (
                                    <li key={a.id} className="py-2">
                                        <Link href={route('assets.show', a.id)} className="block hover:underline">
                                            <span className="font-mono text-[#2D3748]">{a.code}</span>
                                            <span className={`ml-1 font-semibold ${a.days < 0 ? 'text-[#B3261E]' : 'text-[#8A6400]'}`}>
                                                {a.days < 0 ? `${-a.days} d past EOL` : `EOL in ${a.days} d`}
                                            </span>
                                            <p className="truncate text-[#718096]">{a.name} · {a.criticality}</p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            )}

            {continuity && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Business Continuity</h3>
                        <Link href={route('bcp.plans')} className="text-xs text-[#0A1F44] underline">Plans →</Link>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                        {[
                            ['Plans in force', continuity.active, route('bcp.plans', { status: 'active' }), false],
                            ['Not tested in 12 months', continuity.test_overdue, route('bcp.plans', { flag: 'test_overdue' }), continuity.test_overdue > 0],
                            ['Critical services with no BIA', continuity.critical_without_bia, route('bcp.bia'), continuity.critical_without_bia > 0],
                            ['Critical services with no plan', continuity.critical_without_plan, route('bcp.plans'), continuity.critical_without_plan > 0],
                            ['BIA RTO not on the service', continuity.bia_not_applied, route('bcp.bia'), continuity.bia_not_applied > 0],
                            ['DR exercises within RTO', continuity.exercises_12m ? `${continuity.exercises_within_rto}/${continuity.exercises_12m}` : '—', route('dr.exercises'), continuity.exercises_12m > 0 && continuity.exercises_within_rto < continuity.exercises_12m],
                        ].map(([label, n, href, alert]) => (
                            <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                <p className="text-[11px] text-[#718096]">{label}</p>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            {monitoring && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Continuous Monitoring</h3>
                        <Link href={route('monitoring.dashboard')} className="text-xs text-[#0A1F44] underline">Monitoring →</Link>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                        {[
                            ['CCM tests passing', monitoring.tests ? `${monitoring.pass}/${monitoring.tests}` : '—', route('ccm.index'), false],
                            ['CCM tests failing', monitoring.fail, route('ccm.index', { status: 'fail' }), monitoring.fail > 0],
                            ['Control ratings that disagree with CCM', monitoring.controls_disagree, route('monitoring.controls', { filter: 'disagree' }), monitoring.controls_disagree > 0],
                            ['Open high/critical drift', monitoring.drift_critical, route('monitoring.drift', { status: 'unresolved' }), monitoring.drift_critical > 0],
                            ['Access reviews overdue', monitoring.reviews_overdue, route('monitoring.access-reviews', { status: 'overdue' }), monitoring.reviews_overdue > 0],
                            ['Revocations to confirm', monitoring.revocations_pending, route('monitoring.access-reviews'), monitoring.revocations_pending > 0],
                        ].map(([label, n, href, alert]) => (
                            <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                <p className="text-[11px] text-[#718096]">{label}</p>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            {changes && (
                <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex items-start justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Change Management</h3>
                        <Link href={route('changes.index')} className="text-xs text-[#0A1F44] underline">Change Register →</Link>
                    </div>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {[
                                    ['Awaiting approval', changes.stats.awaiting_approval, route('changes.index', { status: 'in_approval' }), false],
                                    ['Scheduled next 7 days', changes.stats.scheduled_7d, route('changes.index', { status: 'upcoming' }), false],
                                    ['Emergency last 30 d', changes.stats.emergency_30d, route('changes.index', { type: 'emergency' }), changes.stats.unauthorised_emergency > 0],
                                    ['Change success rate (90 d)', changes.stats.success_rate_pct === null || changes.stats.success_rate_pct === undefined ? '—' : `${changes.stats.success_rate_pct}%`, route('changes.index', { status: 'closed' }), changes.stats.success_rate_pct !== null && changes.stats.success_rate_pct !== undefined && changes.stats.success_rate_pct < 90],
                                    ['Failed / backed out (90 d)', changes.stats.failed_or_backed_out_90d, route('changes.index', { status: 'failed' }), changes.stats.failed_or_backed_out_90d > 0],
                                    ['Overdue approvals', changes.stats.overdue_approvals, route('changes.approvals'), changes.stats.overdue_approvals > 0],
                                ].map(([label, n, href, alert]) => (
                                    <Link key={label} href={href} className="rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                        <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                        <p className="text-[11px] text-[#718096]">{label}</p>
                                    </Link>
                                ))}
                            </div>
                            <p className="mt-3 text-[11px] text-[#718096]">
                                {changes.stats.open ?? 0} open · {changes.stats.implementing ?? 0} implementing · {changes.stats.cab_pending ?? 0} at CAB/ECAB
                                {changes.stats.emergency_ratio_pct !== null && changes.stats.emergency_ratio_pct !== undefined ? ` · emergency ratio ${changes.stats.emergency_ratio_pct}%` : ''}
                                {changes.stats.mean_lead_time_days !== null && changes.stats.mean_lead_time_days !== undefined ? ` · mean approval lead time ${changes.stats.mean_lead_time_days} d` : ''}
                                {changes.stats.unauthorised_emergency > 0 && <span className="ml-1 font-semibold text-[#B3261E]">· {changes.stats.unauthorised_emergency} unauthorised emergency</span>}
                            </p>
                        </div>
                        <div>
                            <Link href={route('changes.approvals')} className={`mb-2 block rounded-lg border px-3 py-2 ${changes.myApprovals > 0 ? 'border-[#C9A86A] bg-[#C9A86A]/10' : 'border-gray-100'} hover:bg-gray-50`}>
                                <p className="text-xl font-bold text-[#0A1F44]">{changes.myApprovals ?? 0}</p>
                                <p className="text-[11px] text-[#718096]">My approvals waiting</p>
                            </Link>
                            <h4 className="text-xs font-semibold text-[#2D3748]">Next scheduled</h4>
                            <ul className="mt-1 divide-y divide-gray-100 text-xs">
                                {(changes.upcoming || []).length === 0 && <li className="py-2 text-[#718096]">Nothing on the forward schedule.</li>}
                                {(changes.upcoming || []).map((c) => (
                                    <li key={c.id} className="py-2">
                                        <Link href={route('changes.show', c.id)} className="block hover:underline">
                                            <span className="font-mono text-[#2D3748]">{c.change_code}</span>
                                            <span className="ml-1"><StatusBadge status={c.risk_level} label={humanize(c.risk_level)} /></span>
                                            {c.change_type === 'emergency' && <span className="ml-1 font-semibold text-[#B3261E]">Emergency</span>}
                                            <p className="truncate text-[#718096]">{c.title}{c.scheduled_start_at ? ` · ${new Date(c.scheduled_start_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}` : ''}</p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <div className="flex items-start justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Third-Party Risk</h3>
                        <Link href={route('security-ratings.index')} className="text-xs text-[#0A1F44] underline">Ratings →</Link>
                    </div>
                    {vendors && (
                        <div className="mb-3 grid grid-cols-3 gap-2 text-center">
                            {[
                                ['Tier 1', vendors.by_tier?.[1], route('vendors.index', { tier: 1 }), false],
                                ['Reviews overdue', vendors.review_overdue, route('vendors.index', { flag: 'review_overdue' }), vendors.review_overdue > 0],
                                ['No DPA (NDPA)', vendors.missing_dpa, route('vendors.index', { flag: 'missing_dpa' }), vendors.missing_dpa > 0],
                                ['Contracts ≤ 90 d', vendors.contracts_expiring, route('vendors.index', { flag: 'contract_expiring' }), false],
                                ['Rating D/F', vendors.poor_rating, route('security-ratings.index'), vendors.poor_rating > 0],
                                ['Breach alerts', vendors.open_breach_events, route('security-ratings.index'), vendors.open_breach_events > 0],
                            ].map(([label, n, href, alert]) => (
                                <Link key={label} href={href} className="rounded-lg border border-gray-100 px-1 py-1.5 hover:bg-gray-50">
                                    <p className={`text-base font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                    <p className="text-[10px] text-[#718096]">{label}</p>
                                </Link>
                            ))}
                        </div>
                    )}
                    <h4 className="text-xs font-semibold text-[#2D3748]">Lowest-rated Tier 1–2 vendors</h4>
                    <ul className="divide-y divide-gray-100 text-xs">
                        {vendorScoreboard.map((v) => (
                            <li key={v.id} className="py-2 flex items-center justify-between">
                                <div className="min-w-0">
                                    <Link href={route('vendors.show', v.id)} className="block font-medium text-[#2D3748] truncate hover:underline">{v.name}</Link>
                                    <span className="text-[10px] text-[#718096]">Tier {v.tier} · </span><StatusBadge status={v.risk_level} />
                                </div>
                                <div className="flex items-center gap-2 ml-2">
                                    <span className="text-lg font-bold text-[#0A1F44]">{v.grade}</span>
                                    {v.rating_value && <span className="text-xs text-[#718096]">{v.rating_value}</span>}
                                    {v.delta !== 0 && v.delta !== null && (
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
                        <h3 className="text-sm font-semibold text-[#2D3748]">Policy Compliance</h3>
                        <Link href={route('policy-attestations.index')} className="text-xs text-[#0A1F44] underline">Attestations →</Link>
                    </div>
                    {policyPulse && (
                        <div className="mb-3 grid grid-cols-3 gap-2 text-center">
                            {[
                                ['Attested', policyPulse.attestation_rate == null ? '—' : `${policyPulse.attestation_rate}%`, route('policy-attestations.index'), false],
                                ['Overdue', policyPulse.attestations_overdue, route('policy-attestations.index', { status: 'overdue' }), policyPulse.attestations_overdue > 0],
                                ['Reviews overdue', policyPulse.review_overdue, route('policies.index', { flag: 'review_overdue' }), policyPulse.review_overdue > 0],
                                ['Awaiting approval', policyPulse.awaiting_approval, route('policies.index', { status: 'in_review' }), false],
                                ['Live exceptions', policyPulse.exceptions_live, route('policy-exceptions.index', { status: 'approved' }), false],
                                ['No control mapped', policyPulse.unmapped_active, route('control-standards.index'), policyPulse.unmapped_active > 0],
                            ].map(([label, n, href, alert]) => (
                                <Link key={label} href={href} className="rounded-lg border border-gray-100 px-1 py-1.5 hover:bg-gray-50">
                                    <p className={`text-base font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{n ?? 0}</p>
                                    <p className="text-[10px] text-[#718096]">{label}</p>
                                </Link>
                            ))}
                        </div>
                    )}
                    <h4 className="mb-2 text-xs font-semibold text-[#2D3748]">Attestation coverage by department</h4>
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
                            <p className="text-xs text-[#718096]">
                                {csat ? `${csat.year} · ${humanize(csat.status)} · overall ${humanize(csat.overall)} · inherent risk ${humanize(csat.inherent_risk)} · ${csat.completion_percent}% answered · readiness ${csat.readiness ?? '—'}/100` : 'No CSAT cycle started'}
                            </p>
                        </div>
                        {(auth?.user?.permissions || []).includes('view csat') || (auth?.user?.roles || []).includes('Super Admin') ? (
                            <Link href={csat ? route('csat.overview', csat.assessment_id) : route('csat.index')} className="text-xs text-[#0A1F44] underline">CSAT →</Link>
                        ) : null}
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
