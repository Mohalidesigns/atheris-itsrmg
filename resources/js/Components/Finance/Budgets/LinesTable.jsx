import Chip from '@/Components/Material/Chip';
import { ExclamationTriangleIcon } from '@/Components/Material/Symbols';
import { Empty, RowActions, Table } from '@/Components/Finance/Settings/ui';
import Money from './Money';
import { PRIORITY_TONES, RGT_LABELS, formatPct, humanize } from '@/Utils/finance';

export default function LinesTable({ lines, totals, baseCurrency, can, isBaseline, onEdit, onDelete }) {
    const head = ['#', 'Description', 'Pool / tower', 'Priority', 'RGT', 'Type', 'Annual', 'Base', 'PY budget', 'YoY', '', ...(isBaseline ? ['Adjustments', 'Current'] : []), ...(can.edit ? [''] : [])];
    return (
        <Table head={head} empty={lines.length === 0 && <Empty>No lines yet.</Empty>}>
            {lines.map((l) => (
                <tr key={l.id} className={l.over_ceiling ? 'bg-[#FDF4F3]' : ''}>
                    <td className="px-4 py-2 font-mono text-xs text-[#4A5568]">{l.line_no}</td>
                    <td className="min-w-[14rem] px-4 py-2 text-[#2D3748]">{l.description}{l.origin !== 'planned' && <Chip size="xs" tone="info" className="ml-2">{humanize(l.origin)}</Chip>}</td>
                    <td className="px-4 py-2 text-xs text-[#4A5568]">{l.labels.cost_pool}<span className="block text-[#718096]">{l.labels.tower}</span></td>
                    <td className="px-4 py-2"><Chip size="xs" tone={PRIORITY_TONES[l.priority] || 'neutral'}>{humanize(l.priority)}</Chip></td>
                    <td className="px-4 py-2 text-xs">{RGT_LABELS[l.rgt] || l.rgt}</td>
                    <td className="px-4 py-2 text-xs">{l.is_capex ? 'CapEx' : 'OpEx'}</td>
                    <td className="px-4 py-2 text-right"><Money value={l.annual_amount} currency={l.currency} base={l.base_annual_amount} baseCurrency={baseCurrency} stacked /></td>
                    <td className="px-4 py-2 text-right"><Money value={l.base_annual_amount} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                    <td className="px-4 py-2 text-right"><Money value={l.py_budget} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                    <td className="px-4 py-2 text-right text-xs">{formatPct(l.yoy_pct, 1)}</td>
                    <td className="px-2 py-2">{l.over_ceiling && <span title="Over ceiling"><ExclamationTriangleIcon className="h-4 w-4 text-[#B3261E]" aria-label="Over ceiling" /></span>}</td>
                    {isBaseline && <><td className="px-4 py-2 text-right"><Money value={l.adjustments_base} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                        <td className="px-4 py-2 text-right"><Money value={l.current_base_amount} currency={baseCurrency} baseCurrency={baseCurrency} /></td></>}
                    {can.edit && <td className="px-4 py-2"><RowActions onEdit={() => onEdit(l)} onDelete={() => onDelete(l)} /></td>}
                </tr>
            ))}
            {lines.length > 0 && (
                <tr className="bg-gray-50 font-semibold text-[#0A1F44]">
                    <td className="px-4 py-2" colSpan={7}>Total (base)</td>
                    <td className="px-4 py-2 text-right"><Money value={totals.base} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                    <td className="px-4 py-2 text-right"><Money value={totals.py_budget} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                    <td className="px-4 py-2 text-right text-xs">{formatPct(totals.yoy_pct, 1)}</td>
                    <td />
                    {isBaseline && <><td className="px-4 py-2 text-right"><Money value={totals.adjustments_base} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                        <td className="px-4 py-2 text-right"><Money value={totals.current_base} currency={baseCurrency} baseCurrency={baseCurrency} /></td></>}
                    {can.edit && <td />}
                </tr>
            )}
        </Table>
    );
}
