import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, GATE_TONES, humanize, invMoney, localDate, stageLabel } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const gateName = (g) => (g.gate === 'change' ? 'Change beyond tolerance' : g.gate === 'approved' ? 'Business case approval' : g.gate === 'delivered' ? 'Delivery acceptance' : `Move to ${stageLabel(g.gate)}`);

/** Per-gate history: criteria snapshot, engine trail and decisions for every gate request, newest first. */
export default function GatesPanel({ investment, gates, nextGate, baseCurrency, can, onAdvance, errors = {} }) {
    const [decision, setDecision] = useState(null);
    const money = can.viewAmounts !== false;
    const decide = (payload, opts) => router.post(route(`finance.stage-gates.${decision.action}`, decision.gate.id), payload, { preserveScroll: true, ...opts });
    const withdraw = (g) => confirm('Withdraw this gate request?') && router.post(route('finance.stage-gates.withdraw', g.id), {}, { preserveScroll: true });
    return (
        <div className="space-y-4">
            {nextGate && can.advance && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-5 py-3">
                    <p className="text-sm text-[#2D3748]">Next gate: <b>{stageLabel(nextGate.to_stage)}</b>{nextGate.requires_approval ? ' (needs approval)' : ''}. {nextGate.criteria.filter((c) => c.auto && !c.met).length} automatic criteria not yet met.</p>
                    <button type="button" onClick={onAdvance} className={BTN_PRIMARY}>Review gate checklist</button>
                </div>
            )}
            {errors.approval && <p role="alert" className="text-sm text-[#B3261E]">{errors.approval}</p>}
            {errors.workflow && <p role="alert" className="text-sm text-[#B3261E]">{errors.workflow}</p>}
            {errors.gate && <p role="alert" className="text-sm text-[#B3261E]">{errors.gate}</p>}
            {gates.length === 0 ? <Card><Empty>No gate has been requested or passed yet.</Empty></Card> : gates.map((g) => (
                <Card key={g.id} title={<span className="flex flex-wrap items-center gap-2">{gateName(g)}<Chip size="xs" tone={GATE_TONES[g.status] ?? 'neutral'}>{humanize(g.status)}</Chip></span>}
                    right={<span className="text-xs text-[#718096]">Requested {localDate(g.requested_at)}{g.requested_by ? ` by ${g.requested_by}` : ''}</span>}>
                    <div className="space-y-3 px-5 py-4">
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-[#4A5568]">
                            <span>From {stageLabel(g.from_stage)}</span>
                            {g.approval_base_amount !== undefined && g.approval_base_amount !== null || !money ? <span>Amount for approval {invMoney(g.approval_base_amount, baseCurrency, money, { decimals: 0 })}{money && g.amount_band_label ? ` · ${g.amount_band_label}` : ''}</span> : null}
                            {g.passed_at && <span>Passed {localDate(g.passed_at)}{g.passed_by ? ` by ${g.passed_by}` : ''}</span>}
                            {g.decided_at && <span>Decided {localDate(g.decided_at)}{g.decided_by ? ` by ${g.decided_by}` : ''}</span>}
                        </div>
                        {g.request_note && <p className="text-sm text-[#2D3748]">{g.request_note}</p>}
                        {g.rejection_reason && <p className="text-sm text-[#B3261E]">Rejected: {g.rejection_reason}</p>}
                        <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                            {(g.criteria ?? []).map((c) => (
                                <li key={c.key} className="flex items-start gap-1.5 text-xs text-[#4A5568]">
                                    <Icon name={c.met ? 'check_circle' : 'radio_button_unchecked'} size={16} className={c.met ? 'text-[#2D7D46]' : 'text-[#A0A4AB]'} />
                                    <span>{c.label}{c.detail ? <span className="text-[#718096]"> · {c.detail}</span> : null}</span>
                                </li>
                            ))}
                        </ul>
                        {(g.can?.approve || g.can?.reject || g.can?.withdraw) && (
                            <div className="flex flex-wrap items-center gap-2">
                                {g.can.approve && <button type="button" onClick={() => setDecision({ action: 'approve', gate: g, title: 'Approve gate', req: false })} className={BTN_PRIMARY}>Approve</button>}
                                {g.can.reject && <button type="button" onClick={() => setDecision({ action: 'reject', gate: g, title: 'Reject gate', req: true })} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                                {g.can.withdraw && <button type="button" onClick={() => withdraw(g)} className={BTN_SECONDARY}>Withdraw</button>}
                            </div>
                        )}
                        {g.status !== 'passed' && g.trail !== null && <ApprovalTrail process="finance.business_case_approval" id={g.id} version={g.status} />}
                    </div>
                </Card>
            ))}
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.req} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title} />}
        </div>
    );
}
