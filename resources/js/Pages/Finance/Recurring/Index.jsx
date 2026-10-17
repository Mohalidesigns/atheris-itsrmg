import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Amount from '@/Components/Finance/Expenses/Amount';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import RecurringFormModal from '@/Components/Finance/Expenses/RecurringFormModal';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, daysLabel, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const optList = (l) => (l ?? []).map((o) => (typeof o === 'string' ? { value: o, label: humanize(o) } : o));

export default function Index(props) {
    const { filters, items, summary, options, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const { f, setF, apply, set } = useFilters('finance.recurring.index', filters);
    const [editing, setEditing] = useState(undefined);
    const money = can.viewAmounts !== false;
    const generate = (r) => confirm(`Generate the next ${r.auto_generate === 'accrual' ? 'accrual' : 'expected invoice'} for ${r.recurring_code} now?`) && router.post(route('finance.recurring.generate', r.id), {}, { preserveScroll: true });
    const remove = (r) => confirm(`Delete ${r.recurring_code}?`) && router.delete(route('finance.recurring.destroy', r.id), { preserveScroll: true });
    return (
        <AuthenticatedLayout header="Recurring Expenses">
            <Head title="Recurring Expenses" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Recurring Expenses' }]} title="Recurring Expenses" subtitle="Schedules that generate expected invoices or accruals each cycle"
                actions={can.create && <button type="button" onClick={() => setEditing(null)} className={BTN_PRIMARY}>New schedule</button>} />
            <ExpensesNav active="recurring" />
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="Active schedules" value={summary.active} icon="event_repeat" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => apply({ ...f, active: '1' })} />
                <StatCard label="Due in 30 days" value={summary.due_30} icon="event_upcoming" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => apply({ ...f, due: f.due === 30 || f.due === '30' ? null : 30 })} />
                <StatCard label="Annual run rate" value={moneyOrMask(summary.annual_run_rate_base, baseCurrency, money, { decimals: 0 })} sublabel={`Estimated, ${baseCurrency}`} icon="trending_up" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
            </div>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-4">
                <SearchFilter id="rc-q" value={f.q} setValue={(q) => setF({ ...f, q })} apply={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Code or title" />
                <SelectFilter id="rc-a" label="Active" value={f.active} onChange={set('active')}><option value="1">Active</option><option value="0">Inactive</option></SelectFilter>
                <SelectFilter id="rc-f" label="Frequency" value={f.frequency} onChange={set('frequency')}>{optList(options.frequencies).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="rc-d" label="Due within" value={f.due} onChange={set('due')}>{[7, 30, 90].map((n) => <option key={n} value={n}>{n} days</option>)}</SelectFilter>
            </div>
            <Card>
                <Table head={['Schedule', 'Title', 'Vendor', 'Frequency', 'Amount', 'Next due', 'Mode', 'Last generated', 'Recent', ...(can.edit || can.generate ? [''] : [])]} empty={items.length === 0 && <Empty>No recurring schedules match.</Empty>}>
                    {items.map((r) => (
                        <tr key={r.id} className="align-top">
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{r.recurring_code}{!r.is_active && <Chip size="xs" tone="muted" className="ml-1">Inactive</Chip>}</td>
                            <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{r.title}{r.contract && <Link className="block text-xs text-[#0A1F44] underline" href={route('finance.contracts.show', r.contract.id)}>{r.contract.contract_code}</Link>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.vendor?.name ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(r.frequency)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><Amount value={r.amount} currency={r.currency} base={r.base_amount_est} baseCurrency={baseCurrency} money={money} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(r.next_due_on)} <span className={r.days_to_due < 0 ? 'text-[#B3261E]' : 'text-[#718096]'}>{daysLabel(r.days_to_due)}</span></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(r.auto_generate)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(r.last_generated_on)}</td>
                            <td className="px-4 py-2 text-xs">{r.generated.map((g) => <Link key={`${g.type}${g.id}`} href={g.href} className="block text-[#0A1F44] underline">{g.code}</Link>)}</td>
                            {(can.edit || can.generate) && <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                                {can.generate && r.is_active && <button type="button" onClick={() => generate(r)} className="text-[#0A1F44] underline">Generate</button>}
                                {can.edit && <><button type="button" onClick={() => setEditing(r)} className="ml-3 text-[#0A1F44] underline">Edit</button><button type="button" onClick={() => remove(r)} className="ml-3 text-[#B3261E] underline">Delete</button></>}
                            </td>}
                        </tr>
                    ))}
                </Table>
            </Card>
            {editing !== undefined && <RecurringFormModal key={editing?.id ?? 'new'} item={editing} {...props} baseCurrency={baseCurrency} onClose={() => setEditing(undefined)} />}
        </AuthenticatedLayout>
    );
}
