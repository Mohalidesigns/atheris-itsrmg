import InputError from '@/Components/InputError';
import Field from './Field';
import { Card, Check } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, formatMoney } from '@/Utils/finance';
import { Link, useForm } from '@inertiajs/react';

/**
 * Capture / edit one invoice. Choosing a PO loads its open lines (quantity pre-filled with what is received but not yet invoiced);
 * without a PO the invoice is a flat subtotal. WHT and VAT come from the tax lists.
 */
export default function InvoiceForm({ invoice, prefill, purchaseOrders, expenses, contracts, vendors, budgetLines, costCentres, categories, glAccounts, taxTypes, periods, currencies, defaults, baseCurrency, can }) {
    const i = invoice;
    const p = prefill ?? {};
    const form = useForm({
        vendor_invoice_no: i?.vendor_invoice_no ?? '', vendor_id: i?.vendor?.id ?? p.vendor_id ?? '', po_id: i?.po?.id ?? p.po_id ?? '', expense_request_id: i?.expense_request?.id ?? p.expense_request_id ?? '',
        contract_id: i?.contract?.id ?? p.contract_id ?? '', invoice_date: i?.invoice_date ?? p.invoice_date ?? new Date().toISOString().slice(0, 10), due_on: i?.due_on ?? '', received_on: '',
        currency: i?.currency ?? p.currency ?? defaults.currency, subtotal: i?.subtotal ?? p.subtotal ?? '', tax_type_id: i?.tax_type?.id ?? p.tax_type_id ?? '', wht_tax_type_id: i?.wht_tax_type?.id ?? '',
        budget_line_id: i?.budget_line?.id ?? p.budget_line_id ?? '', cost_centre_id: i?.cost_centre?.id ?? p.cost_centre_id ?? '', category_id: i?.category?.id ?? '', gl_account_id: i?.gl_account?.id ?? '',
        period_id: i?.period?.id ?? '', description: i?.description ?? '', prepayment_months: i?.prepayment_months ?? '', is_capex: false,
        lines: (i?.lines ?? p.lines ?? []).map((l) => ({ po_line_id: l.po_line_id ?? l.id ?? '', description: l.description ?? '', quantity: String(l.quantity ?? ''), unit_cost: String(l.unit_cost ?? '') })),
    });
    const d = form.data;
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value);
    const po = purchaseOrders.find((o) => String(o.id) === String(d.po_id));
    const withLines = d.lines.length > 0;
    const linesTotal = d.lines.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0);

    const pickPo = (ev) => {
        const o = purchaseOrders.find((x) => String(x.id) === ev.target.value);
        form.setData((cur) => ({ ...cur, po_id: ev.target.value, ...(o ? {
            vendor_id: o.vendor_id ?? cur.vendor_id, currency: o.currency, cost_centre_id: o.cost_centre_id ?? cur.cost_centre_id, budget_line_id: o.budget_line_id ?? cur.budget_line_id,
            lines: o.lines.filter((l) => Number(l.received_quantity) - Number(l.invoiced_quantity) > 0).map((l) => ({ po_line_id: l.id, description: l.description, quantity: String(Number(l.received_quantity) - Number(l.invoiced_quantity)), unit_cost: String(l.unit_cost) })),
        } : { lines: [] }) }));
    };
    const setLine = (n, k, v) => form.setData('lines', d.lines.map((l, j) => (j === n ? { ...l, [k]: v } : l)));
    const clean = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((cur) => ({ ...clean(cur), lines: cur.lines.map(clean), subtotal: cur.lines.length ? null : cur.subtotal || null }));
        if (i) form.put(route('finance.invoices.update', i.id));
        else form.post(route('finance.invoices.store'));
    };
    const opts = (list, fmt) => list.map((x) => <option key={x.id} value={x.id}>{fmt(x)}</option>);

    return (
        <form onSubmit={submit} className="space-y-4">
            <Card title="Invoice">
                <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field id="v-no" label="Vendor invoice number" error={e.vendor_invoice_no}><input id="v-no" maxLength={64} value={d.vendor_invoice_no} onChange={set('vendor_invoice_no')} className={FIELD} /></Field>
                    <Field id="v-po" label="Purchase order" error={e.po_id} hint="Lines are three-way matched against receipts."><select id="v-po" value={d.po_id} onChange={pickPo} className={FIELD}><option value="">None (no PO)</option>{opts(purchaseOrders, (o) => o.po_code)}</select></Field>
                    <Field id="v-exp" label="Approved expense request" error={e.expense_request_id}><select id="v-exp" value={d.expense_request_id} onChange={set('expense_request_id')} className={FIELD}><option value="">None</option>{opts(expenses, (x) => `${x.request_code} ${x.title}`)}</select></Field>
                    <Field id="v-vendor" label="Vendor" error={e.vendor_id}><select id="v-vendor" value={d.vendor_id} onChange={set('vendor_id')} className={FIELD}><option value="">Select…</option>{opts(vendors, (v) => v.name)}</select></Field>
                    <Field id="v-contract" label="Contract" error={e.contract_id}><select id="v-contract" value={d.contract_id} onChange={set('contract_id')} className={FIELD}><option value="">None</option>{opts(contracts, (c) => `${c.contract_code} ${c.title}`)}</select></Field>
                    <Field id="v-bl" label="Budget line" error={e.budget_line_id}><select id="v-bl" value={d.budget_line_id} onChange={set('budget_line_id')} className={FIELD}><option value="">None</option>{opts(budgetLines, (l) => `${l.budget_code} #${l.line_no} ${l.label}`)}</select></Field>
                    <Field id="v-date" label="Invoice date" error={e.invoice_date}><input id="v-date" type="date" value={d.invoice_date} onChange={set('invoice_date')} className={FIELD} /></Field>
                    <Field id="v-due" label="Due on" error={e.due_on} hint={`Blank = invoice date + ${defaults.payment_days} days`}><input id="v-due" type="date" value={d.due_on} onChange={set('due_on')} className={FIELD} /></Field>
                    <Field id="v-recv" label="Received on" error={e.received_on}><input id="v-recv" type="date" value={d.received_on} onChange={set('received_on')} className={FIELD} /></Field>
                    <Field id="v-cur" label="Currency" error={e.currency}><select id="v-cur" value={d.currency} onChange={set('currency')} className={FIELD}>{currencies.map((c) => <option key={c.code} value={c.code}>{c.code}{c.is_base ? ' (base)' : ''}</option>)}</select></Field>
                    {!withLines && <Field id="v-sub" label="Subtotal (before tax)" error={e.subtotal}><input id="v-sub" type="number" min="0" step="0.01" value={d.subtotal} onChange={set('subtotal')} className={FIELD} /></Field>}
                    <Field id="v-tax" label="VAT" error={e.tax_type_id}><select id="v-tax" value={d.tax_type_id} onChange={set('tax_type_id')} className={FIELD}><option value="">None</option>{opts(taxTypes.vat, (t) => `${t.code} (${Number(t.rate)}%)`)}</select></Field>
                    <Field id="v-wht" label="Withholding tax" error={e.wht_tax_type_id}><select id="v-wht" value={d.wht_tax_type_id} onChange={set('wht_tax_type_id')} className={FIELD}><option value="">None</option>{opts(taxTypes.wht, (t) => `${t.code} (${Number(t.rate)}%)`)}</select></Field>
                    <Field id="v-per" label="Period" error={e.period_id} hint="Blank = period of the invoice date"><select id="v-per" value={d.period_id} onChange={set('period_id')} className={FIELD}><option value="">Automatic</option>{opts(periods, (x) => x.name)}</select></Field>
                    <Field id="v-cc" label="Cost centre" error={e.cost_centre_id}><select id="v-cc" value={d.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">From budget line</option>{opts(costCentres, (c) => `${c.code} ${c.name}`)}</select></Field>
                    <Field id="v-cat" label="Category" error={e.category_id}><select id="v-cat" value={d.category_id} onChange={set('category_id')} className={FIELD}><option value="">None</option>{opts(categories, (c) => `${c.code} ${c.name}`)}</select></Field>
                    <Field id="v-gl" label="GL account" error={e.gl_account_id}><select id="v-gl" value={d.gl_account_id} onChange={set('gl_account_id')} className={FIELD}><option value="">None</option>{opts(glAccounts, (g) => `${g.code} ${g.name}`)}</select></Field>
                    <Field id="v-pre" label="Prepayment (months)" error={e.prepayment_months} hint="2 to 36 spreads the cost across periods"><input id="v-pre" type="number" min="2" max="36" value={d.prepayment_months} onChange={set('prepayment_months')} className={FIELD} /></Field>
                    <div className="flex items-end"><Check label="Capital expenditure" checked={d.is_capex} onChange={(v) => form.setData('is_capex', v)} /></div>
                    <Field id="v-desc" label="Description" error={e.description} className="sm:col-span-2 lg:col-span-3"><textarea id="v-desc" rows={2} value={d.description} onChange={set('description')} className={FIELD} /></Field>
                </div>
            </Card>
            {(withLines || po) && (
                <Card title="Invoice lines" right={po && <button type="button" onClick={() => form.setData('lines', [...d.lines, { po_line_id: '', description: '', quantity: '1', unit_cost: '' }])} className="rounded-lg border border-gray-200 px-3 py-1 text-xs">Add line</button>}>
                    <div className="space-y-2 px-5 py-4">
                        {d.lines.length === 0 && <p className="text-sm text-[#718096]">Nothing is received and uninvoiced on this order. Add a line to invoice anyway; the match will flag it.</p>}
                        {d.lines.map((l, n) => (
                            <div key={n} className="grid grid-cols-12 items-end gap-2">
                                <div className="col-span-12 sm:col-span-3"><label className="text-xs text-[#4A5568]" htmlFor={`il-p-${n}`}>PO line</label>
                                    <select id={`il-p-${n}`} value={l.po_line_id} onChange={(ev) => setLine(n, 'po_line_id', ev.target.value)} className={FIELD}><option value="">None</option>{(po?.lines ?? []).map((x) => <option key={x.id} value={x.id}>#{x.line_no} {x.description}</option>)}</select></div>
                                <div className="col-span-12 sm:col-span-4"><label className="text-xs text-[#4A5568]" htmlFor={`il-d-${n}`}>Description</label><input id={`il-d-${n}`} value={l.description} onChange={(ev) => setLine(n, 'description', ev.target.value)} className={FIELD} /></div>
                                <div className="col-span-4 sm:col-span-2"><label className="text-xs text-[#4A5568]" htmlFor={`il-q-${n}`}>Quantity</label><input id={`il-q-${n}`} type="number" min="0" step="any" value={l.quantity} onChange={(ev) => setLine(n, 'quantity', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${n}.quantity`]} /></div>
                                <div className="col-span-5 sm:col-span-2"><label className="text-xs text-[#4A5568]" htmlFor={`il-u-${n}`}>Unit cost</label><input id={`il-u-${n}`} type="number" min="0" step="any" value={l.unit_cost} onChange={(ev) => setLine(n, 'unit_cost', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${n}.unit_cost`]} /></div>
                                <div className="col-span-3 sm:col-span-1 pb-2 text-right"><button type="button" onClick={() => form.setData('lines', d.lines.filter((_, j) => j !== n))} className="text-xs text-[#B3261E] underline">Remove</button></div>
                            </div>
                        ))}
                        {withLines && <p className="text-xs text-[#718096]">Lines subtotal {formatMoney(linesTotal, d.currency)} before tax.</p>}
                        <InputError message={e.lines} />
                    </div>
                </Card>
            )}
            {(e.workflow || e.approval || e.period) && <p role="alert" className="text-sm text-[#B3261E]">{e.workflow || e.approval || e.period}</p>}
            <div className="flex justify-end gap-2">
                <Link href={i ? route('finance.invoices.show', i.id) : route('finance.invoices.index')} className={BTN_SECONDARY}>Cancel</Link>
                <button disabled={form.processing} className={BTN_PRIMARY}>{i ? 'Save changes' : 'Save draft'}</button>
            </div>
        </form>
    );
}
