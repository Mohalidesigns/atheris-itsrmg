import Chip from '@/Components/Material/Chip';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import RgtChip from '@/Components/Finance/Investments/RgtChip';
import StageChip from '@/Components/Finance/Investments/StageChip';
import { formatPct, humanize, invMoney } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

/** Ranked open investments with cumulative planned cost and a gold fund line where the envelope runs out. */
export default function PortfolioRanking({ ranking, envelope, baseCurrency, money }) {
    const m = (v) => invMoney(v, baseCurrency, money, { decimals: 0 });
    const firstBelow = ranking.findIndex((r) => !r.above_line);
    const cols = 9;
    return (
        <Card title="Priority ranking and fund line" right={<span className="text-xs text-[#718096]">Envelope {m(envelope.used)} · {envelope.source === 'manual' ? 'what-if' : 'change-the-bank baseline'}</span>}>
            <Table head={['Rank', 'Investment', 'Stage', 'RGT', 'Driver', 'Score', 'Planned cost', 'Cumulative', 'NPV / ROI']} empty={ranking.length === 0 && <Empty>No open investments are ranked for this fiscal year.</Empty>}>
                {ranking.map((r, i) => (
                    <FundRows key={r.id} line={i === firstBelow && i > 0} cols={cols}>
                        <tr className={r.above_line ? '' : 'bg-gray-50/70 text-[#718096]'}>
                            <td className="px-4 py-2 tabular-nums">{r.priority_rank ?? '—'}</td>
                            <td className="min-w-[12rem] px-4 py-2"><Link href={r.href} className="text-[#0A1F44] hover:underline"><span className="font-mono text-xs text-[#718096]">{r.investment_code}</span> {r.title}</Link>{r.is_regulatory && <> <Chip size="xs" tone="warning">Regulatory</Chip></>}</td>
                            <td className="px-4 py-2"><StageChip stage={r.stage} size="xs" /></td>
                            <td className="px-4 py-2"><RgtChip rgt={r.rgt} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(r.driver)}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{r.priority_score ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{m(r.planned_cost)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{m(r.cumulative_cost)}{!r.above_line && <span className="block text-[11px] text-[#B3261E]">Over envelope</span>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs tabular-nums">{m(r.npv)}<span className="block text-[#718096]">{money ? (r.roi_pct === null || r.roi_pct === undefined ? '—' : formatPct(r.roi_pct, 0)) : ''}</span></td>
                        </tr>
                    </FundRows>
                ))}
            </Table>
        </Card>
    );
}

/** Renders the fund-line separator row before a rank row when `line` is set. */
function FundRows({ line, cols, children }) {
    return (
        <>
            {line && (
                <tr aria-label="Fund line"><td colSpan={cols} className="border-y-2 border-[#C9A86A] bg-[#F7F1E6] px-4 py-1 text-center text-[11px] font-semibold uppercase tracking-wide text-[#5E4818]">Fund line: above is funded within the envelope</td></tr>
            )}
            {children}
        </>
    );
}
