import InputError from '@/Components/InputError';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const blank = (dims, baseCurrency) => ({
    description: '', cost_pool_id: '', tower_id: '', category_id: '', gl_account_id: '', legal_entity_id: '', is_capex: false, is_recurring: true,
    priority: dims.priorities[0] ?? 'committed', rgt: 'run', vendor_id: '', asset_id: '', ea_application_id: '', business_service_id: '', risk_id: '',
    driver_id: '', driver_rate: '', quantity: '1', unit_cost: '', currency: baseCurrency, annual_amount: '', phasing_method: 'equal', justification: '',
    variance_amber_pct: '', variance_red_pct: '',
});

function Select({ label, value, onChange, options, error, optional = true }) {
    return (
        <div><label className={LABEL}>{label}</label>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={FIELD}>
                {optional && <option value="">None</option>}
                {options.map(([v, t]) => <option key={v} value={v}>{t}</option>)}
            </select><InputError message={error} /></div>
    );
}

/** Add / edit one budget line. `line` null = add. Dimension selects with no options are hidden. */
export default function LineFormModal({ show, onClose, budget, line, dims, baseCurrency }) {
    const form = useForm(blank(dims, baseCurrency));
    const [mode, setMode] = useState('amount');
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        if (line) {
            const d = {};
            Object.keys(blank(dims, baseCurrency)).forEach((k) => { d[k] = typeof line[k] === 'boolean' ? line[k] : (line[k] ?? ''); });
            form.setData(d);
            setMode(line.driver_id ? 'driver' : line.unit_cost ? 'unit' : 'amount');
        } else { form.setData(blank(dims, baseCurrency)); setMode('amount'); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, line?.id]);

    const set = (k) => (v) => form.setData(k, v);
    const e = form.errors;
    const pairs = (list, f) => list.map((x) => [x.id, f(x)]);
    const pickPool = (id) => {
        const p = dims.costPools.find((c) => String(c.id) === String(id));
        form.setData((d) => ({ ...d, cost_pool_id: id, ...(p ? { is_capex: !!p.default_capex, gl_account_id: d.gl_account_id || p.default_gl_account_id || '' } : {}) }));
    };
    const submit = () => {
        form.transform((d) => {
            const out = { ...d };
            Object.keys(out).forEach((k) => { if (out[k] === '') out[k] = null; });
            if (mode === 'amount') { out.unit_cost = null; out.driver_id = null; out.driver_rate = null; }
            if (mode === 'unit') { out.annual_amount = null; out.driver_id = null; out.driver_rate = null; }
            if (mode === 'driver') { out.annual_amount = null; out.unit_cost = null; }
            if (out.quantity === null) out.quantity = '1';
            return out;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (line) form.put(route('finance.budgets.lines.update', { budget: budget.id, line: line.id }), opts);
        else form.post(route('finance.budgets.lines.store', budget.id), opts);
    };
    const subs = dims.subCategories.filter((s) => !form.data.tower_id || String(s.parent_id) === String(form.data.tower_id) || !s.parent_id);
    const unitTotal = form.data.unit_cost !== '' ? (Number(form.data.quantity || 1) * Number(form.data.unit_cost)) : null;

    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} maxWidth="2xl" title={line ? `Edit line ${line.line_no}` : 'Add line'}>
            <div><label className={LABEL}>Description</label><input maxLength={255} value={form.data.description} onChange={(ev) => set('description')(ev.target.value)} className={FIELD} /><InputError message={e.description} /></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Select optional={false} label="Cost pool" value={form.data.cost_pool_id} onChange={pickPool} options={[['', 'Select…'], ...pairs(dims.costPools, (c) => `${c.code} ${c.name}`)]} error={e.cost_pool_id} />
                <Select optional={false} label="Tower" value={form.data.tower_id} onChange={set('tower_id')} options={[['', 'Select…'], ...pairs(dims.towers, (c) => `${c.code} ${c.name}`)]} error={e.tower_id} />
                {subs.length > 0 && <Select label="Sub-category" value={form.data.category_id} onChange={set('category_id')} options={pairs(subs, (c) => `${c.code} ${c.name}`)} error={e.category_id} />}
                {dims.glAccounts.length > 0 && <Select label="GL account" value={form.data.gl_account_id} onChange={set('gl_account_id')} options={pairs(dims.glAccounts, (c) => `${c.code} ${c.name}`)} error={e.gl_account_id} />}
                {dims.legalEntities.length > 0 && <Select label="Legal entity" value={form.data.legal_entity_id} onChange={set('legal_entity_id')} options={pairs(dims.legalEntities, (c) => `${c.code} ${c.name}`)} error={e.legal_entity_id} />}
                <Select optional={false} label="Priority" value={form.data.priority} onChange={set('priority')} options={dims.priorities.map((p) => [p, humanize(p)])} error={e.priority} />
                <Select optional={false} label="Run / Grow / Transform" value={form.data.rgt} onChange={set('rgt')} options={dims.rgt.map((p) => [p, humanize(p)])} error={e.rgt} />
                <Select optional={false} label="Phasing" value={form.data.phasing_method} onChange={set('phasing_method')} options={dims.phasingMethods.map((p) => [p, humanize(p)])} error={e.phasing_method} />
            </div>
            <div className="flex flex-wrap gap-6">
                <Check label="CapEx" checked={form.data.is_capex} onChange={set('is_capex')} />
                <Check label="Recurring" checked={form.data.is_recurring} onChange={set('is_recurring')} />
            </div>

            <fieldset className="rounded-lg border border-gray-100 p-3">
                <legend className="px-1 text-xs font-medium text-[#4A5568]">Amount</legend>
                <div className="mb-3 flex flex-wrap gap-4 text-sm" role="radiogroup" aria-label="Amount basis">
                    {[['amount', 'Annual amount'], ['unit', 'Quantity × unit cost'], ...(dims.drivers.length ? [['driver', 'Driver × rate']] : [])].map(([k, t]) => (
                        <label key={k} className="flex items-center gap-1"><input type="radio" name="line-mode" checked={mode === k} onChange={() => setMode(k)} /> {t}</label>
                    ))}
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Select optional={false} label="Currency" value={form.data.currency} onChange={set('currency')} options={dims.currencies.map((c) => [c.code, c.code])} error={e.currency} />
                    {mode === 'amount' && <div className="sm:col-span-2"><label className={LABEL}>Annual amount</label><input type="number" step="any" min="0" value={form.data.annual_amount} onChange={(ev) => set('annual_amount')(ev.target.value)} className={FIELD} /><InputError message={e.annual_amount} /></div>}
                    {mode === 'unit' && <>
                        <div><label className={LABEL}>Quantity</label><input type="number" step="any" min="0" value={form.data.quantity} onChange={(ev) => set('quantity')(ev.target.value)} className={FIELD} /><InputError message={e.quantity} /></div>
                        <div><label className={LABEL}>Unit cost</label><input type="number" step="any" min="0" value={form.data.unit_cost} onChange={(ev) => set('unit_cost')(ev.target.value)} className={FIELD} /><InputError message={e.unit_cost} /></div>
                        {unitTotal !== null && <p className="text-xs text-[#718096] sm:col-span-3">Annual total {unitTotal.toLocaleString()} {form.data.currency}</p>}
                    </>}
                    {mode === 'driver' && <>
                        <Select optional={false} label="Driver" value={form.data.driver_id} onChange={set('driver_id')} options={[['', 'Select…'], ...dims.drivers.map((d) => [d.id, `${d.code} ${d.name}${d.unit ? ` (${d.unit})` : ''}`])]} error={e.driver_id} />
                        <div><label className={LABEL}>Rate per unit</label><input type="number" step="any" min="0" value={form.data.driver_rate} onChange={(ev) => set('driver_rate')(ev.target.value)} className={FIELD} /><InputError message={e.driver_rate} /></div>
                    </>}
                </div>
            </fieldset>

            <details className="rounded-lg border border-gray-100 p-3">
                <summary className="cursor-pointer text-xs font-medium text-[#4A5568]">Links and variance thresholds</summary>
                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {dims.vendors.length > 0 && <Select label="Vendor" value={form.data.vendor_id} onChange={set('vendor_id')} options={pairs(dims.vendors, (c) => c.name)} error={e.vendor_id} />}
                    {dims.assets.length > 0 && <Select label="Asset" value={form.data.asset_id} onChange={set('asset_id')} options={pairs(dims.assets, (c) => c.name)} error={e.asset_id} />}
                    {dims.eaApplications.length > 0 && <Select label="Application" value={form.data.ea_application_id} onChange={set('ea_application_id')} options={pairs(dims.eaApplications, (c) => c.name)} error={e.ea_application_id} />}
                    {dims.businessServices.length > 0 && <Select label="Business service" value={form.data.business_service_id} onChange={set('business_service_id')} options={pairs(dims.businessServices, (c) => c.name)} error={e.business_service_id} />}
                    {dims.risks.length > 0 && <Select label="Risk" value={form.data.risk_id} onChange={set('risk_id')} options={pairs(dims.risks, (c) => c.title)} error={e.risk_id} />}
                    <div><label className={LABEL}>Amber variance %</label><input type="number" step="any" min="0" value={form.data.variance_amber_pct} onChange={(ev) => set('variance_amber_pct')(ev.target.value)} className={FIELD} /><InputError message={e.variance_amber_pct} /></div>
                    <div><label className={LABEL}>Red variance %</label><input type="number" step="any" min="0" value={form.data.variance_red_pct} onChange={(ev) => set('variance_red_pct')(ev.target.value)} className={FIELD} /><InputError message={e.variance_red_pct} /></div>
                </div>
            </details>
            <div><label className={LABEL}>Justification</label><textarea rows={3} value={form.data.justification} onChange={(ev) => set('justification')(ev.target.value)} className={FIELD} /><InputError message={e.justification} /></div>
        </FormModal>
    );
}
