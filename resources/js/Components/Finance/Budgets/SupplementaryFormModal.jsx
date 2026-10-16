import InputError from '@/Components/InputError';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const BLANK = { cost_centre_id: '', target_line_id: '', cost_pool_id: '', tower_id: '', category_id: '', title: '', is_capex: false, currency: '', amount: '', justification: '' };

export default function SupplementaryFormModal({ show, onClose, request, fiscalYearId, lineOptions, costCentres, costPools, towers, subCategories, currencies, baseCurrency }) {
    const form = useForm({ ...BLANK, fiscal_year_id: fiscalYearId, currency: baseCurrency });
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        form.setData(request ? {
            fiscal_year_id: fiscalYearId, cost_centre_id: request.cost_centre?.id ?? '', target_line_id: request.target_line?.id ?? '', cost_pool_id: request.cost_pool?.id ?? '',
            tower_id: request.tower?.id ?? '', category_id: request.category?.id ?? '', title: request.title, is_capex: request.is_capex, currency: request.currency, amount: request.amount, justification: request.justification ?? '',
        } : { ...BLANK, fiscal_year_id: fiscalYearId, currency: baseCurrency });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, request?.id]);
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const newLine = !form.data.target_line_id;
    const lines = lineOptions.filter((l) => !form.data.cost_centre_id || String(l.cost_centre_id) === String(form.data.cost_centre_id));
    const submit = () => {
        form.transform((d) => {
            const out = Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
            if (out.target_line_id) Object.assign(out, { cost_pool_id: null, tower_id: null, category_id: null });
            return out;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (request) form.put(route('finance.supplementary.update', request.id), opts); else form.post(route('finance.supplementary.store'), opts);
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} title={request ? `Edit ${request.request_code}` : 'New supplementary request'} maxWidth="2xl">
            <div><label className={LABEL}>Title</label><input maxLength={200} value={form.data.title} onChange={set('title')} className={FIELD} /><InputError message={e.title} /></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL}>Cost centre</label><select value={form.data.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.cost_centre_id} /></div>
                <div><label className={LABEL}>Top up an existing baseline line</label><select value={form.data.target_line_id} onChange={set('target_line_id')} className={FIELD}><option value="">No, create a new line</option>{lines.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}</select><InputError message={e.target_line_id} /></div>
            </div>
            {newLine && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Cost pool</label><select value={form.data.cost_pool_id} onChange={set('cost_pool_id')} className={FIELD}><option value="">Select…</option>{costPools.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.cost_pool_id} /></div>
                    <div><label className={LABEL}>Tower</label><select value={form.data.tower_id} onChange={set('tower_id')} className={FIELD}><option value="">Select…</option>{towers.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.tower_id} /></div>
                    <div><label className={LABEL}>Category</label><select value={form.data.category_id} onChange={set('category_id')} className={FIELD}><option value="">Select…</option>{subCategories.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.category_id} /></div>
                </div>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label className={LABEL}>Currency</label><select value={form.data.currency} onChange={set('currency')} className={FIELD}>{currencies.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}</select><InputError message={e.currency} /></div>
                <div><label className={LABEL}>Amount</label><input type="number" min="0" step="any" value={form.data.amount} onChange={set('amount')} className={FIELD} /><InputError message={e.amount} />
                    {form.data.currency !== baseCurrency && form.data.amount && <p className="mt-1 text-[11px] text-[#718096]">Converted to {baseCurrency} at the latest rate when saved.</p>}</div>
                <div className="flex items-end pb-2"><Check label="CapEx" checked={form.data.is_capex} onChange={(v) => form.setData('is_capex', v)} /></div>
            </div>
            <div><label className={LABEL}>Justification</label><textarea rows={3} value={form.data.justification} onChange={set('justification')} className={FIELD} /><InputError message={e.justification} /></div>
            <InputError message={e.workflow} />
        </FormModal>
    );
}
