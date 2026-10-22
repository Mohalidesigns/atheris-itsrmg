import InputError from '@/Components/InputError';
import Field from './Field';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, formatMoney } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const today = () => new Date().toISOString().slice(0, 10);

/** Commit an approved expense as a purchase order: dates, tax and priced lines. Errors (e.g. budget, duplicate PO) show inline. */
export default function CommitPoModal({ show, onClose, expense, taxTypes }) {
    const form = useForm({
        po_date: today(), expected_delivery_on: expense.needed_by ?? '', external_reference: '', tax_type_id: expense.tax_type?.id ?? '',
        lines: [{ description: expense.title, quantity: '1', unit_cost: expense.amount ?? '' }],
    });
    const d = form.data;
    const setLine = (i, k, v) => form.setData('lines', d.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
    const subtotal = d.lines.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0);
    const submit = () => {
        form.transform((cur) => ({ ...cur, expected_delivery_on: cur.expected_delivery_on || null, external_reference: cur.external_reference || null, tax_type_id: cur.tax_type_id || null }));
        form.post(route('finance.expenses.commit', expense.id), { preserveScroll: true, onSuccess: onClose });
    };
    const e = form.errors;
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || d.lines.length === 0} title={`Commit ${expense.request_code} as a purchase order`} submitLabel="Commit" maxWidth="3xl">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <Field id="c-date" label="PO date" error={e.po_date}><input id="c-date" type="date" value={d.po_date} onChange={(ev) => form.setData('po_date', ev.target.value)} className={FIELD} /></Field>
                <Field id="c-exp" label="Expected delivery" error={e.expected_delivery_on}><input id="c-exp" type="date" value={d.expected_delivery_on} onChange={(ev) => form.setData('expected_delivery_on', ev.target.value)} className={FIELD} /></Field>
                <Field id="c-ref" label="External reference" error={e.external_reference}><input id="c-ref" maxLength={100} value={d.external_reference} onChange={(ev) => form.setData('external_reference', ev.target.value)} className={FIELD} /></Field>
                <Field id="c-tax" label="Tax" error={e.tax_type_id}><select id="c-tax" value={d.tax_type_id} onChange={(ev) => form.setData('tax_type_id', ev.target.value)} className={FIELD}><option value="">None</option>{taxTypes.map((t) => <option key={t.id} value={t.id}>{t.code}</option>)}</select></Field>
            </div>
            <div className="space-y-2">
                {d.lines.map((l, i) => (
                    <div key={i} className="grid grid-cols-12 items-end gap-2">
                        <div className="col-span-12 sm:col-span-6"><label className="text-xs text-[#4A5568]" htmlFor={`c-ld-${i}`}>Description</label><input id={`c-ld-${i}`} value={l.description} onChange={(ev) => setLine(i, 'description', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.description`]} /></div>
                        <div className="col-span-4 sm:col-span-2"><label className="text-xs text-[#4A5568]" htmlFor={`c-lq-${i}`}>Quantity</label><input id={`c-lq-${i}`} type="number" min="0" step="any" value={l.quantity} onChange={(ev) => setLine(i, 'quantity', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.quantity`]} /></div>
                        <div className="col-span-5 sm:col-span-3"><label className="text-xs text-[#4A5568]" htmlFor={`c-lu-${i}`}>Unit cost ({expense.currency})</label><input id={`c-lu-${i}`} type="number" min="0" step="any" value={l.unit_cost} onChange={(ev) => setLine(i, 'unit_cost', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.unit_cost`]} /></div>
                        <div className="col-span-3 sm:col-span-1 pb-2 text-right">{d.lines.length > 1 && <button type="button" onClick={() => form.setData('lines', d.lines.filter((_, j) => j !== i))} className="text-xs text-[#B3261E] underline">Remove</button>}</div>
                    </div>
                ))}
                <button type="button" onClick={() => form.setData('lines', [...d.lines, { description: '', quantity: '1', unit_cost: '' }])} className="rounded-lg border border-gray-200 px-3 py-1 text-xs">Add line</button>
            </div>
            <p className="text-xs text-[#718096]">Lines subtotal {formatMoney(subtotal, expense.currency)} before tax. The PO subtotal must reconcile with the approved request amount.</p>
            <InputError message={e.lines} />
            {(e.budget || e.commit || e.approval) && <p role="alert" className="text-sm text-[#B3261E]">{e.budget || e.commit || e.approval}</p>}
        </FormModal>
    );
}
