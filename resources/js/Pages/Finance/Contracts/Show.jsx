import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import ContractsNav from '@/Components/Finance/Contracts/ContractsNav';
import ContractStatusChip from '@/Components/Finance/Contracts/ContractStatusChip';
import DaysChip from '@/Components/Finance/Contracts/DaysChip';
import DocumentsPanel from '@/Components/Finance/Contracts/DocumentsPanel';
import LinksPanel from '@/Components/Finance/Contracts/LinksPanel';
import RenewalPanel from '@/Components/Finance/Contracts/RenewalPanel';
import SpendPanel from '@/Components/Finance/Contracts/SpendPanel';
import TeamPanel from '@/Components/Finance/Contracts/TeamPanel';
import TerminateModal from '@/Components/Finance/Contracts/TerminateModal';
import TimelinePanel from '@/Components/Finance/Contracts/TimelinePanel';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, CONTRACT_TYPE_LABELS, humanize, localDate, moneyOrMask, formatPct } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS = ['overview', 'team', 'links', 'documents', 'renewal', 'timeline', 'spend'];

function Fact({ label, children }) {
    return <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;
}

function Overview({ c, baseCurrency, money }) {
    const m = (v, cur = baseCurrency) => moneyOrMask(v, cur, money);
    const licence = c.licence_metric ? `${Number(c.deployed_quantity ?? 0).toLocaleString()} deployed of ${Number(c.licence_quantity ?? 0).toLocaleString()} ${humanize(c.licence_metric).toLowerCase()}` : null;
    return (
        <div className="space-y-4">
            <Card title="Contract">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                    <Fact label="Vendor">{c.vendor?.name}</Fact>
                    <Fact label="Type">{CONTRACT_TYPE_LABELS[c.type] ?? humanize(c.type)}</Fact>
                    <Fact label="Legal entity">{c.legal_entity && `${c.legal_entity.code} ${c.legal_entity.name}`}</Fact>
                    <Fact label="Cost centre">{c.cost_centre && `${c.cost_centre.code} ${c.cost_centre.name}`}</Fact>
                    <Fact label="Category">{c.category && `${c.category.code} ${c.category.name}`}</Fact>
                    <Fact label="Owner">{c.owner?.name ?? <span className="text-[#B3261E]">Unassigned</span>}</Fact>
                    <Fact label="Technical owner">{c.technical_owner?.name}</Fact>
                    <Fact label="Procurement contact">{c.procurement_contact?.name}</Fact>
                    <Fact label="External reference">{c.external_reference}</Fact>
                    <Fact label="Budget line">{c.budget_line && <Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: c.budget_line.budget_id, tab: 'lines' })}>{c.budget_line.label}</Link>}</Fact>
                    {c.description && <div className="col-span-2 lg:col-span-4"><dt className="text-xs text-[#718096]">Description</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{c.description}</dd></div>}
                </dl>
            </Card>
            <Card title="Term and renewal">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                    <Fact label="Commenced">{localDate(c.commenced_on)}</Fact>
                    <Fact label="Ends">{localDate(c.ends_on)} <DaysChip days={c.days_to_end} muted={['renewed', 'terminated', 'replaced', 'draft'].includes(c.status)} /></Fact>
                    <Fact label="Term">{c.term_months} months</Fact>
                    <Fact label="Renewal type">{humanize(c.renewal_type)}{c.is_auto ? ' (auto-renews)' : ''}</Fact>
                    <Fact label="Window opens">{localDate(c.renewal_window_opens_on)} <span className="text-xs text-[#718096]">({c.effective_lead_days} days before end)</span></Fact>
                    <Fact label="Notice deadline">{localDate(c.notice_deadline_on)} <span className="text-xs text-[#718096]">({c.notice_period_days} days notice)</span></Fact>
                    <Fact label="Reminders">{c.effective_offsets?.length ? c.effective_offsets.map((n) => `T-${n}`).join(', ') : 'None'}{c.notification_offsets ? ' (custom)' : ' (bank default)'}</Fact>
                    <Fact label="Last reminder">{c.last_notified_offset !== null && c.last_notified_offset !== undefined ? `T-${c.last_notified_offset}` : null}</Fact>
                    {c.decision && <Fact label="Decision">{humanize(c.decision)} by {c.decision_by?.name ?? 'system'} on {localDate(c.decision_at)}</Fact>}
                    {c.terminated_on && <Fact label="Terminated">{localDate(c.terminated_on)}: {c.termination_reason}</Fact>}
                </dl>
            </Card>
            <Card title="Money and licensing">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                    <Fact label={`Value (${c.currency})`}>{m(c.contract_value, c.currency)}</Fact>
                    <Fact label={`Value (${baseCurrency})`}>{m(c.base_contract_value)}</Fact>
                    <Fact label="Annualised (base)">{m(c.annualised_base_value)}</Fact>
                    <Fact label="Approval band">{money ? c.amount_band_label : '•••'}</Fact>
                    <Fact label="Billing">{humanize(c.billing_frequency)}</Fact>
                    <Fact label="Amount per billing">{c.amount_per_billing === null && money ? null : m(c.amount_per_billing, c.currency)}</Fact>
                    <Fact label="Payment terms">{c.payment_terms_days !== null && c.payment_terms_days !== undefined ? `${c.payment_terms_days} days` : null}</Fact>
                    <Fact label="Price escalation">{c.price_escalation_pct !== null ? formatPct(c.price_escalation_pct) : null}</Fact>
                    <Fact label="Licence position">{licence && <span className="flex flex-wrap items-center gap-1">{licence}
                        {c.licence_position === 'over' && <Chip size="xs" tone="error" icon="warning">Over-licensed</Chip>}
                        {c.licence_position === 'under' && <Chip size="xs" tone="warning">Under-used</Chip>}
                        {c.licence_position === 'ok' && <Chip size="xs" tone="success">Within entitlement</Chip>}</span>}</Fact>
                    {c.fx_rate !== null && c.currency !== baseCurrency && money && <Fact label="FX rate">{Number(c.fx_rate).toLocaleString(undefined, { maximumFractionDigits: 6 })} on {localDate(c.fx_rate_date)}</Fact>}
                </dl>
            </Card>
            <Card title="Data protection and clauses">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                    <Fact label="Data processing role">{humanize(c.data_processing_role)}</Fact>
                    <Fact label="DPA status">{c.dpa_status === 'pending' && c.data_processing_role !== 'none' ? <Chip size="xs" tone="warning" icon="warning">DPA pending</Chip> : humanize(c.dpa_status)}</Fact>
                    {c.sla_summary && <div className="col-span-2"><dt className="text-xs text-[#718096]">SLA summary</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{c.sla_summary}</dd></div>}
                    {c.exit_terms && <div className="col-span-2"><dt className="text-xs text-[#718096]">Exit terms</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{c.exit_terms}</dd></div>}
                    {(c.key_clauses ?? []).map((k) => <Fact key={k.key} label={k.label}>{k.value}</Fact>)}
                </dl>
            </Card>
        </div>
    );
}

export default function Show(props) {
    const { contract: c, team, links, documents, renewal, renewals, approval, termination, terminations, terminationApproval, terminationNeedsOverride, events, spend, linkOptions, users, options, baseCurrency, can, errors } = props;
    const money = can.viewAmounts !== false;
    const [tab, setTab] = useState(TABS.includes(props.tab) ? props.tab : 'overview');
    const [terminate, setTerminate] = useState(false);
    const select = (key) => {
        setTab(key);
        try { window.history.replaceState(null, '', route('finance.contracts.show', { contract: c.id, tab: key })); } catch { /* ignore */ }
    };
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, c.id), {}, { preserveScroll: true });
    const remove = () => confirm(`Delete draft ${c.contract_code}?`) && router.delete(route('finance.contracts.destroy', c.id));
    const inWindow = c.status === 'in_renewal_window';
    const closed = ['renewed', 'terminated', 'replaced', 'draft'].includes(c.status);

    return (
        <AuthenticatedLayout header={c.contract_code}>
            <Head title={`Contract ${c.contract_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Contracts', href: route('finance.contracts.index') }, { label: c.contract_code }]}
                title={`${c.contract_code} ${c.title}`} subtitle={c.vendor?.name} />
            <ContractsNav active="contracts" />

            {c.in_passed && (
                <div role="alert" className="mb-4 flex items-start gap-2 rounded-lg border border-[#B3261E]/30 bg-[#F9DEDC] px-4 py-3 text-sm text-[#8C1D18]">
                    <Icon name="priority_high" size={20} className="mt-0.5" />
                    <span>{c.days_to_notice < 0 ? `The notice deadline passed ${Math.abs(c.days_to_notice)} days ago` : `The contract ended ${Math.abs(c.days_to_end)} days ago`} with no renewal decision recorded.{c.is_auto ? ' It will auto-renew under its terms.' : ' Record a decision now.'}</span>
                </div>
            )}
            {!c.in_passed && inWindow && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]">
                    <Icon name="event_upcoming" size={20} className="mt-0.5" />
                    <span>Renewal window is open. Notice deadline {localDate(c.notice_deadline_on)} ({c.days_to_notice >= 0 ? `${c.days_to_notice} days left` : 'passed'}).</span>
                </div>
            )}

            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <ContractStatusChip status={c.status} size="sm" />
                    <span className="flex items-center gap-1 text-[#4A5568]">Ends {localDate(c.ends_on)} <DaysChip days={c.days_to_end} muted={closed} /></span>
                    <span className="flex items-center gap-1 text-[#4A5568]">Notice {localDate(c.notice_deadline_on)} <DaysChip days={c.days_to_notice} muted={closed} /></span>
                    <span className="font-medium tabular-nums text-[#0A1F44]" title={money && c.currency !== baseCurrency ? `${moneyOrMask(c.base_contract_value, baseCurrency, money)} base` : undefined}>{moneyOrMask(c.contract_value, c.currency, money, { decimals: 0 })}</span>
                    {money && c.amount_band_label && <Chip size="xs" tone="tertiary">{c.amount_band_label}</Chip>}
                    {c.renewed_from && <Link className="text-xs text-[#0A1F44] underline" href={route('finance.contracts.show', c.renewed_from.id)}>Renewed from {c.renewed_from.contract_code}</Link>}
                    {c.renewed_to && <Link className="text-xs text-[#0A1F44] underline" href={route('finance.contracts.show', c.renewed_to.id)}>Renewed to {c.renewed_to.contract_code}</Link>}
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.edit && <Link href={route('finance.contracts.edit', c.id)} className={BTN_SECONDARY}>Edit</Link>}
                {can.activate && <button type="button" onClick={post('finance.contracts.activate', 'Activate this contract?')} className={BTN_PRIMARY}>Activate</button>}
                {can.recompute && <button type="button" onClick={post('finance.contracts.recompute')} className={BTN_SECONDARY}>Recompute dates</button>}
                {can.openRenewal && <button type="button" onClick={post('finance.renewals.open')} className={BTN_PRIMARY}>Open renewal</button>}
                {can.terminate && <button type="button" onClick={() => setTerminate(true)} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Request termination</button>}
                {can.edit && c.status === 'draft' && <button type="button" onClick={remove} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#B3261E]">Delete draft</button>}
                <button type="button" onClick={() => window.print()} className={BTN_SECONDARY}>Print</button>
            </div>
            {errors?.approval && <p className="mb-3 text-sm text-[#B3261E]">{errors.approval}</p>}
            {errors?.workflow && <p role="alert" className="mb-3 text-sm text-[#B3261E]">{errors.workflow}</p>}
            {termination?.status === 'submitted' && <div className="mb-4 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]"><Icon name="pending_actions" size={20} className="mt-0.5" /><span>Termination {termination.termination_code} is awaiting approval. <button type="button" className="underline" onClick={() => select('renewal')}>View request</button></span></div>}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" role="tablist">
                {TABS.map((k) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => select(k)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>{humanize(k)}</button>
                ))}
            </div>

            {tab === 'overview' && <Overview c={c} baseCurrency={baseCurrency} money={money} />}
            {tab === 'team' && <TeamPanel contract={c} team={team} users={users} options={options} can={can} />}
            {tab === 'links' && <LinksPanel contract={c} links={links} linkOptions={linkOptions} options={options} can={can} />}
            {tab === 'documents' && <DocumentsPanel contract={c} documents={documents} options={options} can={can} />}
            {tab === 'renewal' && <RenewalPanel termination={termination} terminations={terminations ?? []} terminationApproval={terminationApproval} contract={c} renewal={renewal} renewals={renewals} approval={approval} options={{ decisions: options.decisions }} currencies={props.currencies ?? [{ code: c.currency }, { code: baseCurrency }].filter((x, i, a) => a.findIndex((y) => y.code === x.code) === i)} baseCurrency={baseCurrency} can={can} errors={errors} />}
            {tab === 'timeline' && <TimelinePanel events={events} />}
            {tab === 'spend' && <SpendPanel contract={c} spend={spend} baseCurrency={baseCurrency} can={can} />}

            <TerminateModal show={terminate} onClose={() => setTerminate(false)} contract={c} documents={documents} needsOverride={!!terminationNeedsOverride} />
        </AuthenticatedLayout>
    );
}
