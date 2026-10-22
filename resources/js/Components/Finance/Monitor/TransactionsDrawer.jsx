import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { Empty, Table } from '@/Components/Finance/Settings/ui';
import { humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Side drawer listing the actuals and open commitments behind one budget line (fetches finance.monitor.transactions). */
export default function TransactionsDrawer({ line, params = {}, baseCurrency, money, onClose }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => {
        if (!line) return undefined;
        setData(null); setError(null);
        let live = true;
        window.axios.get(route('finance.monitor.transactions', { line: line.line_id }), { params })
            .then((r) => live && setData(r.data)).catch(() => live && setError('Could not load the transactions.'));
        const esc = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', esc);
        return () => { live = false; window.removeEventListener('keydown', esc); };
    }, [line?.line_id, params.from_period_id, params.to_period_id]); // eslint-disable-line react-hooks/exhaustive-deps
    if (!line) return null;
    const fmt = (v) => moneyOrMask(v, baseCurrency, money);
    return (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Transactions">
            <button type="button" aria-label="Close" onClick={onClose} className="absolute inset-0 bg-black/30" />
            <div className="relative flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-white shadow-xl">
                <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div className="min-w-0">
                        <h3 className="truncate text-base font-semibold text-[#0A1F44]">{data?.line?.label ?? line.label}</h3>
                        <p className="text-xs text-[#718096]">Transactions behind this line, in {baseCurrency}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close drawer" className="text-[#718096]"><Icon name="close" size={22} /></button>
                </div>
                {error && <p className="m-5 rounded-lg bg-[#F9DEDC] px-3 py-2 text-sm text-[#8C1D18]">{error}</p>}
                {!data && !error && <p className="px-5 py-10 text-center text-sm text-[#718096]">Loading…</p>}
                {data && (
                    <div className="space-y-5 py-4">
                        <div className="grid grid-cols-2 gap-3 px-5">
                            <div className="rounded-lg border border-gray-200 p-3"><p className="text-xs text-[#718096]">Actual</p><p className="text-lg font-semibold tabular-nums">{fmt(data.totals.actual)}</p></div>
                            <div className="rounded-lg border border-gray-200 p-3"><p className="text-xs text-[#718096]">Open commitments</p><p className="text-lg font-semibold tabular-nums">{fmt(data.totals.committed)}</p></div>
                        </div>
                        <section>
                            <h4 className="px-5 pb-1 text-sm font-semibold text-[#0A1F44]">Actuals</h4>
                            <Table head={['Posted', 'Period', 'Source', 'Description', 'Amount']} empty={data.actuals.length === 0 && <Empty>No actuals in this range.</Empty>}>
                                {data.actuals.map((a) => (
                                    <tr key={a.id}>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(a.posted_on)}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{a.period}</td>
                                        <td className="px-4 py-2"><Chip size="xs" tone="neutral">{humanize(a.source)}</Chip></td>
                                        <td className="px-4 py-2 text-xs">{a.href ? <Link href={a.href} className="text-[#0A1F44] underline">{a.description || a.source_ref || 'Open'}</Link> : (a.description || a.source_ref)}{a.vendor && <span className="block text-[#718096]">{a.vendor}</span>}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(a.base_amount)}</td>
                                    </tr>
                                ))}
                            </Table>
                        </section>
                        <section>
                            <h4 className="px-5 pb-1 text-sm font-semibold text-[#0A1F44]">Open commitments</h4>
                            <Table head={['Type', 'Document', 'Remaining']} empty={data.commitments.length === 0 && <Empty>No open commitments.</Empty>}>
                                {data.commitments.map((c, i) => (
                                    <tr key={i}>
                                        <td className="px-4 py-2 text-xs">{c.type === 'po' ? 'Purchase order' : 'Expense request'}</td>
                                        <td className="px-4 py-2 text-xs">{c.href ? <Link href={c.href} className="text-[#0A1F44] underline">{c.code}</Link> : c.code}<span className="block text-[#718096]">{c.title}</span></td>
                                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(c.remaining_base)}</td>
                                    </tr>
                                ))}
                            </Table>
                        </section>
                    </div>
                )}
            </div>
        </div>
    );
}
