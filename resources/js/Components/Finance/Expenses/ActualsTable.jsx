import Chip from '@/Components/Material/Chip';
import Amount from './Amount';
import StatusChip from './StatusChip';
import { Empty, Table } from '@/Components/Finance/Settings/ui';
import { humanize, localDate } from '@/Utils/finance';
import { Link, router } from '@inertiajs/react';

/** Posted-actuals ledger rows; `onAction(row, 'match'|'unbudgeted')` is offered for reconcilable rows. */
export default function ActualsTable({ actuals, baseCurrency, money, canMatch, onAction, showReason }) {
    const unmatch = (a) => confirm('Return this actual to the unmatched queue?') && router.post(route('finance.actuals.unmatch', a.id), {}, { preserveScroll: true });
    return (
        <Table head={['Posted', 'Period', 'Source', 'Description', 'Cost centre', 'GL', 'Budget line', 'Amount', 'Status', ...(canMatch ? [''] : [])]} empty={actuals.length === 0 && <Empty>No actuals match these filters.</Empty>}>
            {actuals.map((a) => (
                <tr key={a.id} className="align-top">
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(a.posted_on)}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{a.period?.name}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(a.source)}{a.source_ref && <span className="block font-mono text-[11px] text-[#718096]">{a.source_ref}</span>}</td>
                    <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{a.description}
                        <div className="mt-0.5 flex flex-wrap gap-1">
                            {a.match_reason === 'possible_duplicate' && a.match_status !== 'duplicate' && <Chip size="xs" tone="error">Possible duplicate</Chip>}
                            {a.is_capex && <Chip size="xs" tone="info">Capex</Chip>}{a.is_lease && <Chip size="xs" tone="tertiary">Lease</Chip>}
                            {a.invoice && <Link className="text-xs text-[#0A1F44] underline" href={route('finance.invoices.show', a.invoice.id)}>{a.invoice.invoice_code}</Link>}
                        </div>
                        {showReason && a.reason && <span className="block text-xs text-[#718096]">{a.reason}</span>}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{a.cost_centre?.code ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{a.gl_account?.code ?? '—'}</td>
                    <td className="min-w-[8rem] px-4 py-2 text-xs">{a.budget_line ? <Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: a.budget_line.budget_id, tab: 'lines' })}>{a.budget_line.budget_code} #{a.budget_line.line_no}</Link> : '—'}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-right text-xs font-medium"><Amount value={a.amount} currency={a.currency} base={a.base_amount} baseCurrency={baseCurrency} money={money} /></td>
                    <td className="px-4 py-2"><StatusChip kind="actual" status={a.match_status} /></td>
                    {canMatch && (
                        <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                            {a.match_reason === 'possible_duplicate' && a.match_status !== 'duplicate' && onAction && <><button type="button" onClick={() => onAction(a, 'confirm-duplicate')} className="text-[#B3261E] underline">Confirm duplicate</button><button type="button" onClick={() => onAction(a, 'clear-duplicate')} className="ml-3 text-[#0A1F44] underline">Not a duplicate</button></>}
                            {a.match_reason !== 'possible_duplicate' && ['unmatched', 'unbudgeted'].includes(a.match_status) && onAction && <><button type="button" onClick={() => onAction(a, 'match')} className="text-[#0A1F44] underline">Match</button>{a.match_status === 'unmatched' && <button type="button" onClick={() => onAction(a, 'unbudgeted')} className="ml-3 text-[#8A6400] underline">Unbudgeted</button>}</>}
                            {a.match_status === 'matched' && a.source === 'gl_import' && <button type="button" onClick={() => unmatch(a)} className="text-[#B3261E] underline">Unmatch</button>}
                        </td>
                    )}
                </tr>
            ))}
        </Table>
    );
}
