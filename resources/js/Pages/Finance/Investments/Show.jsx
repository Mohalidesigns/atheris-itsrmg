import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import AdvanceGateModal from '@/Components/Finance/Investments/AdvanceGateModal';
import BenefitsPanel from '@/Components/Finance/Investments/BenefitsPanel';
import BusinessCaseEditor from '@/Components/Finance/Investments/BusinessCaseEditor';
import ChangesPanel from '@/Components/Finance/Investments/ChangesPanel';
import EvmPanel from '@/Components/Finance/Investments/EvmPanel';
import GatesPanel from '@/Components/Finance/Investments/GatesPanel';
import HealthChip from '@/Components/Finance/Investments/HealthChip';
import InvestmentLinksPanel from '@/Components/Finance/Investments/InvestmentLinksPanel';
import LinkedLinesPanel from '@/Components/Finance/Investments/LinkedLinesPanel';
import ProgressModal from '@/Components/Finance/Investments/ProgressModal';
import RgtChip from '@/Components/Finance/Investments/RgtChip';
import ScoringPanel from '@/Components/Finance/Investments/ScoringPanel';
import StageChip from '@/Components/Finance/Investments/StageChip';
import StageStepper from '@/Components/Finance/Investments/StageStepper';
import ValueReviewsPanel from '@/Components/Finance/Investments/ValueReviewsPanel';
import InvestmentsNav from '@/Components/Finance/Investments/InvestmentsNav';
import DocumentsPanel from '@/Components/Finance/Expenses/DocumentsPanel';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL, capStatusTone, formatPct, humanize, invMoney, isoDate, localDate } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS = ['overview', 'business-case', 'scoring', 'gates', 'budget', 'benefits', 'reviews', 'changes', 'activity'];
const DOC_KINDS = ['business_case', 'quote', 'approval', 'benefit_evidence', 'pir_report', 'other'].map((v) => ({ value: v, label: humanize(v) }));

function Fact({ label, children }) {
    return <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;
}

function CapitalisationFlag({ i, options, can }) {
    const standards = options.capitalisationStandards ?? [];
    const save = (patch) => router.put(route('finance.investments.update', i.id), patch, { preserveScroll: true });
    return (
        <Card title="Capitalisation">
            <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" disabled={!can.edit} checked={!!i.is_capitalisable} onChange={(e) => save({ is_capitalisable: e.target.checked, ...(e.target.checked ? {} : { capitalisation_standard: null }) })} className="rounded border-gray-300" /> Capitalisable: delivered cost can move to the asset register</label>
                {i.is_capitalisable && (
                    <div><label className={LABEL} htmlFor="iv-cs">Standard</label><select id="iv-cs" disabled={!can.edit} value={i.capitalisation_standard ?? ''} onChange={(e) => save({ capitalisation_standard: e.target.value || null })} className={FIELD}><option value="">Decide at capitalisation</option>{standards.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                )}
            </div>
        </Card>
    );
}

function Overview({ i, links, linkOptions, documents, baseCurrency, can, options }) {
    const money = can.viewAmounts !== false;
    return (
        <div className="space-y-4">
            <Card title="Investment">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                    <Fact label="Type">{humanize(i.type)}</Fact>
                    <Fact label="Run, grow, transform"><RgtChip rgt={i.rgt} /></Fact>
                    <Fact label="Driver">{humanize(i.driver)}</Fact>
                    <Fact label="Regulatory">{i.is_regulatory ? <span>Yes{i.regulatory_reference ? `: ${i.regulatory_reference}` : ''}</span> : 'No'}</Fact>
                    <Fact label="Sponsor">{i.sponsor?.name ?? <span className="text-[#B3261E]">Unassigned</span>}</Fact>
                    <Fact label="Manager">{i.manager?.name ?? <span className="text-[#B3261E]">Unassigned</span>}</Fact>
                    <Fact label="Cost centre">{i.cost_centre && `${i.cost_centre.code} ${i.cost_centre.name}`}</Fact>
                    <Fact label="Legal entity">{i.legal_entity && `${i.legal_entity.code} ${i.legal_entity.name}`}</Fact>
                    <Fact label="Funding year">{i.fiscal_year?.year_label}</Fact>
                    <Fact label="Starts">{localDate(i.starts_on)}</Fact>
                    <Fact label="Planned end">{localDate(i.planned_ends_on)}</Fact>
                    <Fact label="Actual end">{i.actual_ends_on ? localDate(i.actual_ends_on) : null}</Fact>
                    <Fact label="Planned cost">{invMoney(i.planned_cost, baseCurrency, money)}</Fact>
                    <Fact label="Budget at completion">{invMoney(i.bac, baseCurrency, money)}</Fact>
                    <Fact label="Cost tolerance">{formatPct(i.cost_tolerance_pct)}</Fact>
                    <Fact label="External reference">{i.external_reference}</Fact>
                    {i.strategic_objective && <div className="col-span-2 lg:col-span-4"><dt className="text-xs text-[#718096]">Strategic objective</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{i.strategic_objective}</dd></div>}
                    {i.description && <div className="col-span-2 lg:col-span-4"><dt className="text-xs text-[#718096]">Description</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{i.description}</dd></div>}
                    {i.cancel_reason && <div className="col-span-2 lg:col-span-4"><dt className="text-xs text-[#718096]">Cancelled because</dt><dd className="text-sm text-[#B3261E]">{i.cancel_reason}</dd></div>}
                </dl>
            </Card>
            <CapitalisationFlag i={i} options={options} can={can} />
            <InvestmentLinksPanel investment={i} links={links} linkOptions={linkOptions} can={can} />
            <DocumentsPanel subject="investment" subjectId={i.id} documents={documents} kinds={DOC_KINDS} canAttach={can.edit} defaultKind="business_case" />
        </div>
    );
}

function Activity({ activity }) {
    return (
        <Card title="Activity">
            {activity.length === 0 ? <Empty>No activity yet.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {activity.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2 text-sm">
                            <span className="text-[#2D3748]">{a.description}</span>
                            <span className="text-xs text-[#718096]">{(typeof a.causer === 'string' ? a.causer : a.causer?.name) ?? 'System'} · {localDate(a.at)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

export default function Show(props) {
    const { investment: i, businessCases, businessCase, scores, weights, gates, nextGate, evm, lines, lineOptions, benefits, reviews, changes, capitalisations = [], links, linkOptions, documents, activity, users, periods, options, baseCurrency, can, errors } = props;
    const money = can.viewAmounts !== false;
    const [tab, setTab] = useState(TABS.includes(props.tab) ? props.tab : 'overview');
    const [gateModal, setGateModal] = useState(null); // { routeName, routeParams, nextGate }
    const [progress, setProgress] = useState(false);
    const [reason, setReason] = useState(null); // 'hold' | 'cancel'
    const today = isoDate(new Date());
    const select = (key) => {
        setTab(key);
        try { window.history.replaceState(null, '', route('finance.investments.show', { investment: i.id, tab: key })); } catch { /* ignore */ }
    };
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, i.id), {}, { preserveScroll: true });
    const remove = () => confirm(`Delete ${i.investment_code}?`) && router.delete(route('finance.investments.destroy', i.id));
    const pending = gates.find((g) => g.status === 'submitted');
    const submitBc = (bc) => setGateModal({ routeName: 'finance.business-cases.submit', routeParams: bc.id, nextGate: nextGate?.to_stage === 'approved' ? nextGate : { to_stage: 'approved', requires_approval: true, criteria: [] } });
    const late = i.stage !== 'cancelled' && i.planned_ends_on && ['approved', 'in_delivery'].includes(i.stage) && i.planned_ends_on < today;

    return (
        <AuthenticatedLayout header={i.investment_code}>
            <Head title={`Investment ${i.investment_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Investments & Value', href: route('finance.investments.index') }, { label: i.investment_code }]}
                title={`${i.investment_code} ${i.title}`} subtitle={`${i.sponsor ? `Sponsor ${i.sponsor.name}` : 'No sponsor'}${i.manager ? ` · Manager ${i.manager.name}` : ''}`} />
            <InvestmentsNav active="investments" />

            {i.status === 'on_hold' && <div role="status" className="mb-4 flex items-start gap-2 rounded-lg border border-gray-300 bg-[#F1F2F4] px-4 py-3 text-sm text-[#43474E]"><Icon name="pause_circle" size={20} className="mt-0.5" /><span>This investment is on hold. Gates and changes are blocked until it resumes.</span></div>}
            {late && <div role="alert" className="mb-4 flex items-start gap-2 rounded-lg border border-[#B3261E]/30 bg-[#F9DEDC] px-4 py-3 text-sm text-[#8C1D18]"><Icon name="priority_high" size={20} className="mt-0.5" /><span>The planned end date ({localDate(i.planned_ends_on)}) has passed. Report progress or request a schedule change.</span></div>}
            {pending && <div className="mb-4 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]"><Icon name="pending_actions" size={20} className="mt-0.5" /><span>A {pending.gate === 'change' ? 'change beyond tolerance' : `${humanize(pending.gate)} gate`} is awaiting approval. <button type="button" className="underline" onClick={() => select('gates')}>View gate</button></span></div>}

            <div className="mb-4 space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                <StageStepper stage={i.stage} />
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <StageChip stage={i.stage} />
                    <HealthChip value={i.status === 'on_hold' ? 'on_hold' : (evm?.health ?? i.status)} />
                    <RgtChip rgt={i.rgt} />
                    {i.is_regulatory && <Chip size="xs" tone="warning">Regulatory</Chip>}
                    {i.priority_score !== null && i.priority_score !== undefined && <span className="text-xs text-[#4A5568]">Score {i.priority_score}{i.priority_rank ? `, rank ${i.priority_rank}` : ''}</span>}
                    <span className="text-xs text-[#4A5568]">{Number(i.percent_complete ?? 0).toFixed(0)}% complete</span>
                    <span className="font-medium tabular-nums text-[#0A1F44]">{invMoney(i.bac ?? i.planned_cost, baseCurrency, money, { decimals: 0 })}</span>
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.advance && nextGate && <button type="button" onClick={() => select('gates')} className={BTN_PRIMARY}>Next gate: {humanize(nextGate.to_stage)}</button>}
                {can.hold && <button type="button" onClick={() => setReason('hold')} className={BTN_SECONDARY}>Hold</button>}
                {can.resume && <button type="button" onClick={post('finance.investments.resume')} className={BTN_SECONDARY}>Resume</button>}
                {can.cancel && <button type="button" onClick={() => setReason('cancel')} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Cancel investment</button>}
                {can.edit && i.stage === 'idea' && gates.length === 0 && <button type="button" onClick={remove} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#B3261E]">Delete</button>}
                <button type="button" onClick={() => window.print()} className={BTN_SECONDARY}>Print</button>
            </div>
            {['investment', 'hold', 'cancel', 'gate', 'change', 'review', 'benefit'].map((k) => errors?.[k] && <p key={k} role="alert" className="mb-2 text-sm text-[#B3261E]">{errors[k]}</p>)}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" role="tablist">
                {TABS.map((k) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => select(k)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>{k === 'business-case' ? 'Business case' : humanize(k)}</button>
                ))}
            </div>

            {tab === 'overview' && <Overview i={i} links={links} linkOptions={linkOptions} documents={documents} baseCurrency={baseCurrency} can={can} options={options} />}
            {tab === 'business-case' && <BusinessCaseEditor investment={i} businessCases={businessCases} businessCase={businessCase} baseCurrency={baseCurrency} can={can} errors={errors} onSubmit={submitBc} />}
            {tab === 'scoring' && <ScoringPanel investment={i} scores={scores} weights={weights} criteria={options.criteria} can={can} />}
            {tab === 'gates' && <GatesPanel investment={i} gates={gates} nextGate={nextGate} baseCurrency={baseCurrency} can={can} errors={errors} onAdvance={() => setGateModal({ routeName: 'finance.investments.advance-gate', routeParams: i.id, nextGate })} />}
            {tab === 'budget' && (
                <div className="space-y-4">
                    <EvmPanel investment={i} evm={evm} baseCurrency={baseCurrency} can={can} onProgress={() => setProgress(true)} />
                    <LinkedLinesPanel investment={i} lines={lines} lineOptions={lineOptions} baseCurrency={baseCurrency} can={can} />
                    {i.is_capitalisable && (
                        <Card title="Capitalisations" right={can.edit && <Link className={BTN_SECONDARY} href={route('finance.capitalisations.index', { source: 'investment', id: i.id })}>Capitalise</Link>}>
                            {capitalisations.length === 0 ? <Empty>Nothing capitalised yet. Delivered capex can be moved to the asset register.</Empty> : (
                                <ul className="divide-y divide-gray-50">{capitalisations.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm">
                                        <Link className="font-mono text-xs text-[#0A1F44] hover:underline" href={c.href}>{c.capitalisation_code}</Link>
                                        <Chip size="xs" tone={capStatusTone(c.status)}>{humanize(c.status)}</Chip>
                                        <span className="tabular-nums">{invMoney(c.amount, baseCurrency, money, { decimals: 0 })}</span>
                                        <span className="text-xs text-[#718096]">{localDate(c.available_for_use_on)}</span>
                                    </li>
                                ))}</ul>
                            )}
                        </Card>
                    )}
                </div>
            )}
            {tab === 'benefits' && <BenefitsPanel investment={i} benefits={benefits} users={users} periods={periods} options={options} baseCurrency={baseCurrency} can={can} />}
            {tab === 'reviews' && <ValueReviewsPanel investment={i} reviews={reviews} users={users} options={options} today={today} can={can} errors={errors} />}
            {tab === 'changes' && <ChangesPanel investment={i} changes={changes} baseCurrency={baseCurrency} can={can} users={users} costCentres={props.costCentres ?? []} options={options} />}
            {tab === 'activity' && <Activity activity={activity} />}

            {gateModal && <AdvanceGateModal show onClose={() => setGateModal(null)} investment={i} nextGate={gateModal.nextGate} routeName={gateModal.routeName} routeParams={gateModal.routeParams} errors={errors} />}
            <ProgressModal show={progress} onClose={() => setProgress(false)} investment={i} today={today} viewAmounts={money} errors={errors} />
            <ReasonModal show={reason === 'hold'} onClose={() => setReason(null)} title="Put investment on hold" routeName="finance.investments.hold" params={i.id} submitLabel="Hold" errors={errors} />
            <ReasonModal show={reason === 'cancel'} onClose={() => setReason(null)} title="Cancel investment" routeName="finance.investments.cancel" params={i.id} submitLabel="Cancel investment" label="Reason (pending approvals are withdrawn, open reviews and benefits cancelled)" errors={errors} />
        </AuthenticatedLayout>
    );
}
