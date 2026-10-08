import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { PencilIcon, PlusIcon, ClockIcon, TrashIcon, ClipboardDocumentCheckIcon } from '@heroicons/react/24/outline';
import { ScoreDisplay, TreatmentStatusBadge } from '@/Components/Risk/RiskBadge';
import AtherisFlow from '@/Components/AtherisFlow';
import { APPETITE_LABELS, SOURCE_LABELS, formatDate, humanize, ratingFor } from '@/Utils/risk';

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

function Empty({ children }) {
    return <p className="text-xs text-[#718096] text-center py-8">{children}</p>;
}

const ngn = (n) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(Number(n || 0));
const toneFor = (rating) => ({ critical: 'red', high: 'amber', medium: 'gold' }[rating] || 'green');
const effectivenessTone = (e) => (e === 'effective' ? 'pass' : e === 'partially_effective' ? 'warn' : e ? 'fail' : 'draft');

function LinkThreatForm({ risk, availableThreats, likelihoodLabels, impactLabels }) {
    const blank = { threat_id: '', risk_id: risk.id, likelihood: '', impact: '', analysis: '' };
    const { data, setData, post, processing, errors } = useForm(blank);
    const submit = (e) => {
        e.preventDefault();
        post(route('threats.assessments.store', data.threat_id || 0), {
            preserveScroll: true,
            onSuccess: () => setData(blank),
        });
    };
    return (
        <form onSubmit={submit} className="mt-3 grid grid-cols-1 md:grid-cols-5 gap-2 items-start">
            <div className="md:col-span-2">
                <select value={data.threat_id} onChange={(e) => setData('threat_id', e.target.value)} required
                    className="w-full text-sm border-gray-200 rounded-lg">
                    <option value="">Select a threat…</option>
                    {availableThreats.map((t) => <option key={t.id} value={t.id}>{t.threat_id_code} — {t.name}</option>)}
                </select>
            </div>
            <select value={data.likelihood} onChange={(e) => setData('likelihood', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                <option value="">Likelihood…</option>
                {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
            </select>
            <select value={data.impact} onChange={(e) => setData('impact', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                <option value="">Impact…</option>
                {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
            </select>
            <button type="submit" disabled={processing || !data.threat_id}
                className="px-3 py-2 text-sm rounded-lg bg-[#0A1F44] text-white disabled:opacity-50">Link threat</button>
            <input type="text" value={data.analysis} onChange={(e) => setData('analysis', e.target.value)}
                placeholder="Analysis — how this threat realises the risk (optional)"
                className="md:col-span-5 text-sm border-gray-200 rounded-lg" />
            {Object.values(errors).length > 0 && (
                <p className="md:col-span-5 text-xs text-red-600">{Object.values(errors).join(' ')}</p>
            )}
        </form>
    );
}

export default function ShowRisk({ risk, threats = [], vulnerabilities = [], audit = [], fair = null, availableThreats = [], likelihoodLabels = {}, impactLabels = {} }) {
    const [tab, setTab] = useState('overview');
    const [controlUplift, setControlUplift] = useState(50);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const can = usePage().props.auth?.user?.permissions || [];
    const isSuper = (usePage().props.auth?.user?.roles || []).some((r) => r === 'Super Admin');
    const allowed = (p) => isSuper || can.includes(p);

    const recomputedResidual = useMemo(() => {
        const i = Number(risk.inherent_score || 0);
        return Math.max(1, Math.round(i * (1 - controlUplift / 100)));
    }, [controlUplift, risk.inherent_score]);

    const controls = risk.controls || [];
    const assets = risk.assets || [];
    const issues = risk.issues || [];
    const treatments = risk.treatments || [];
    const assessments = risk.assessments || [];
    const history = risk.score_history || [];
    const threatAssessments = risk.threat_assessments || [];
    const run = fair?.run;

    const destroy = () => router.delete(route('risks.destroy', risk.id));

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
                subtitle={`${risk.category?.name || 'Uncategorised'} · Owner: ${risk.owner?.name || 'Unassigned'} · Status: ${humanize(risk.status)}`}
                actions={<>
                    {allowed('edit risks') && (
                        <Link href={route('risks.edit', risk.id)}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50">
                            <PencilIcon className="w-4 h-4" /> Edit
                        </Link>
                    )}
                    {allowed('create risk-assessments') && (
                        <Link href={route('risk-assessments.create', { risk_id: risk.id })}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50">
                            <ClipboardDocumentCheckIcon className="w-4 h-4" /> New Assessment
                        </Link>
                    )}
                    {allowed('create risk-treatments') && (
                        <Link href={route('risk-treatments.create', { risk_id: risk.id })}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                            <PlusIcon className="w-4 h-4" /> Add Treatment
                        </Link>
                    )}
                    {allowed('delete risks') && (
                        <button onClick={() => setConfirmingDelete(true)}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-red-200 text-red-700 text-sm hover:bg-red-50">
                            <TrashIcon className="w-4 h-4" /> Archive
                        </button>
                    )}
                </>}
            />

            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard label="Status" value={humanize(risk.status)} tone="white" sublabel={risk.review_date ? `Review ${formatDate(risk.review_date)}` : null} />
                <KpiCard label="Inherent" value={risk.inherent_score || '—'} sublabel={humanize(risk.inherent_rating)} tone={risk.inherent_score ? toneFor(risk.inherent_rating) : 'white'} />
                <KpiCard label="Residual" value={risk.residual_score || '—'} sublabel={humanize(risk.residual_rating)} tone={risk.residual_score ? toneFor(risk.residual_rating) : 'white'} />
                <KpiCard label="Treatment" value={humanize(risk.treatment_strategy)} sublabel={risk.treatment_due_date ? `Due ${formatDate(risk.treatment_due_date)}` : null} tone="white" />
                <KpiCard label="Appetite" value={risk.risk_appetite ? humanize(risk.risk_appetite) : '—'} sublabel={risk.risk_appetite ? APPETITE_LABELS[risk.risk_appetite] : 'Not set'} tone={risk.risk_appetite === 'above' ? 'red' : 'white'} />
                <KpiCard label="ALE" value={risk.fair_annual_loss_expectancy ? ngn(risk.fair_annual_loss_expectancy) : '—'} sublabel={risk.fair_single_loss_expectancy ? `SLE ${ngn(risk.fair_single_loss_expectancy)}` : 'Not quantified'} tone="gold" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-2 overflow-x-auto">
                    <div className="flex gap-1 min-w-max">
                        {TABS.map(([k, label]) => {
                            const count = ({
                                assessment: assessments.length,
                                controls: controls.length,
                                treatments: treatments.length,
                                issues: issues.length,
                                history: history.length,
                                audit: audit.length,
                            })[k];
                            return <TabButton key={k} active={tab === k} onClick={() => setTab(k)} count={count}>{label}</TabButton>;
                        })}
                    </div>
                </div>

                <div className="p-5 text-sm">
                    {tab === 'overview' && (
                        <div className="space-y-5">
                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                <p className="text-[#2D3748] whitespace-pre-line">{risk.description || '—'}</p>
                            </div>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Category</h4><p className="text-[#2D3748]">{risk.category?.name || '—'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Owner</h4><p className="text-[#2D3748]">{risk.owner?.name || 'Unassigned'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Source</h4><p className="text-[#2D3748]">{SOURCE_LABELS[risk.source] || humanize(risk.source)}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase font-medium">Registered</h4><p className="text-[#2D3748]">{formatDate(risk.created_at)} by {risk.creator?.name || '—'}</p></div>
                            </div>

                            <div className="bg-gray-50 rounded-lg p-4">
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">Linked records</h4>
                                <div className="flex items-center gap-2 flex-wrap text-xs">
                                    {[
                                        [threats.length, 'Threats', 'graph'],
                                        [vulnerabilities.length, 'Vulnerabilities', 'graph'],
                                        [assets.length, 'Assets', 'graph'],
                                        [controls.length, 'Controls', 'controls'],
                                        [treatments.length, 'Treatments', 'treatments'],
                                        [issues.length, 'Issues', 'issues'],
                                    ].map(([n, label, target]) => (
                                        <button key={label} onClick={() => setTab(target)}
                                            className={`px-2 py-1 rounded ${n ? 'bg-[#0A1F44]/5 text-[#0A1F44] hover:bg-[#0A1F44]/10' : 'bg-white text-[#A0AEC0] border border-dashed border-gray-200'}`}>
                                            {n} {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">Affected assets</h4>
                                    {assets.length === 0 ? <p className="text-xs text-[#718096]">No assets linked — use <b>Edit</b> to link affected assets.</p> : (
                                        <ul className="divide-y divide-gray-100">
                                            {assets.map((a) => (
                                                <li key={a.id} className="py-2 flex items-center justify-between">
                                                    <Link href={route('assets.show', a.id)} className="hover:underline">
                                                        <span className="font-mono text-xs text-[#0A1F44] mr-2">{a.asset_id_code}</span>{a.name}
                                                    </Link>
                                                    <span className="text-xs text-[#718096]">{humanize(a.criticality)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">Threats realising this risk</h4>
                                    {threatAssessments.length === 0 ? <p className="text-xs text-[#718096]">No threats linked yet.</p> : (
                                        <ul className="divide-y divide-gray-100">
                                            {threatAssessments.map((ta) => (
                                                <li key={ta.id} className="py-2 flex items-center justify-between gap-2">
                                                    <div className="min-w-0">
                                                        {ta.threat ? (
                                                            <Link href={route('threats.show', ta.threat.id)} className="hover:underline">
                                                                <span className="font-mono text-xs text-[#0A1F44] mr-2">{ta.threat.threat_id_code}</span>{ta.threat.name}
                                                            </Link>
                                                        ) : '—'}
                                                        <p className="text-xs text-[#718096] truncate">{ta.analysis || `Assessed ${formatDate(ta.assessment_date)} by ${ta.assessor?.name || '—'}`}</p>
                                                    </div>
                                                    <ScoreDisplay score={ta.score} />
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {allowed('edit threats') && availableThreats.length > 0 && (
                                        <LinkThreatForm risk={risk} availableThreats={availableThreats} likelihoodLabels={likelihoodLabels} impactLabels={impactLabels} />
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'assessment' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <KpiCard label="Inherent Likelihood" value={risk.inherent_likelihood ? `${risk.inherent_likelihood} · ${likelihoodLabels[risk.inherent_likelihood] || ''}` : '—'} tone="white" />
                                <KpiCard label="Inherent Impact" value={risk.inherent_impact ? `${risk.inherent_impact} · ${impactLabels[risk.inherent_impact] || ''}` : '—'} tone="white" />
                                <KpiCard label="Inherent Score" value={risk.inherent_score || '—'} sublabel={humanize(risk.inherent_rating)} tone="navy" />
                                <KpiCard label="Residual Score" value={risk.residual_score || '—'} sublabel={humanize(risk.residual_rating)} tone="green" />
                            </div>
                            <div className="bg-gray-50 rounded-lg p-4">
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-2">What-if scenario — control-effectiveness uplift</h4>
                                <input type="range" min="0" max="90" step="5" value={controlUplift}
                                    onChange={(e) => setControlUplift(Number(e.target.value))}
                                    className="w-full accent-[#0A1F44]" aria-label="Control effectiveness uplift" />
                                <div className="flex items-center justify-between text-xs mt-1">
                                    <span className="text-[#718096]">Uplift: <span className="font-semibold text-[#0A1F44]">{controlUplift}%</span></span>
                                    <span className="text-[#718096]">Projected residual: <span className="font-semibold text-[#2D7D46]">{recomputedResidual}</span> ({humanize(ratingFor(recomputedResidual))})</span>
                                </div>
                                <p className="text-[10px] text-[#718096] mt-2">Projection = inherent score × (1 − uplift). Not saved — record a <b>New Assessment</b> to persist a score.</p>
                            </div>
                            {assessments.length > 0 ? (
                                <ul className="divide-y divide-gray-100">
                                    {assessments.map((a) => (
                                        <li key={a.id} className="py-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <span className="capitalize text-xs bg-gray-100 px-2 py-0.5 rounded">{humanize(a.methodology)}</span>
                                                    <span className="capitalize text-xs bg-gray-100 px-2 py-0.5 rounded">{humanize(a.assessment_type)}</span>
                                                    {a.score && <ScoreDisplay score={a.score} />}
                                                </div>
                                                <span className="text-xs text-[#718096]">{formatDate(a.assessment_date)}</span>
                                            </div>
                                            <p className="text-[#2D3748] mt-1">{a.justification || 'No justification recorded.'}</p>
                                            <div className="flex items-center justify-between">
                                                <p className="text-xs text-[#718096]">By {a.assessor?.name || '—'}</p>
                                                <Link href={route('risk-assessments.show', a.id)} className="text-xs text-[#1A365D] hover:underline">View assessment →</Link>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <Empty>No recorded assessments yet — click <b>New Assessment</b> to score this risk.</Empty>
                            )}
                        </div>
                    )}

                    {tab === 'graph' && (
                        threats.length + vulnerabilities.length + controls.length + assets.length === 0 ? (
                            <Empty>No threats, vulnerabilities, controls or assets are linked to this risk yet.</Empty>
                        ) : (
                            <div>
                                <p className="text-xs text-[#718096] mb-2">Threat → Risk → Control / Asset ← Vulnerability relationship graph. Drag nodes · zoom · pan.</p>
                                <AtherisFlow
                                    nodes={[
                                        { id: 'risk', label: risk.risk_id_code + ' — ' + (risk.title || '').slice(0, 30), type: 'risk' },
                                        ...threats.map((t) => ({ id: 't-' + t.id, label: (t.threat_id_code || t.name || 'Threat').slice(0, 28), type: 'threat' })),
                                        ...vulnerabilities.map((v) => ({ id: 'v-' + v.id, label: (v.vuln_id_code || v.cve_id || v.title || 'Vuln').slice(0, 28), type: 'vulnerability' })),
                                        ...controls.map((c) => ({ id: 'c-' + c.id, label: (c.control_code || c.title || 'Control').slice(0, 28), type: 'control' })),
                                        ...assets.map((a) => ({ id: 'a-' + a.id, label: (a.asset_id_code || a.name || 'Asset').slice(0, 28), type: 'asset' })),
                                    ]}
                                    edges={[
                                        ...threats.map((t) => ({ id: 'et-' + t.id, source: 't-' + t.id, target: 'risk', label: 'realises', animated: true })),
                                        ...controls.map((c) => ({ id: 'ec-' + c.id, source: 'risk', target: 'c-' + c.id, label: 'mitigated by' })),
                                        ...assets.map((a) => ({ id: 'ea-' + a.id, source: 'risk', target: 'a-' + a.id, label: 'affects' })),
                                        // Vulnerabilities reach the risk through the assets they sit on.
                                        ...vulnerabilities.flatMap((v) => (v.asset_ids || [])
                                            .map((id) => ({ id: `ev-${v.id}-${id}`, source: 'v-' + v.id, target: 'a-' + id, label: 'exposes' }))),
                                    ]}
                                    layout="radial"
                                    height={540}
                                />
                            </div>
                        )
                    )}

                    {tab === 'controls' && (
                        controls.length === 0 ? <Empty>No controls mapped to this risk.</Empty> : (
                            <ul className="divide-y divide-gray-100">
                                {controls.map((c) => (
                                    <li key={c.id} className="py-3 flex items-center justify-between">
                                        <Link href={route('controls.show', c.id)} className="hover:underline">
                                            <p className="font-mono text-xs text-[#0A1F44]">{c.control_code}</p>
                                            <p className="text-[#2D3748]">{c.title}</p>
                                        </Link>
                                        <div className="text-right">
                                            <StatusBadge status={effectivenessTone(c.effectiveness)} label={humanize(c.effectiveness || 'not_tested')} />
                                            {c.pivot?.effectiveness && <p className="text-[10px] text-[#718096] mt-1">Against this risk: {humanize(c.pivot.effectiveness)}</p>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}

                    {tab === 'treatments' && (
                        treatments.length === 0 ? (
                            <Empty>No treatment plans created yet — click <b>Add Treatment</b>.</Empty>
                        ) : (
                            <ul className="space-y-3">
                                {treatments.map((t) => (
                                    <li key={t.id} className="border border-gray-100 rounded-lg p-3 hover:border-[#C9A86A]">
                                        <Link href={route('risk-treatments.show', t.id)} className="block">
                                            <div className="flex items-center justify-between">
                                                <span className="font-medium">{t.title}</span>
                                                <TreatmentStatusBadge status={t.status} overdue={t.is_overdue} />
                                            </div>
                                            <div className="flex items-center justify-between text-xs text-[#718096] mt-1">
                                                <span>{humanize(t.strategy)} · due {formatDate(t.due_date)} · {t.assignee?.name || 'Unassigned'}{t.target_score ? ` · target residual ${t.target_score}` : ''}</span>
                                                <span>{t.completion_percentage ?? 0}% complete</span>
                                            </div>
                                            <div className="h-1.5 bg-gray-100 rounded mt-2">
                                                <div className="h-1.5 bg-[#2D7D46] rounded" style={{ width: `${t.completion_percentage ?? 0}%` }} />
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}

                    {tab === 'issues' && (
                        issues.length === 0 ? <Empty>No remediation issues raised against this risk. <Link href={route('issues.index')} className="text-[#1A365D] underline">Open the issue tracker</Link>.</Empty> : (
                            <ul className="divide-y divide-gray-100">
                                {issues.map((i) => (
                                    <li key={i.id} className="py-3 flex items-center justify-between">
                                        <div>
                                            <p className="text-[#2D3748]">{i.title}</p>
                                            <p className="text-xs text-[#718096]">Due {formatDate(i.due_date)} · {i.owner?.name || 'Unassigned'}</p>
                                        </div>
                                        <div className="flex gap-1">
                                            <StatusBadge status={i.severity} label={humanize(i.severity)} />
                                            <StatusBadge status={i.status} label={humanize(i.status)} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}

                    {tab === 'evidence' && (
                        <div className="space-y-4">
                            <p className="text-xs text-[#718096]">Evidence for this risk is the evidence held against its mitigating controls and treatment plans.</p>
                            {controls.length === 0 && treatments.length === 0 ? (
                                <Empty>No controls or treatments to hold evidence yet.</Empty>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {controls.map((c) => (
                                        <Link key={c.id} href={route('controls.show', c.id)} className="text-xs px-2 py-1 rounded border border-gray-200 hover:border-[#C9A86A]">
                                            {c.control_code} evidence →
                                        </Link>
                                    ))}
                                    <Link href={route('evidence-vault.index')} className="text-xs px-2 py-1 rounded bg-[#0A1F44] text-white">Open Evidence Vault →</Link>
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'fair' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <KpiCard label="ALE (register)" value={risk.fair_annual_loss_expectancy ? ngn(risk.fair_annual_loss_expectancy) : '—'} tone="gold" />
                                <KpiCard label="SLE (register)" value={risk.fair_single_loss_expectancy ? ngn(risk.fair_single_loss_expectancy) : '—'} tone="white" />
                                <KpiCard label="P95 ALE" value={run ? ngn(run.ale_p95_ngn) : '—'} sublabel={run ? 'Latest Monte Carlo run' : 'No simulation run'} tone="amber" />
                                <KpiCard label="P99 ALE" value={run ? ngn(run.ale_p99_ngn) : '—'} sublabel={run ? formatDate(run.ran_at, true) : null} tone="red" />
                            </div>
                            {run && Array.isArray(run.histogram) && run.histogram.length > 0 ? (
                                <div className="bg-gray-50 rounded-lg p-4">
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-3">
                                        {fair.scenario.name} — loss distribution ({Number(fair.scenario.iterations || 0).toLocaleString()} iterations, Naira)
                                    </h4>
                                    <div className="flex items-end gap-1 h-28">
                                        {(() => {
                                            const counts = run.histogram.map((b) => Number(b.count ?? b.frequency ?? b[1] ?? b) || 0);
                                            const max = Math.max(1, ...counts);
                                            return counts.map((c, idx) => <div key={idx} className="flex-1 bg-[#C9A86A] rounded-t" style={{ height: `${Math.max(3, (c / max) * 100)}%` }} />);
                                        })()}
                                    </div>
                                </div>
                            ) : (
                                <Empty>
                                    {fair ? `Scenario "${fair.scenario.name}" has not been simulated yet.` : 'No FAIR scenario is linked to this risk.'}{' '}
                                    <Link href={route('fair.index')} className="text-[#1A365D] underline">Go to FAIR Quantification</Link>.
                                </Empty>
                            )}
                        </div>
                    )}

                    {tab === 'history' && (
                        history.length === 0 ? <Empty>No score changes recorded yet. Scores are captured whenever the risk is assessed or re-scored.</Empty> : (
                            <table className="w-full text-sm divide-y divide-gray-100">
                                <thead className="text-xs uppercase text-[#718096]">
                                    <tr><th className="py-2 text-left">Date</th><th>Inherent</th><th>Residual</th><th className="text-left">Change reason</th><th className="text-left">By</th></tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {history.map((h) => (
                                        <tr key={h.id}>
                                            <td className="py-2 text-xs text-[#718096]">{formatDate(h.recorded_at, true)}</td>
                                            <td className="text-center"><ScoreDisplay score={h.inherent_score} /></td>
                                            <td className="text-center"><ScoreDisplay score={h.residual_score} /></td>
                                            <td className="text-xs text-[#2D3748]">{h.change_reason}</td>
                                            <td className="text-xs text-[#718096]">{h.changed_by_user?.name || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )
                    )}

                    {tab === 'audit' && (
                        audit.length === 0 ? <Empty>No audit events recorded.</Empty> : (
                            <ul className="divide-y divide-gray-100">
                                {audit.map((a) => (
                                    <li key={a.id} className="py-2 flex items-start gap-3">
                                        <ClockIcon className="w-4 h-4 text-[#718096] mt-0.5" />
                                        <div>
                                            <p className="text-xs text-[#718096]">{formatDate(a.ts, true)} · <span className="text-[#0A1F44] font-medium">{a.actor}</span> · {humanize(a.action)}</p>
                                            {a.changes?.length > 0 ? (
                                                <ul className="text-xs text-[#2D3748] mt-0.5">
                                                    {a.changes.filter((c) => c.field !== 'updated_at').map((c) => (
                                                        <li key={c.field}><span className="text-[#718096]">{humanize(c.field)}:</span> {String(c.old ?? '—')} → {String(c.new ?? '—')}</li>
                                                    ))}
                                                </ul>
                                            ) : <p className="text-[#2D3748]">Risk {a.action === 'created' ? 'registered' : humanize(a.action).toLowerCase()}</p>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}
                </div>
            </div>

            <Modal show={confirmingDelete} onClose={() => setConfirmingDelete(false)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-[#2D3748]">Archive {risk.risk_id_code}?</h2>
                    <p className="mt-2 text-sm text-[#718096]">The risk is removed from the register, heat maps and dashboards. It stays in the audit trail and its code is never reused.</p>
                    <div className="mt-6 flex justify-end gap-2">
                        <button onClick={() => setConfirmingDelete(false)} className="px-4 py-2 text-sm rounded-lg border border-gray-200">Cancel</button>
                        <button onClick={destroy} className="px-4 py-2 text-sm rounded-lg bg-[#C53030] text-white">Archive risk</button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
