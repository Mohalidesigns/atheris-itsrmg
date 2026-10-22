import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import Chip from '@/Components/Material/Chip';
import { Card, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, humanize, localDate } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const TONES = { submitted: 'warning', approved: 'success', rejected: 'error', withdrawn: 'neutral' };

/** Termination request on the contract Renewal tab: status, override, trail, decisions and history. */
export default function TerminationPanel({ termination: t, terminations = [], approval, errors }) {
    const [decision, setDecision] = useState(null);
    if (!t && terminations.length === 0) return null;
    const history = terminations.filter((x) => !t || x.id !== t.id);
    const decide = (payload, opts) => router.post(route(`finance.terminations.${decision.action}`, t.id), payload, { preserveScroll: true, ...opts });
    const withdraw = () => confirm('Withdraw this termination request?') && router.post(route('finance.terminations.withdraw', t.id), {}, { preserveScroll: true });
    return (
        <div className="space-y-4">
            {t && (
                <Card title={<span className="flex flex-wrap items-center gap-2">Termination request <span className="font-mono text-xs">{t.termination_code}</span><Chip size="xs" tone={TONES[t.status] || 'neutral'}>{humanize(t.status)}</Chip>{t.requires_cfo && <Chip size="xs" tone="tertiary">CFO approval</Chip>}</span>}
                    right={<>
                        {t.can?.approve && <button type="button" onClick={() => setDecision({ action: 'approve', title: 'Approve termination', noteRequired: false })} className={BTN_PRIMARY}>Approve</button>}
                        {t.can?.reject && <button type="button" onClick={() => setDecision({ action: 'reject', title: 'Reject termination', noteRequired: true })} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                        {t.can?.withdraw && <button type="button" onClick={withdraw} className={BTN_SECONDARY}>Withdraw</button>}
                    </>}>
                    {errors?.approval && <p className="px-5 pt-3 text-sm text-[#B3261E]">{errors.approval}</p>}
                    <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-4">
                        <div><dt className="text-xs text-[#718096]">Requested termination date</dt><dd>{localDate(t.requested_terminated_on) || 'Today'}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Requested by</dt><dd>{t.requested_by?.name ?? '—'}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Requested</dt><dd>{localDate(t.requested_at)}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Approval band</dt><dd>{t.amount_band_label ?? '•••'}</dd></div>
                        <div className="col-span-2 sm:col-span-4"><dt className="text-xs text-[#718096]">Reason</dt><dd className="whitespace-pre-line">{t.reason}</dd></div>
                        {t.is_post_deadline && (
                            <div className="col-span-2 rounded-lg bg-[#FCEBC4] px-3 py-2 text-[#5F4300] sm:col-span-4">
                                <p className="text-xs font-semibold">Post-deadline override</p>
                                <p className="whitespace-pre-line">{t.override_reason}</p>
                                {t.override_document && <p className="text-xs">Document: {t.override_document.title}</p>}
                            </div>
                        )}
                        {t.status === 'approved' && <div><dt className="text-xs text-[#718096]">Approved</dt><dd>{t.approved_by?.name ?? '—'} · {localDate(t.approved_at)}</dd></div>}
                    </dl>
                    {t.rejection_reason && <p className="mx-5 mb-4 rounded-lg bg-[#F9DEDC] px-3 py-2 text-sm text-[#8C1D18]">Rejected: {t.rejection_reason}</p>}
                </Card>
            )}
            {t && approval !== undefined && <ApprovalTrail process="finance.contract_termination" id={t.id} version={t.status} />}
            {history.length > 0 && (
                <Card title="Termination history">
                    <Table head={['Request', 'Status', 'Requested', 'Reason']}>
                        {history.map((x) => (
                            <tr key={x.id}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{x.termination_code}</td>
                                <td className="px-4 py-2"><Chip size="xs" tone={TONES[x.status] || 'neutral'}>{humanize(x.status)}</Chip></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(x.requested_at)}</td>
                                <td className="px-4 py-2 text-xs">{x.rejection_reason ?? x.reason}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.noteRequired} errors={errors ?? {}} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title.split(' ')[0]} />}
        </div>
    );
}
