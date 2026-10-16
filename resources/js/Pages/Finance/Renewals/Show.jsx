import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import BandSnapshot from '@/Components/Finance/Budgets/BandSnapshot';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import ContractsNav from '@/Components/Finance/Contracts/ContractsNav';
import ContractStatusChip from '@/Components/Finance/Contracts/ContractStatusChip';
import DaysChip from '@/Components/Finance/Contracts/DaysChip';
import ExecuteRenewalModal from '@/Components/Finance/Contracts/ExecuteRenewalModal';
import RecommendationForm from '@/Components/Finance/Contracts/RecommendationForm';
import { RenewalFacts } from '@/Components/Finance/Contracts/RenewalPanel';
import TimelinePanel from '@/Components/Finance/Contracts/TimelinePanel';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, DECISION_LABELS, RENEWAL_TONES, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ renewal, contract, approval, events, options, currencies, baseCurrency, can, errors }) {
    const money = can.viewAmounts !== false;
    const [decision, setDecision] = useState(null);
    const [execute, setExecute] = useState(false);
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, renewal.id), {}, { preserveScroll: true });
    const decide = (payload, opts) => router.post(route(`finance.renewals.${decision.action}`, renewal.id), payload, { preserveScroll: true, ...opts });
    const c = renewal.contract;

    return (
        <AuthenticatedLayout header={renewal.renewal_code}>
            <Head title={`Renewal ${renewal.renewal_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Renewals', href: route('finance.renewals.index') }, { label: renewal.renewal_code }]}
                title={`${renewal.renewal_code} ${c.title}`} subtitle={c.vendor?.name} />
            <ContractsNav active="renewals" />

            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card title="Contract" className="lg:col-span-2" right={<Link href={route('finance.contracts.show', c.id)} className="text-xs text-[#0A1F44] underline">Open contract</Link>}>
                    <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-3">
                        <div><dt className="text-xs text-[#718096]">Contract</dt><dd className="font-mono text-xs">{c.contract_code}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Status</dt><dd><ContractStatusChip status={c.status} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Owner</dt><dd>{c.owner?.name ?? '—'}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Ends</dt><dd>{localDate(c.ends_on)} <DaysChip days={contract.days_to_end} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Notice deadline</dt><dd>{localDate(c.notice_deadline_on)} <DaysChip days={c.days_to_notice} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Current annualised (base)</dt><dd className="tabular-nums">{moneyOrMask(c.annualised_base_value, baseCurrency, money)}</dd></div>
                    </dl>
                </Card>
                <Card title="Renewal">
                    <div className="space-y-2 px-5 py-4 text-sm">
                        <div className="flex flex-wrap items-center gap-2"><Chip tone={RENEWAL_TONES[renewal.status] || 'neutral'}>{humanize(renewal.status)}</Chip>{renewal.is_auto && <Chip size="xs" icon="autorenew">Auto</Chip>}
                            {money && renewal.amount_band_label && <Chip size="xs" tone="tertiary">{renewal.amount_band_label}</Chip>}
                            {renewal.requires_cfo === 'yes' && <Chip size="xs" tone="warning">Needs CFO</Chip>}</div>
                        <p className="text-xs text-[#718096]">Opened {localDate(renewal.opened_on)}</p>
                    </div>
                </Card>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.submit && <button type="button" onClick={post('finance.renewals.submit', 'Submit this recommendation for approval?')} className={BTN_PRIMARY}>Submit for approval</button>}
                {can.withdraw && <button type="button" onClick={post('finance.renewals.withdraw', 'Withdraw this renewal?')} className={BTN_SECONDARY}>Withdraw</button>}
                {can.approve && <button type="button" onClick={() => setDecision({ action: 'approve', title: 'Approve renewal', noteRequired: false })} className={BTN_PRIMARY}>Approve</button>}
                {can.reject && <button type="button" onClick={() => setDecision({ action: 'reject', title: 'Reject renewal', noteRequired: true })} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                {can.execute && <button type="button" onClick={() => setExecute(true)} className={BTN_PRIMARY}>Execute</button>}
                <button type="button" onClick={() => window.print()} className={BTN_SECONDARY}>Print</button>
            </div>
            {errors?.approval && <p className="mb-3 text-sm text-[#B3261E]">{errors.approval}</p>}
            {renewal.status === 'open' && !can.submit && can.recommend && <p className="mb-3 text-xs text-[#718096]">Save a decision and a justification to enable submission.</p>}

            {renewal.status === 'rejected' && renewal.rejection_reason && <p className="mb-4 rounded-lg bg-[#F9DEDC] px-4 py-3 text-sm text-[#8C1D18]">Rejected: {renewal.rejection_reason}</p>}
            {renewal.new_contract && (
                <Card title="Outcome" className="mb-4">
                    <p className="px-5 py-4 text-sm">{DECISION_LABELS[renewal.recommendation] ?? 'Executed'} on {localDate(renewal.executed_at)} by {renewal.executed_by?.name ?? 'system'}. Successor: <Link className="text-[#0A1F44] underline" href={route('finance.contracts.show', renewal.new_contract.id)}>{renewal.new_contract.contract_code} {renewal.new_contract.title}</Link></p>
                </Card>
            )}
            {renewal.status === 'executed' && !renewal.new_contract && <Card title="Outcome" className="mb-4"><p className="px-5 py-4 text-sm">Executed on {localDate(renewal.executed_at)}. No successor contract was created.</p></Card>}

            <div className="space-y-4">
                {can.recommend
                    ? <RecommendationForm key={renewal.id} renewal={renewal} contract={contract} options={options} currencies={currencies} baseCurrency={baseCurrency} can={can} />
                    : <Card title="Recommendation"><RenewalFacts renewal={renewal} baseCurrency={baseCurrency} can={can} />
                        {(renewal.benchmark_notes || renewal.alternatives_considered) && (
                            <dl className="grid grid-cols-1 gap-3 border-t border-gray-50 px-5 py-4 text-sm sm:grid-cols-2">
                                {renewal.benchmark_notes && <div><dt className="text-xs text-[#718096]">Benchmark notes</dt><dd className="whitespace-pre-line">{renewal.benchmark_notes}</dd></div>}
                                {renewal.alternatives_considered && <div><dt className="text-xs text-[#718096]">Alternatives considered</dt><dd className="whitespace-pre-line">{renewal.alternatives_considered}</dd></div>}
                            </dl>
                        )}</Card>}
                {approval && <>
                    <ApprovalTrail process="finance.contract_renewal" id={renewal.id} version={renewal.status} />
                    <BandSnapshot context={approval.request?.context ?? approval.context} baseCurrency={baseCurrency} />
                </>}
                <TimelinePanel events={events} />
            </div>

            {decision && <DecisionModal show title={decision.title} noteRequired={decision.noteRequired} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title.split(' ')[0]} />}
            <ExecuteRenewalModal show={execute} onClose={() => setExecute(false)} renewal={renewal} contract={c} />
        </AuthenticatedLayout>
    );
}
