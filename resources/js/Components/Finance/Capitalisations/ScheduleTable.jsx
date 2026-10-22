import Chip from '@/Components/Material/Chip';
import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { finMoney, humanize, localDate } from '@/Utils/finance';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/** Amortisation schedule (memo, not posted to actuals) with a by-year chart. */
export default function ScheduleTable({ schedule, byYear = [], baseCurrency, money, status }) {
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const full = (v) => new Intl.NumberFormat(undefined, { style: 'currency', currency: baseCurrency, maximumFractionDigits: 0 }).format(v);
    return (
        <Card title="Amortisation schedule" right={<span className="text-xs text-[#718096]">Memo schedule: depreciation is not IT cash spend</span>}>
            {schedule.length === 0 ? <Empty>{status === 'capitalised' ? 'No schedule lines.' : 'The schedule is created when the capitalisation is approved.'}</Empty> : (
                <>
                    {money && byYear.length > 0 && (
                        <div className="h-44 px-4 pt-4" role="img" aria-label="Amortisation by year">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={byYear.map((y) => ({ year: String(y.year), amount: num(y.amount) }))} margin={{ left: 4, right: 8 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                    <XAxis dataKey="year" tick={{ fontSize: 11 }} />
                                    <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={64} />
                                    <Tooltip formatter={full} />
                                    <Bar dataKey="amount" name="Amortisation" fill="#0A1F44" />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                    <div className="max-h-96 overflow-y-auto">
                        <Table head={['#', 'Month', 'Period', 'Opening NBV', 'Amortisation', 'Closing NBV']}>
                            {schedule.map((l) => (
                                <tr key={l.line_no}>
                                    <td className="px-4 py-1.5 text-xs text-[#718096]">{l.line_no}</td>
                                    <td className="whitespace-nowrap px-4 py-1.5 text-xs">{localDate(l.month, { month: 'short', year: 'numeric' })}{l.is_catch_up && <Chip size="xs" tone="warning" className="ml-1">Catch-up</Chip>}</td>
                                    <td className="whitespace-nowrap px-4 py-1.5 text-xs text-[#718096]">{l.period ? `${l.period.name} (${humanize(l.period.status)})` : 'Future'}</td>
                                    <td className="whitespace-nowrap px-4 py-1.5 text-right tabular-nums">{fmt(l.opening_nbv)}</td>
                                    <td className="whitespace-nowrap px-4 py-1.5 text-right tabular-nums">{fmt(l.amount)}</td>
                                    <td className="whitespace-nowrap px-4 py-1.5 text-right tabular-nums">{fmt(l.closing_nbv)}</td>
                                </tr>
                            ))}
                        </Table>
                    </div>
                </>
            )}
        </Card>
    );
}
