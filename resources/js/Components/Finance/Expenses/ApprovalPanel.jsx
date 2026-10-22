import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import BandSnapshot from '@/Components/Finance/Budgets/BandSnapshot';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import { BTN_PRIMARY, BTN_SECONDARY } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Engine state exactly as the controller passes it (`approval` = FinanceApprovals::trail): the trail with
 * the current step, who may decide and SLA due, the approval context snapshot, and approve / return / reject actions.
 * `prefix` is `finance.expenses` or `finance.invoices`.
 */
export default function ApprovalPanel({ prefix, subject, approval, can, baseCurrency, errors = {}, version }) {
    const [decision, setDecision] = useState(null);
    const decide = (payload, opts) => router.post(route(`${prefix}.${decision.action}`, subject.id), payload, { preserveScroll: true, ...opts });
    const pending = approval?.request?.steps?.find((s) => s.status === 'pending');
    return (
        <div className="space-y-4">
            {(can.approve || can.return || can.reject) && (
                <div className="flex flex-wrap items-center gap-2">
                    {can.approve && <button type="button" onClick={() => setDecision({ action: 'approve', title: 'Approve', req: false })} className={BTN_PRIMARY}>Approve</button>}
                    {can.return && <button type="button" onClick={() => setDecision({ action: 'return', title: 'Return to draft', req: true })} className={BTN_SECONDARY}>Return</button>}
                    {can.reject && <button type="button" onClick={() => setDecision({ action: 'reject', title: 'Reject', req: true })} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                    {pending && <span className="text-xs text-[#718096]">Current step: {pending.name}{pending.due_at ? '' : ''}</span>}
                </div>
            )}
            {errors.approval && <p role="alert" className="text-sm text-[#B3261E]">{errors.approval}</p>}
            {errors.workflow && <p role="alert" className="text-sm text-[#B3261E]">{errors.workflow}</p>}
            <ApprovalTrail process={approval.process} id={subject.id} version={version ?? subject.status} />
            <BandSnapshot context={approval.context} baseCurrency={baseCurrency} />
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.req} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title} />}
        </div>
    );
}
