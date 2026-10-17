import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import Amount from '@/Components/Finance/Expenses/Amount';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import Field from '@/Components/Finance/Expenses/Field';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { BTN_SECONDARY, FIELD, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index(props) {
    const { filters, accruals, summary, periods, fiscalYears, budgetLines, costCentres, glAccounts, currencies, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const { f, set } = useFilters('finance.accruals.index', filters);
    const money = can.viewAmounts !== false;
    const [show, setShow] = useState(false);
    const [period, setPeriod] = useState(filters.period_id ?? periods.find((p) => p.status === 'open')?.id ?? '');
    const form = useForm({ period_id: '', cost_centre_id: '', budget_line_id: '', gl_account_id: '', description: '', currency: baseCurrency, amount: '' });
    const e = form.errors;
    const periodAction = (name, text) => () => period && confirm(text) && router.post(route(name), { period_id: period }, { preserveScroll: true });
    const row = (name, a, text) => () => confirm(text) && router.post(route(name, a.id), {}, { preserveScroll: true });
    const submit = () => {
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('finance.accruals.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } });
    };
    return (
        <AuthenticatedLayout header="Accruals">
            <Head title="Accruals" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Accruals' }]} title="Accruals" subtitle="Period-end accruals for received-not-invoiced goods and prepayment releases" />
            <ExpensesNav active="accruals" />
            <div className="mb-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="Proposed" value={moneyOrMask(summary.proposed_base, baseCurrency, money, { decimals: 0 })} sublabel="Awaiting posting" icon="pending" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => set('status')({ target: { value: 'proposed' } })} />
                <StatCard label="Posted" value={moneyOrMask(summary.posted_base, baseCurrency, money, { decimals: 0 })} icon="task_alt" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => set('status')({ target: { value: 'posted' } })} />
                <StatCard label="Reversed" value={moneyOrMask(summary.reversed_base, baseCurrency, money, { decimals: 0 })} icon="undo" tint="bg-[#F1F2F4] text-[#43474E]" />
                <StatCard label="Prepaid balance" value={moneyOrMask(summary.prepaid_balance_base, baseCurrency, money, { decimals: 0 })} sublabel="Still to release" icon="savings" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
            </div>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-4">
                <SelectFilter id="ac-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="ac-pe" label="Period" value={f.period_id} onChange={set('period_id')}>{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <SelectFilter id="ac-ty" label="Type" value={f.type} onChange={set('type')}>{['accrual', 'prepayment_release'].map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</SelectFilter>
                <SelectFilter id="ac-st" label="Status" value={f.status} onChange={set('status')}>{['proposed', 'posted', 'reversed', 'cancelled'].map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</SelectFilter>
            </div>
            {can.manage && (
                <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
                    <div><label className="block text-xs font-medium text-[#4A5568]" htmlFor="ac-run">Period to run</label><select id="ac-run" value={period} onChange={(ev) => setPeriod(ev.target.value)} className={FIELD}><option value="">Select…</option>{periods.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.status})</option>)}</select></div>
                    <button type="button" disabled={!period} onClick={periodAction('finance.accruals.propose', 'Propose accruals for received-not-invoiced goods in this period?')} className={BTN_SECONDARY}>Propose accruals</button>
                    <button type="button" disabled={!period} onClick={periodAction('finance.accruals.release-prepayments', 'Post the due prepayment releases to this period?')} className={BTN_SECONDARY}>Release prepayments</button>
                    <div className="ml-auto"><AddButton onClick={() => setShow(true)}>Manual accrual</AddButton></div>
                    {errors?.period && <p role="alert" className="w-full text-sm text-[#B3261E]">{errors.period}</p>}
                </div>
            )}
            <Card>
                <Table head={['Period', 'Type', 'Source', 'Description', 'Cost centre', 'Amount', 'Status', 'Reverses in', 'Posted', ...(can.manage ? [''] : [])]} empty={accruals.length === 0 && <Empty>No accruals for this selection.</Empty>}>
                    {accruals.map((a) => (
                        <tr key={a.id}>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{a.period?.name}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(a.type)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(a.source)}{a.po && <Link className="block text-[#0A1F44] underline" href={route('finance.purchase-orders.show', a.po.id)}>{a.po.po_code}</Link>}{a.invoice && <Link className="block text-[#0A1F44] underline" href={route('finance.invoices.show', a.invoice.id)}>{a.invoice.invoice_code}</Link>}</td>
                            <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{a.description}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{a.cost_centre?.code}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><Amount value={a.amount} currency={a.currency} base={a.base_amount} baseCurrency={baseCurrency} money={money} /></td>
                            <td className="px-4 py-2"><StatusChip kind="accrual" status={a.status} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{a.reversal_period?.name ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(a.posted_at)}</td>
                            {can.manage && <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                                {a.status === 'proposed' && <><button type="button" onClick={row('finance.accruals.post', a, 'Post this accrual?')} className="text-[#0A1F44] underline">Post</button><button type="button" onClick={row('finance.accruals.cancel', a, 'Cancel this accrual?')} className="ml-3 text-[#B3261E] underline">Cancel</button></>}
                                {a.status === 'posted' && a.type === 'accrual' && <button type="button" onClick={row('finance.accruals.reverse', a, 'Reverse this accrual?')} className="text-[#0A1F44] underline">Reverse</button>}
                            </td>}
                        </tr>
                    ))}
                </Table>
            </Card>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.description || !form.data.amount || !form.data.period_id || !form.data.cost_centre_id} title="Manual accrual" submitLabel="Propose">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field id="ma-p" label="Period" error={e.period_id}><select id="ma-p" value={form.data.period_id} onChange={(ev) => form.setData('period_id', ev.target.value)} className={FIELD}><option value="">Select…</option>{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                    <Field id="ma-c" label="Cost centre" error={e.cost_centre_id}><select id="ma-c" value={form.data.cost_centre_id} onChange={(ev) => form.setData('cost_centre_id', ev.target.value)} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select></Field>
                    <Field id="ma-b" label="Budget line" error={e.budget_line_id}><select id="ma-b" value={form.data.budget_line_id} onChange={(ev) => form.setData('budget_line_id', ev.target.value)} className={FIELD}><option value="">None</option>{budgetLines.map((l) => <option key={l.id} value={l.id}>{l.budget_code} #{l.line_no} {l.label}</option>)}</select></Field>
                    <Field id="ma-g" label="GL account" error={e.gl_account_id}><select id="ma-g" value={form.data.gl_account_id} onChange={(ev) => form.setData('gl_account_id', ev.target.value)} className={FIELD}><option value="">None</option>{glAccounts.map((g) => <option key={g.id} value={g.id}>{g.code} {g.name}</option>)}</select></Field>
                    <Field id="ma-d" label="Description" error={e.description} className="sm:col-span-2"><input id="ma-d" maxLength={255} value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} className={FIELD} /></Field>
                    <Field id="ma-cu" label="Currency" error={e.currency}><select id="ma-cu" value={form.data.currency} onChange={(ev) => form.setData('currency', ev.target.value)} className={FIELD}>{currencies.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}</select></Field>
                    <Field id="ma-a" label="Amount" error={e.amount}><input id="ma-a" type="number" min="0" step="0.01" value={form.data.amount} onChange={(ev) => form.setData('amount', ev.target.value)} className={FIELD} /></Field>
                </div>
                <InputError message={e.accrual} />
            </FormModal>
        </AuthenticatedLayout>
    );
}
