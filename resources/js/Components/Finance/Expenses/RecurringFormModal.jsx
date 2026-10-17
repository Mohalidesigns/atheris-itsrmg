import Field from './Field';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const optList = (l) => (l ?? []).map((o) => (typeof o === 'string' ? { value: o, label: o.replace(/_/g, ' ') } : o));

/** Create / edit a recurring expense schedule. */
export default function RecurringFormModal({ item, options, contracts, vendors, budgetLines, costCentres, categories, glAccounts, taxTypes, currencies, baseCurrency, onClose }) {
    const form = useForm({
        title: item?.title ?? '', contract_id: item?.contract?.id ?? '', budget_line_id: item?.budget_line?.id ?? '', vendor_id: item?.vendor?.id ?? '', category_id: item?.category?.id ?? '',
        gl_account_id: item?.gl_account?.id ?? '', tax_type_id: item?.tax_type?.id ?? '', cost_centre_id: item?.cost_centre?.id ?? '', frequency: item?.frequency ?? 'monthly', amount: item?.amount ?? '',
        currency: item?.currency ?? baseCurrency, starts_on: item?.starts_on ?? '', ends_on: item?.ends_on ?? '', auto_generate: item?.auto_generate ?? 'expected_invoice', is_active: item?.is_active ?? true, notes: item?.notes ?? '',
    });
    const d = form.data;
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const pickContract = (ev) => {
        const c = contracts.find((k) => String(k.id) === ev.target.value);
        form.setData((cur) => ({ ...cur, contract_id: ev.target.value, ...(c ? { vendor_id: cur.vendor_id || c.vendor_id || '', budget_line_id: cur.budget_line_id || c.budget_line_id || '', title: cur.title || c.title } : {}) }));
    };
    const submit = () => {
        form.transform((cur) => Object.fromEntries(Object.entries(cur).map(([k, v]) => [k, v === '' ? null : v])));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (item) form.put(route('finance.recurring.update', item.id), opts);
        else form.post(route('finance.recurring.store'), opts);
    };
    const sel = (id, k, label, list, fmt, onChange) => <Field id={id} label={label} error={e[k]}><select id={id} value={d[k]} onChange={onChange ?? set(k)} className={FIELD}><option value="">None</option>{list.map((x) => <option key={x.id} value={x.id}>{fmt(x)}</option>)}</select></Field>;
    return (
        <FormModal show onClose={onClose} onSubmit={submit} processing={form.processing} title={item ? `Edit ${item.recurring_code}` : 'New recurring expense'} maxWidth="3xl">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Field id="rc-title" label="Title" error={e.title} className="sm:col-span-3"><input id="rc-title" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /></Field>
                {sel('rc-k', 'contract_id', 'Contract', contracts, (c) => `${c.contract_code} ${c.title}`, pickContract)}
                {sel('rc-v', 'vendor_id', 'Vendor', vendors, (v) => v.name)}
                {sel('rc-cc', 'cost_centre_id', 'Cost centre', costCentres, (c) => `${c.code} ${c.name}`)}
                {sel('rc-bl', 'budget_line_id', 'Budget line', budgetLines, (l) => `${l.budget_code} #${l.line_no} ${l.label}`)}
                {sel('rc-cat', 'category_id', 'Category', categories, (c) => `${c.code} ${c.name}`)}
                {sel('rc-gl', 'gl_account_id', 'GL account', glAccounts, (g) => `${g.code} ${g.name}`)}
                <Field id="rc-f" label="Frequency" error={e.frequency}><select id="rc-f" value={d.frequency} onChange={set('frequency')} className={FIELD}>{optList(options.frequencies).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                <Field id="rc-amt" label="Amount per run (before tax)" error={e.amount}><input id="rc-amt" type="number" min="0" step="0.01" value={d.amount} onChange={set('amount')} className={FIELD} /></Field>
                <Field id="rc-cur" label="Currency" error={e.currency}><select id="rc-cur" value={d.currency} onChange={set('currency')} className={FIELD}>{currencies.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}</select></Field>
                {sel('rc-tax', 'tax_type_id', 'Tax', taxTypes, (t) => t.code)}
                <Field id="rc-s" label="Starts on" error={e.starts_on}><input id="rc-s" type="date" value={d.starts_on} onChange={set('starts_on')} className={FIELD} /></Field>
                <Field id="rc-e" label="Ends on" error={e.ends_on}><input id="rc-e" type="date" value={d.ends_on} onChange={set('ends_on')} className={FIELD} /></Field>
                <Field id="rc-a" label="When due" error={e.auto_generate}><select id="rc-a" value={d.auto_generate} onChange={set('auto_generate')} className={FIELD}>{optList(options.autoGenerate).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                <Field id="rc-n" label="Notes" error={e.notes} className="sm:col-span-2"><input id="rc-n" maxLength={2000} value={d.notes} onChange={set('notes')} className={FIELD} /></Field>
                <div className="flex items-end"><Check label="Active" checked={d.is_active} onChange={(v) => form.setData('is_active', v)} /></div>
            </div>
        </FormModal>
    );
}
