import Chip from '@/Components/Material/Chip';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import RecommendationForm from './RecommendationForm';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, DECISION_LABELS, RENEWAL_TONES, formatPct, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Link, router } from '@inertiajs/react';

export function RenewalFacts({ renewal, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const rows = [
        ['Recommendation', renewal.recommendation ? DECISION_LABELS[renewal.recommendation] ?? humanize(renewal.recommendation) : 'Not set'],
        ['Proposed term', renewal.proposed_term_months ? `${renewal.proposed_term_months} months` : '—'],
        ['Commences', localDate(renewal.proposed_commences_on)],
        ['Proposed value', moneyOrMask(renewal.proposed_value, renewal.proposed_currency, money)],
        [`Proposed (${baseCurrency})`, moneyOrMask(renewal.proposed_base_value, baseCurrency, money)],
        ['Uplift', money ? formatPct(renewal.uplift_pct, 1) : '•••'],
        ['Requested by', renewal.requested_by?.name ?? '—'],
        ['Submitted', localDate(renewal.submitted_at)],
    ];
    return (
        <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-4">
            {rows.map(([k, v]) => <div key={k}><dt className="text-xs text-[#718096]">{k}</dt><dd className="text-[#2D3748]">{v}</dd></div>)}
            {renewal.justification && <div className="col-span-2 sm:col-span-4"><dt className="text-xs text-[#718096]">Justification</dt><dd className="whitespace-pre-line text-[#2D3748]">{renewal.justification}</dd></div>}
        </dl>
    );
}

/** The Renewal tab on a contract: current renewal, inline recommendation while open, submit / withdraw, trail and history. */
export default function RenewalPanel({ contract, renewal, renewals, approval, options, currencies, baseCurrency, can, errors }) {
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, renewal.id), {}, { preserveScroll: true });
    const open = () => router.post(route('finance.renewals.open', contract.id), {}, { preserveScroll: true });
    const active = renewal && ['open', 'submitted', 'approved'].includes(renewal.status);
    const history = renewals.filter((r) => !renewal || r.id !== renewal.id);

    return (
        <div className="space-y-4">
            {!renewal && (
                <Card title="Renewal"><Empty>No renewal has been opened for this contract.</Empty>
                    {can.openRenewal && <div className="flex justify-center pb-6"><button type="button" onClick={open} className={BTN_PRIMARY}>Open renewal</button></div>}</Card>
            )}
            {renewal && (
                <Card title={<span className="flex items-center gap-2"><Link className="underline" href={route('finance.renewals.show', renewal.id)}>{renewal.renewal_code}</Link><Chip size="xs" tone={RENEWAL_TONES[renewal.status] || 'neutral'}>{humanize(renewal.status)}</Chip>{renewal.is_auto && <Chip size="xs" tone="neutral" icon="autorenew">Auto</Chip>}</span>}
                    right={<>
                        {renewal.status === 'open' && can.edit && renewal.recommendation && renewal.justification && <button type="button" onClick={post('finance.renewals.submit', 'Submit this recommendation for approval?')} className={BTN_PRIMARY}>Submit for approval</button>}
                        {['open', 'submitted'].includes(renewal.status) && can.edit && <button type="button" onClick={post('finance.renewals.withdraw', 'Withdraw this renewal?')} className={BTN_SECONDARY}>Withdraw</button>}
                        <Link href={route('finance.renewals.show', renewal.id)} className={BTN_SECONDARY}>Open full page</Link>
                    </>}>
                    {errors?.approval && <p className="px-5 pt-3 text-sm text-[#B3261E]">{errors.approval}</p>}
                    <RenewalFacts renewal={renewal} baseCurrency={baseCurrency} can={can} />
                    {renewal.rejection_reason && <p className="mx-5 mb-4 rounded-lg bg-[#F9DEDC] px-3 py-2 text-sm text-[#8C1D18]">Rejected: {renewal.rejection_reason}</p>}
                    {renewal.new_contract && <p className="mx-5 mb-4 text-sm">Outcome: <Link className="text-[#0A1F44] underline" href={route('finance.contracts.show', renewal.new_contract.id)}>{renewal.new_contract.contract_code} {renewal.new_contract.title}</Link></p>}
                </Card>
            )}
            {renewal?.status === 'open' && can.edit && <RecommendationForm key={renewal.id} renewal={renewal} contract={contract} options={options} currencies={currencies} baseCurrency={baseCurrency} can={can} />}
            {renewal && !active && can.openRenewal && <div><button type="button" onClick={open} className={BTN_SECONDARY}>Open a new renewal</button></div>}
            {renewal && approval && <ApprovalTrail process="finance.contract_renewal" id={renewal.id} version={renewal.status} />}
            {history.length > 0 && (
                <Card title="Renewal history">
                    <Table head={['Renewal', 'Status', 'Recommendation', 'Proposed (base)', 'Uplift', 'Opened']}>
                        {history.map((r) => (
                            <tr key={r.id}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.renewals.show', r.id)}>{r.renewal_code}</Link></td>
                                <td className="px-4 py-2"><Chip size="xs" tone={RENEWAL_TONES[r.status] || 'neutral'}>{humanize(r.status)}</Chip></td>
                                <td className="px-4 py-2 text-xs">{r.recommendation ? DECISION_LABELS[r.recommendation] ?? humanize(r.recommendation) : '—'}</td>
                                <td className="px-4 py-2 text-right text-xs tabular-nums">{moneyOrMask(r.proposed_base_value, baseCurrency, can.viewAmounts !== false, { decimals: 0 })}</td>
                                <td className="px-4 py-2 text-right text-xs">{can.viewAmounts !== false ? formatPct(r.uplift_pct, 1) : '•••'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(r.opened_on)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </div>
    );
}
