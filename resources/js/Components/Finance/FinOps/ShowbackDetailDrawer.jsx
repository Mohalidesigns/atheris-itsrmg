import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { Empty, Table } from '@/Components/Finance/Settings/ui';
import { finMoney, humanize } from '@/Utils/finance';
import { useEffect } from 'react';

/** Side drawer: the rules and direct items behind one target. `detail` arrives through an Inertia partial reload. */
export default function ShowbackDetailDrawer({ open, label, detail, onClose, baseCurrency, money }) {
    useEffect(() => {
        if (!open) return undefined;
        const esc = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
    if (!open) return null;
    const ready = detail && detail.target_key === open;
    return (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Showback detail">
            <button type="button" aria-label="Close" onClick={onClose} className="absolute inset-0 bg-black/30" />
            <div className="relative flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-white shadow-xl">
                <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div className="min-w-0"><h3 className="truncate text-base font-semibold text-[#0A1F44]">{detail?.label ?? label}</h3><p className="text-xs text-[#718096]">Rules and direct items behind this figure, in {baseCurrency}</p></div>
                    <button type="button" onClick={onClose} aria-label="Close drawer" className="text-[#718096]"><Icon name="close" size={22} /></button>
                </div>
                {!ready && <p className="px-5 py-10 text-center text-sm text-[#718096]">Loading…</p>}
                {ready && (
                    <Table head={['Kind', 'Source', 'Period', 'Cost pool', 'Amount']} empty={detail.items.length === 0 && <Empty>Nothing behind this target.</Empty>}>
                        {detail.items.map((it, i) => (
                            <tr key={i}>
                                <td className="px-4 py-2 text-xs">{it.kind === 'rule' ? <Chip size="xs" tone="primary">{it.rule_code} {humanize(it.method ?? '')}</Chip> : <Chip size="xs" tone="tertiary">Direct</Chip>}</td>
                                <td className="min-w-[8rem] px-4 py-2 text-xs text-[#2D3748]">{it.source_label}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{it.period}</td>
                                <td className="px-4 py-2 text-xs text-[#4A5568]">{it.cost_pool ?? 'Unclassified'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{finMoney(it.amount, baseCurrency, money, { decimals: 0 })}</td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </div>
    );
}
