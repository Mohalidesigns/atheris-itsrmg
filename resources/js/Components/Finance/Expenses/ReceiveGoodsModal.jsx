import InputError from '@/Components/InputError';
import Field from './Field';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const today = () => new Date().toISOString().slice(0, 10);

/** Record a goods receipt (GRN): received and accepted quantity per line, with a reason when part is rejected. */
export default function ReceiveGoodsModal({ show, onClose, po }) {
    const open = po.lines.filter((l) => Number(l.remaining_quantity) > 0);
    const form = useForm({ received_on: today(), notes: '', lines: open.map((l) => ({ po_line_id: l.id, quantity: String(l.remaining_quantity), accepted: String(l.remaining_quantity), rejection_reason: '' })) });
    const e = form.errors;
    const setLine = (i, k, v) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
    const submit = () => {
        form.transform((d) => ({ ...d, notes: d.notes || null, lines: d.lines.filter((l) => Number(l.quantity) > 0).map((l) => ({ ...l, rejection_reason: l.rejection_reason || null })) }));
        form.post(route('finance.purchase-orders.receive', po.id), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || open.length === 0} title={`Receive goods against ${po.po_code}`} submitLabel="Record receipt" maxWidth="3xl">
            {open.length === 0 ? <p className="text-sm text-[#718096]">Every line is fully received.</p> : (
                <>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <Field id="r-date" label="Received on" error={e.received_on}><input id="r-date" type="date" value={form.data.received_on} onChange={(ev) => form.setData('received_on', ev.target.value)} className={FIELD} /></Field>
                        <Field id="r-notes" label="Notes" error={e.notes} className="sm:col-span-2"><input id="r-notes" value={form.data.notes} onChange={(ev) => form.setData('notes', ev.target.value)} className={FIELD} /></Field>
                    </div>
                    <div className="space-y-3">
                        {open.map((l, i) => {
                            const f = form.data.lines[i];
                            const short = Number(f.accepted) < Number(f.quantity);
                            return (
                                <div key={l.id} className="grid grid-cols-12 items-end gap-2 rounded-lg border border-gray-100 p-2">
                                    <p className="col-span-12 text-sm text-[#2D3748]">#{l.line_no} {l.description} <span className="text-xs text-[#718096]">(remaining {Number(l.remaining_quantity)})</span></p>
                                    <div className="col-span-6 sm:col-span-2"><label className="text-xs text-[#4A5568]" htmlFor={`r-q-${i}`}>Received</label><input id={`r-q-${i}`} type="number" min="0" max={l.remaining_quantity} step="any" value={f.quantity} onChange={(ev) => setLine(i, 'quantity', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.quantity`]} /></div>
                                    <div className="col-span-6 sm:col-span-2"><label className="text-xs text-[#4A5568]" htmlFor={`r-a-${i}`}>Accepted</label><input id={`r-a-${i}`} type="number" min="0" step="any" value={f.accepted} onChange={(ev) => setLine(i, 'accepted', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.accepted`]} /></div>
                                    <div className="col-span-12 sm:col-span-8"><label className="text-xs text-[#4A5568]" htmlFor={`r-x-${i}`}>Rejection reason{short ? ' (required)' : ''}</label><input id={`r-x-${i}`} disabled={!short} value={f.rejection_reason} onChange={(ev) => setLine(i, 'rejection_reason', ev.target.value)} className={FIELD} /><InputError message={e[`lines.${i}.rejection_reason`]} /></div>
                                </div>
                            );
                        })}
                    </div>
                    <InputError message={e.lines} />
                </>
            )}
        </FormModal>
    );
}
