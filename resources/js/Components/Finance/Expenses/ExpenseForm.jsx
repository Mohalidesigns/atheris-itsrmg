import Field from './Field';
import AvailabilityCard from './AvailabilityCard';
import { Card, Check } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD } from '@/Utils/finance';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Create / edit form for one expense request. Picking a budget line pre-fills category, GL, vendor, contract, currency. */
export default function ExpenseForm({ expense, budgetLines, costCentres, categories, glAccounts, vendors, contracts, taxTypes, currencies, legalEntities, assets, fiscalYears, settings, defaults, baseCurrency, can }) {
    const x = expense;
    const form = useForm({
        title: x?.title ?? '', description: x?.description ?? '', fiscal_year_id: x?.fiscal_year?.id ?? defaults.fiscal_year_id ?? '', budget_line_id: x?.budget_line?.id ?? '',
        cost_centre_id: x?.cost_centre?.id ?? defaults.cost_centre_id ?? '', category_id: x?.category?.id ?? '', gl_account_id: x?.gl_account?.id ?? '', legal_entity_id: x?.legal_entity?.id ?? '',
        vendor_id: x?.vendor?.id ?? '', contract_id: x?.contract?.id ?? '', asset_id: x?.asset?.id ?? '', is_capex: !!x?.is_capex, is_emergency: !!x?.is_emergency, needed_by: x?.needed_by ?? '',
        currency: x?.currency ?? defaults.currency ?? baseCurrency, amount: x?.amount ?? '', tax_type_id: x?.tax_type?.id ?? '', justification: x?.justification ?? '', overbudget_justification: x?.overbudget_justification ?? '',
    });
    const d = form.data;
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value);
    const lines = budgetLines.filter((l) => (!d.fiscal_year_id || String(l.fiscal_year_id) === String(d.fiscal_year_id)) && (!d.cost_centre_id || String(l.cost_centre_id) === String(d.cost_centre_id) || String(l.id) === String(d.budget_line_id)));
    const [avail, setAvail] = useState(null);
    const [checking, setChecking] = useState(false);

    const pickLine = (ev) => {
        const id = ev.target.value;
        const l = budgetLines.find((b) => String(b.id) === String(id));
        form.setData((cur) => ({ ...cur, budget_line_id: id, ...(l ? {
            cost_centre_id: l.cost_centre_id ?? cur.cost_centre_id, category_id: cur.category_id || l.category_id || '', gl_account_id: cur.gl_account_id || l.gl_account_id || '',
            vendor_id: cur.vendor_id || l.vendor_id || '', contract_id: cur.contract_id || l.contract_id || '', is_capex: cur.is_capex || !!l.is_capex,
        } : {}) }));
    };
    const pickContract = (ev) => {
        const c = contracts.find((k) => String(k.id) === String(ev.target.value));
        form.setData((cur) => ({ ...cur, contract_id: ev.target.value, ...(c ? { vendor_id: cur.vendor_id || c.vendor_id || '', budget_line_id: cur.budget_line_id || c.budget_line_id || '' } : {}) }));
    };

    useEffect(() => {
        if (!d.budget_line_id || !can.viewAmounts) { setAvail(null); return undefined; }
        setChecking(true);
        const t = setTimeout(() => {
            window.axios.get(route('finance.expenses.availability'), { params: { budget_line_id: d.budget_line_id, amount: d.amount || 0, currency: d.currency, exclude: x?.id } })
                .then((r) => setAvail(r.data)).catch(() => setAvail(null)).finally(() => setChecking(false));
        }, 350);
        return () => clearTimeout(t);
    }, [d.budget_line_id, d.amount, d.currency]);

    const wouldExceed = avail?.would_exceed;
    const justificationNeeded = (wouldExceed || !d.budget_line_id) && settings.overbudget_mode !== 'allow';
    const clean = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((cur) => clean(cur));
        if (x) form.put(route('finance.expenses.update', x.id));
        else form.post(route('finance.expenses.store'));
    };
    const opts = (list, fmt) => list.map((i) => <option key={i.id} value={i.id}>{fmt(i)}</option>);
    const dec = currencies.find((c) => c.code === d.currency)?.decimals ?? 2;

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div className="space-y-4 xl:col-span-2">
                <Card title="Request">
                    <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                        <Field id="x-title" label="Title" error={e.title} className="sm:col-span-2"><input id="x-title" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /></Field>
                        <Field id="x-desc" label="Description" error={e.description} className="sm:col-span-2"><textarea id="x-desc" rows={3} value={d.description} onChange={set('description')} className={FIELD} /></Field>
                        <Field id="x-fy" label="Fiscal year" error={e.fiscal_year_id}><select id="x-fy" value={d.fiscal_year_id} onChange={set('fiscal_year_id')} className={FIELD}><option value="">Select…</option>{opts(fiscalYears, (f) => f.year_label)}</select></Field>
                        <Field id="x-cc" label="Cost centre" error={e.cost_centre_id}><select id="x-cc" value={d.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">Select…</option>{opts(costCentres, (c) => `${c.code} ${c.name}`)}</select></Field>
                        <Field id="x-bl" label="Budget line" error={e.budget_line_id} className="sm:col-span-2" hint="Leave empty to raise this as unbudgeted spend; a justification is then required."><select id="x-bl" value={d.budget_line_id} onChange={pickLine} className={FIELD}><option value="">Unbudgeted</option>{opts(lines, (l) => `${l.budget_code} #${l.line_no} ${l.label}`)}</select></Field>
                        <Field id="x-cur" label="Currency" error={e.currency}><select id="x-cur" value={d.currency} onChange={set('currency')} className={FIELD}>{currencies.map((c) => <option key={c.code} value={c.code}>{c.code}{c.is_base ? ' (base)' : ''}</option>)}</select></Field>
                        <Field id="x-amt" label="Amount (before tax)" error={e.amount}><input id="x-amt" inputMode="decimal" type="number" min="0" step={dec === 0 ? '1' : '0.01'} value={d.amount} onChange={set('amount')} className={FIELD} /></Field>
                        <Field id="x-tax" label="Tax" error={e.tax_type_id}><select id="x-tax" value={d.tax_type_id} onChange={set('tax_type_id')} className={FIELD}><option value="">None</option>{opts(taxTypes, (t) => `${t.code} (${Number(t.rate)}%${t.is_inclusive ? ', inclusive' : ''})`)}</select></Field>
                        <Field id="x-need" label="Needed by" error={e.needed_by}><input id="x-need" type="date" value={d.needed_by} onChange={set('needed_by')} className={FIELD} /></Field>
                        <div className="flex flex-wrap items-center gap-4 sm:col-span-2">
                            <Check label="Capital expenditure" checked={d.is_capex} onChange={(v) => form.setData('is_capex', v)} />
                            <Check label="Emergency" checked={d.is_emergency} onChange={(v) => form.setData('is_emergency', v)} />
                        </div>
                    </div>
                </Card>
                <Card title="Classification and links">
                    <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                        <Field id="x-vendor" label="Vendor" error={e.vendor_id}><select id="x-vendor" value={d.vendor_id} onChange={set('vendor_id')} className={FIELD}><option value="">None</option>{opts(vendors, (v) => v.name)}</select></Field>
                        <Field id="x-contract" label="Contract" error={e.contract_id}><select id="x-contract" value={d.contract_id} onChange={pickContract} className={FIELD}><option value="">None</option>{opts(contracts, (c) => `${c.contract_code} ${c.title}`)}</select></Field>
                        <Field id="x-cat" label="Category" error={e.category_id}><select id="x-cat" value={d.category_id} onChange={set('category_id')} className={FIELD}><option value="">None</option>{opts(categories, (c) => `${c.code} ${c.name}`)}</select></Field>
                        <Field id="x-gl" label="GL account" error={e.gl_account_id}><select id="x-gl" value={d.gl_account_id} onChange={set('gl_account_id')} className={FIELD}><option value="">None</option>{opts(glAccounts, (g) => `${g.code} ${g.name}`)}</select></Field>
                        <Field id="x-le" label="Legal entity" error={e.legal_entity_id}><select id="x-le" value={d.legal_entity_id} onChange={set('legal_entity_id')} className={FIELD}><option value="">None</option>{opts(legalEntities ?? [], (l) => `${l.code} ${l.name}`)}</select></Field>
                        <Field id="x-asset" label="Asset" error={e.asset_id}><select id="x-asset" value={d.asset_id} onChange={set('asset_id')} className={FIELD}><option value="">None</option>{opts(assets, (a) => a.label)}</select></Field>
                    </div>
                </Card>
                <Card title="Justification">
                    <div className="space-y-4 px-5 py-4">
                        <Field id="x-just" label="Business justification" error={e.justification}><textarea id="x-just" rows={3} value={d.justification} onChange={set('justification')} className={FIELD} /></Field>
                        {(justificationNeeded || d.overbudget_justification) && (
                            <Field id="x-over" label={d.budget_line_id ? `Over-budget justification (minimum ${settings.justification_min} characters)` : `Unbudgeted justification (minimum ${settings.justification_min} characters)`} error={e.overbudget_justification}>
                                <textarea id="x-over" rows={3} value={d.overbudget_justification} onChange={set('overbudget_justification')} className={FIELD} />
                            </Field>
                        )}
                    </div>
                </Card>
                {(e.workflow || e.approval) && <p role="alert" className="text-sm text-[#B3261E]">{e.workflow || e.approval}</p>}
                <div className="flex justify-end gap-2">
                    <Link href={x ? route('finance.expenses.show', x.id) : route('finance.expenses.index')} className={BTN_SECONDARY}>Cancel</Link>
                    <button disabled={form.processing} className={BTN_PRIMARY}>{x ? 'Save changes' : 'Save draft'}</button>
                </div>
            </div>
            <div className="space-y-4">
                <AvailabilityCard availability={avail} baseCurrency={baseCurrency} money={can.viewAmounts !== false} title={checking ? 'Checking availability…' : 'Budget availability'}
                    extra={avail && can.viewAmounts !== false && (
                        <div className={`mx-5 mb-4 rounded-lg px-3 py-2 text-xs ${wouldExceed ? 'bg-[#F9DEDC] text-[#8C1D18]' : 'bg-[#E7F4EC] text-[#1B5530]'}`} role="status">
                            {wouldExceed ? `This request would exceed the line's available budget (policy: ${settings.overbudget_mode}).` : 'Within the available budget.'}
                        </div>
                    )} />
                {!d.budget_line_id && <p className="rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-xs text-[#5F4300]">No budget line chosen. The request is flagged unbudgeted and Finance is notified on submission.</p>}
            </div>
        </form>
    );
}
