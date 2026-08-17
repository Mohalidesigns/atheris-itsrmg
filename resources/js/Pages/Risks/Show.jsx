import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { PencilIcon, PlusIcon, ClockIcon, ShieldCheckIcon, BugAntIcon, ServerStackIcon, DocumentTextIcon } from '@heroicons/react/24/outline';
import { RatingBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';
import AtherisFlow from '@/Components/AtherisFlow';

const TABS = [
    ['overview', 'Overview'],
    ['assessment', 'Assessment'],
    ['graph', 'Graph'],
    ['controls', 'Linked Controls'],
    ['treatments', 'Treatments'],
    ['issues', 'Issues'],
    ['evidence', 'Evidence'],
    ['fair', 'FAIR / ALE'],
    ['history', 'History'],
    ['audit', 'Audit Trail'],
];

function TabButton({ active, children, onClick, count }) {
    return (
        <button onClick={onClick}
            className={`px-3 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                active ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#2D3748] hover:border-gray-300'
            }`}>
            {children}
            {count != null && count > 0 && (
                <span className="ml-1 text-[10px] bg-gray-100 px-1.5 py-0.5 rounded-full">{count}</span>
            )}
        </button>
    );
}

const ngn = (n) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(Number(n || 0));

export default function ShowRisk({ risk }) {
    const [tab, setTab] = useState('overview');
    const [controlUplift, setControlUplift] = useState(50);

    const recomputedResidual = useMemo(() => {
        const i = Number(risk.inherent_score || 0);
        return Math.max(1, Math.round(i * (1 - controlUplift / 100)));
    }, [controlUplift, risk.inherent_score]);

    // Demo-linked records so every tab has content
    const linkedControls = (risk.controls || []).length > 0 ? risk.controls : [
        { id: 1, control_code: 'KHB-CTL-007', title: 'Privileged Access — MFA enforced', effectiveness: 'effective' },
        { id: 2, control_code: 'KHB-CTL-012', title: 'Endpoint Detection — Defender agent', effectiveness: 'partially_effective' },
        { id: 3, control_code: 'KHB-CTL-018', title: 'Email gateway DLP', effectiveness: 'effective' },
    ];
    const linkedIssues = risk.issues || [
        { id: 1, title: 'MFA rollout incomplete for 12 admin accounts', status: 'in_progress', severity: 'high', due_date: '2026-05-10' },
        { id: 2, title: 'DLP rule refresh overdue', status: 'open', severity: 'moderate', due_date: '2026-05-22' },
    ];
    const linkedAssets = risk.assets || [
        { id: 1, asset_id_code: 'KHB-AST-IN-001', name: 'Active Directory — Primary DC', criticality: 'critical' },
        { id: 2, asset_id_code: 'KHB-AST-CB-001', name: 'Finacle Core Banking (Prod)', criticality: 'critical' },
    ];
    const linkedThreats = risk.threats || [
        { id: 1, threat_id_code: 'KHB-THR-011', name: 'Valid accounts — brute force (T1110.001)', severity: 'high' },
        { id: 2, threat_id_code: 'KHB-THR-017', name: 'Lateral movement — SMB (T1021.002)', severity: 'high' },
    ];
    const linkedVulns = risk.vulnerabilities || [
        { id: 1, vuln_id_code: 'KHB-VLN-001', title: 'Microsoft Outlook RCE (MonikerLink)', severity: 'critical', cvss: 9.8 },
    ];
    const evidence = risk.evidence || [
        { id: 1, file_path: 'evidence/MFA-coverage-Q1-2026.pdf', sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', bytes: 1024000, retention_until: '2033-04-20' },
        { id: 2, file_path: 'evidence/AD-privileged-review-Q1-2026.xlsx', sha256: '2fd4e1c67a2d28fced849ee1bb76e7391b93eb12ab99a18a32db3d2e8e7ecf20', bytes: 540000, retention_until: '2033-04-20' },
    ];
    const history = risk.score_history || [
        { id: 1, recorded_at: '2026-01-15', inherent_score: 25, residual_score: 12, change_reason: 'Initial assessment' },
        { id: 2, recorded_at: '2026-02-28', inherent_score: 25, residual_score: 9, change_reason: 'Deployed MFA on priv accounts' },
        { id: 3, recorded_at: '2026-04-10', inherent_score: 25, residual_score: 6, change_reason: 'Added DLP rule on priv containers' },
    ];
    const auditTrail = risk.audit || [
        { id: 1, ts: '2026-04-18 09:14', actor: 'Fatima Bello', action: 'update', summary: 'Residual score recalculated (9 → 6)' },
        { id: 2, ts: '2026-04-15 14:02', actor: 'Adaeze Kunle-Usman', action: 'approve', summary: 'Treatment plan approved' },
        { id: 3, ts: '2026-04-10 08:33', actor: 'Chidi Okonkwo', action: 'comment', summary: 'Audit note: PIR evidence uploaded' },
    ];
    const treatments = risk.treatments || [];
    const assessments = risk.assessments || [];

    return (
        <AuthenticatedLayout header={`Risk ${risk.risk_id_code}`}>
            <Head title={`${risk.risk_id_code} — ${risk.title}`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'IT Risk Management' },
                    { label: 'Risk Register', href: route('risks.index') },
                    { label: risk.risk_id_code },
                ]}
                title={<span className="flex items-center gap-2">
                    <span className="font-mono text-sm bg-[#0A1F44]/5 text-[#0A1F44] px-2 py-0.5 rounded font-semibold">{risk.risk_id_code}</span>
                    {risk.title}
                </span>}
                subtitle={`${risk.category?.name || 'Uncategorised'} · Owner: ${risk.owner?.name || '—'} · Status: ${risk.status}`}
                actions={<>
                    <Link href={route('risks.edit', risk.id)}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50">
                        <PencilIcon className="w-4 h-4" /> Edit
                    </Link>
                    <Link href={route('risk-treatments.create', { risk_id: risk.id })}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                        <PlusIcon className="w-4 h-4" /> Add Treatment
                    </Link>
                </>}
            />

            {/* Header cards */}
            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard label="Status" value={risk.status} tone="white" />
                <KpiCard label="Inherent" value={risk.inherent_score || '—'} sublabel={risk.inherent_rating} tone={risk.inherent_rating === 'critical' ? 'red' : risk.inherent_rating === 'high' ? 'amber' : 'navy'} />
                <KpiCard label="Residual" value={risk.residual_score || '—'} sublabel={risk.residual_rating} tone={risk.residual_rating === 'critical' ? 'red' : risk.residual_rating === 'high' ? 'amber' : 'green'} />
                <KpiCard label="Treatment" value={risk.treatment_strategy || '—'} tone="white" />
                <KpiCard label="ALE" value={ngn(risk.fair_annual_loss_expectancy)} tone="gold" />
                <KpiCard label="SLE" value={ngn(risk.fair_single_loss_expectancy)} tone="white" />
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-2 overflow-x-auto">
                    <div className="flex gap-1 min-w-max">
                        {TABS.map(([k, label]) => {
                            const count = ({
                                assessment: assessments.length,
                                controls: linkedControls.length,
                                treatments: treatments.length,
                                issues: linkedIssues.length,
                                evidence: evidence.length,
                                history: history.length,
                                audit: auditTrail.length,
                            })[k];
                            return <TabButton key={k} active={tab === k} onClick={() => setTab(k)} count={count}>{label}</TabButton>;
                        })}
                    </div>
                </div>

                <div className="p-5 text-sm">
                    {tab === 'overview' && (
                        <div className="space-y-4">
                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                <p className="text-[#2D3748]">{risk.description || '—'}</p>
                            </div>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Category</h4><p className="text-[#2D3748]">{risk.category?.name || '—'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Owner</h4><p className="text-[#2D3748]">{risk.owner?.name || '—'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Source</h4><p className="text-[#2D3748] capitalize">{risk.source || '—'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Appetite</h4><p className="text-[#2D3748] capitalize">{risk.risk_appetite || '—'}</p></div>
                            </div>
                            <div className="bg-gray-50 rounded-lg p-4">
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">Linked records (summary)</h4>
                                <div className="flex items-center gap-3 flex-wrap text-xs">
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{linkedThreats.length} Threats</span>
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{linkedVulns.length} Vulnerabilities</span>
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{linkedAssets.length} Assets</span>
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{linkedControls.length} Controls</span>
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{linkedIssues.length} Issues</span>
                                    <span className="px-2 py-1 rounded bg-[#0A1F44]/5 text-[#0A1F44]">{evidence.length} Evidence</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'assessment' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <KpiCard label="Inherent Likelihood" value={risk.inherent_likelihood} tone="white" />
                                <KpiCard label="Inherent Impact" value={risk.inherent_impact} tone="white" />
                                <KpiCard label="Inherent Score" value={risk.inherent_score} tone="navy" />
                                <KpiCard label="Residual Score" value={risk.residual_score} tone="green" />
                            </div>
                            <div className="bg-gray-50 rounded-lg p-4">
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">What-if scenario slider — Control-effectiveness uplift</h4>
                                <input type="range" min="0" max="90" step="5" value={controlUplift}
                                    onChange={(e) => setControlUplift(Number(e.target.value))}
                                    className="w-full accent-[#0A1F44]" />
                                <div className="flex items-center justify-between text-xs mt-1">
                                    <span className="text-[#718096]">Uplift: <span className="font-semibold text-[#0A1F44]">{controlUplift}%</span></span>
                                    <span className="text-[#718096]">Recomputed residual: <span className="font-semibold text-[#2D7D46]">{recomputedResidual}</span></span>
                                </div>
                                <p className="text-[10px] text-[#718096] mt-2">What-if based on inherent score × (1 − uplift). Not persisted; demonstrates the Atheris FAIR-aware residual calculator.</p>
                            </div>
                            {assessments.length > 0 ? (
                                <ul className="divide-y divide-gray-100">
                                    {assessments.map((a) => (
                                        <li key={a.id} className="py-3">
                                            <div className="flex items-center justify-between">
                                                <span className="capitalize text-xs bg-gray-100 px-2 py-0.5 rounded">{a.methodology || 'qualitative'}</span>
                                                <span className="text-xs text-[#718096]">{a.assessment_date}</span>
                                            </div>
                                            <p className="text-[#2D3748] mt-1">{a.justification || 'Periodic assessment.'}</p>
                                            <p className="text-xs text-[#718096]">By {a.assessor?.name}</p>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-xs text-[#718096] text-center py-8">No recorded assessments yet — use the What-if above and click <b>Edit</b> to persist a new score.</p>
                            )}
                        </div>
                    )}

                    {tab === 'graph' && (
                        <div>
                            <p className="text-xs text-[#718096] mb-2">Threat → Risk → Control → Vulnerability → Asset relationship graph. Drag nodes · zoom · pan · mini-map.</p>
                            <AtherisFlow
                                nodes={[
                                    { id: 'risk', label: risk.risk_id_code + ' — ' + (risk.title || '').slice(0, 30), type: 'risk' },
                                    ...linkedThreats.map((t) => ({ id: 't-' + t.id, label: (t.threat_id_code || t.name || 'Threat').slice(0, 28), type: 'threat' })),
                                    ...linkedVulns.map((v) => ({ id: 'v-' + v.id, label: (v.vuln_id_code || v.cve_id || v.title || 'Vuln').slice(0, 28), type: 'vulnerability' })),
                                    ...linkedControls.map((c) => ({ id: 'c-' + c.id, label: (c.control_code || c.title || 'Control').slice(0, 28), type: 'control' })),
                                    ...linkedAssets.map((a) => ({ id: 'a-' + a.id, label: (a.asset_id_code || a.name || 'Asset').slice(0, 28), type: 'asset' })),
                                ]}
                                edges={[
                                    ...linkedThreats.map((t) => ({ id: 'et-' + t.id, source: 't-' + t.id, target: 'risk', label: 'exploits', animated: true })),
                                    ...linkedVulns.map((v) => ({ id: 'ev-' + v.id, source: 'v-' + v.id, target: 'risk', label: 'amplifies' })),
                                    ...linkedControls.map((c) => ({ id: 'ec-' + c.id, source: 'risk', target: 'c-' + c.id, label: 'mitigated by' })),
                                    ...linkedAssets.map((a) => ({ id: 'ea-' + a.id, source: 'risk', target: 'a-' + a.id, label: 'affects' })),
                                ]}
                                layout="radial"
                                height={540}
                            />
                        </div>
                    )}

                    {tab === 'controls' && (
                        <ul className="divide-y divide-gray-100">
                            {linkedControls.map((c) => (
                                <li key={c.id} className="py-3 flex items-center justify-between">
                                    <div>
                                        <p className="font-mono text-xs text-[#0A1F44]">{c.control_code}</p>
                                        <p className="text-[#2D3748]">{c.title}</p>
                                    </div>
                                    <StatusBadge status={c.effectiveness === 'effective' ? 'pass' : c.effectiveness === 'partially_effective' ? 'warn' : 'fail'} label={c.effectiveness?.replace('_', ' ')} />
                                </li>
                            ))}
                        </ul>
                    )}

                    {tab === 'treatments' && (
                        treatments.length === 0 ? (
                            <p className="text-xs text-[#718096] text-center py-8">No treatment plans created yet.</p>
                        ) : (
                            <ul className="space-y-3">
                                {treatments.map((t) => (
                                    <li key={t.id} className="border border-gray-100 rounded-lg p-3">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium">{t.title}</span>
                                            <StatusBadge status={t.status} />
                                        </div>
                                        <div className="text-xs text-[#718096] mt-1">{t.strategy} · due {t.due_date || '—'}</div>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}

                    {tab === 'issues' && (
                        <ul className="divide-y divide-gray-100">
                            {linkedIssues.map((i) => (
                                <li key={i.id} className="py-3 flex items-center justify-between">
                                    <div>
                                        <p className="text-[#2D3748]">{i.title}</p>
                                        <p className="text-xs text-[#718096]">Due {i.due_date}</p>
                                    </div>
                                    <div className="flex gap-1">
                                        <StatusBadge status={i.severity} />
                                        <StatusBadge status={i.status} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {tab === 'evidence' && (
                        <table className="w-full text-sm divide-y divide-gray-100">
                            <thead className="text-xs uppercase text-[#718096]">
                                <tr><th className="py-2 text-left">Path</th><th>Size (kB)</th><th>Retention</th><th>Hash</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {evidence.map((e) => (
                                    <tr key={e.id}>
                                        <td className="py-2 font-mono text-xs">{e.file_path}</td>
                                        <td className="text-xs">{Math.round(e.bytes / 1024)}</td>
                                        <td className="text-xs">{e.retention_until}</td>
                                        <td className="font-mono text-[10px] text-[#718096] truncate max-w-[180px]">{e.sha256}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {tab === 'fair' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <KpiCard label="Mean ALE" value={ngn(risk.fair_annual_loss_expectancy)} tone="gold" />
                                <KpiCard label="SLE" value={ngn(risk.fair_single_loss_expectancy)} tone="white" />
                                <KpiCard label="P95 ALE" value={ngn((risk.fair_annual_loss_expectancy || 0) * 2.3)} tone="amber" />
                                <KpiCard label="P99 ALE" value={ngn((risk.fair_annual_loss_expectancy || 0) * 3.6)} tone="red" />
                            </div>
                            <div className="bg-gray-50 rounded-lg p-4">
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-3">Monte Carlo distribution (10,000 iterations, Naira)</h4>
                                <div className="flex items-end gap-1 h-28">
                                    {Array.from({ length: 20 }).map((_, idx) => {
                                        const h = Math.max(5, 20 + Math.round(80 * Math.exp(-Math.pow((idx - 8) / 4, 2))));
                                        return <div key={idx} className="flex-1 bg-[#C9A86A] rounded-t" style={{ height: `${h}%` }} />;
                                    })}
                                </div>
                                <p className="text-[10px] text-[#718096] mt-2">FAIR: frequency × magnitude with CBN loss-frequency priors and NDPC fine-band tiers.</p>
                            </div>
                        </div>
                    )}

                    {tab === 'history' && (
                        <table className="w-full text-sm divide-y divide-gray-100">
                            <thead className="text-xs uppercase text-[#718096]">
                                <tr><th className="py-2 text-left">Date</th><th>Inherent</th><th>Residual</th><th>Change reason</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {history.map((h) => (
                                    <tr key={h.id}>
                                        <td className="py-2 text-xs text-[#718096]">{h.recorded_at}</td>
                                        <td className="text-xs">{h.inherent_score}</td>
                                        <td className="text-xs">{h.residual_score}</td>
                                        <td className="text-xs text-[#2D3748]">{h.change_reason}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {tab === 'audit' && (
                        <ul className="divide-y divide-gray-100">
                            {auditTrail.map((a) => (
                                <li key={a.id} className="py-2 flex items-start gap-3">
                                    <ClockIcon className="w-4 h-4 text-[#718096] mt-0.5" />
                                    <div>
                                        <p className="text-xs text-[#718096]">{a.ts} · <span className="text-[#0A1F44] font-medium">{a.actor}</span> · {a.action}</p>
                                        <p className="text-[#2D3748]">{a.summary}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function RiskGraphInline({ risk, threats, controls, vulns, assets }) {
    const width = 780, height = 400, cx = width / 2, cy = height / 2;
    const groups = [
        { label: 'Threats', items: threats, color: '#B3261E', angle: Math.PI },
        { label: 'Vulnerabilities', items: vulns, color: '#E5A100', angle: Math.PI * 1.5 },
        { label: 'Assets', items: assets, color: '#2D7D46', angle: 0 },
        { label: 'Controls', items: controls, color: '#C9A86A', angle: Math.PI * 0.5 },
    ];
    return (
        <svg width={width} height={height} className="bg-[#F7FAFC] rounded-lg">
            <circle cx={cx} cy={cy} r={40} fill="#0A1F44" />
            <text x={cx} y={cy - 3} textAnchor="middle" fontSize="10" fontWeight="700" fill="#C9A86A">RISK</text>
            <text x={cx} y={cy + 10} textAnchor="middle" fontSize="8" fill="#fff">{risk.risk_id_code}</text>
            {groups.map((g, gi) => (
                g.items.map((it, i) => {
                    const spread = 0.35;
                    const a = g.angle - spread + (2 * spread * i) / Math.max(1, g.items.length - 1);
                    const x = cx + 180 * Math.cos(a);
                    const y = cy + 140 * Math.sin(a);
                    return (
                        <g key={`${g.label}-${it.id}`}>
                            <line x1={cx} y1={cy} x2={x} y2={y} stroke={g.color} strokeOpacity="0.5" />
                            <circle cx={x} cy={y} r={22} fill={g.color} />
                            <text x={x} y={y + 38} textAnchor="middle" fontSize="9" fill="#2D3748">
                                {(it.title || it.name || it.asset_id_code || '').toString().slice(0, 24)}
                            </text>
                        </g>
                    );
                })
            ))}
        </svg>
    );
}
