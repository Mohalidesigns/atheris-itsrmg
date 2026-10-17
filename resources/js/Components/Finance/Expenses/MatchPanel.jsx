import Chip from '@/Components/Material/Chip';
import Amount from './Amount';
import StatusChip from './StatusChip';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { humanize, localDate } from '@/Utils/finance';

const q = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 });

/** Three-way match result: totals, tolerance, per-line ordered / received / invoiced and flags, plus any override. */
export default function MatchPanel({ invoice, baseCurrency, money, actions }) {
    const m = invoice.match;
    return (
        <Card title={<span className="flex items-center gap-2">Three-way match <StatusChip kind="match" status={invoice.match_status} /></span>} right={actions}>
            {!m ? <Empty>This invoice has not been matched yet.{!invoice.po && ' Without a purchase order there is nothing to match against.'}</Empty> : (
                <>
                    <dl className="grid grid-cols-2 gap-4 px-5 py-4 text-sm sm:grid-cols-5">
                        <div><dt className="text-xs text-[#718096]">Expected</dt><dd className="font-medium"><Amount value={m.expected} currency={invoice.currency} money={money} secondary={false} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Invoiced</dt><dd className="font-medium"><Amount value={m.invoiced} currency={invoice.currency} money={money} secondary={false} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Variance</dt><dd className={`font-medium ${Number(m.variance) !== 0 ? 'text-[#B3261E]' : ''}`}><Amount value={m.variance} currency={invoice.currency} money={money} secondary={false} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Variance (base)</dt><dd className="font-medium"><Amount value={m.variance_base} currency={baseCurrency} money={money} /></dd></div>
                        <div><dt className="text-xs text-[#718096]">Tolerance</dt><dd className="text-xs">{m.tolerance ? `${Number(m.tolerance.pct)}% or ${money ? Number(m.tolerance.abs).toLocaleString() : '•••'}` : '—'}{m.limit !== undefined && m.limit !== null && money ? <span className="block text-[#718096]">limit {Number(m.limit).toLocaleString()}</span> : null}</dd></div>
                    </dl>
                    <Table head={['Line', 'Ordered', 'Received', 'Previously invoiced', 'This invoice', 'PO unit cost', 'Invoice unit cost', 'Variance', 'Flags']} empty={m.lines.length === 0 && <Empty>No lines.</Empty>}>
                        {m.lines.map((l, n) => (
                            <tr key={n}>
                                <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{l.description}</td>
                                <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.ordered_qty)}</td>
                                <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.received_qty)}</td>
                                <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.prior_invoiced_qty)}</td>
                                <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.invoiced_qty)}</td>
                                <td className="px-4 py-2 text-right text-xs"><Amount value={l.po_unit_cost} currency={invoice.currency} money={money} secondary={false} /></td>
                                <td className="px-4 py-2 text-right text-xs"><Amount value={l.invoice_unit_cost} currency={invoice.currency} money={money} secondary={false} /></td>
                                <td className={`px-4 py-2 text-right text-xs ${Number(l.variance) !== 0 ? 'text-[#B3261E]' : ''}`}><Amount value={l.variance} currency={invoice.currency} money={money} secondary={false} /></td>
                                <td className="px-4 py-2"><div className="flex flex-wrap gap-1">{(l.flags ?? []).map((f) => <Chip key={f} size="xs" tone="warning">{humanize(f)}</Chip>)}</div></td>
                            </tr>
                        ))}
                    </Table>
                    {m.override && <p className="mx-5 my-3 rounded-lg bg-[#F3E9D2] px-3 py-2 text-sm text-[#5E4818]">Variance overridden by {m.override.by?.name} on {localDate(m.override.at)}: {m.override.reason}</p>}
                </>
            )}
        </Card>
    );
}
