import InputError from '@/Components/InputError';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Compute allocation results for chosen periods. Closed periods that already have results stay frozen. */
export default function RunAllocationsModal({ show, onClose, fiscalYears, periods, amountTypes, defaultFiscalYearId }) {
    const form = useForm({ fiscal_year_id: '', period_ids: [], amount_types: ['budget', 'actual'] });
    useEffect(() => {
        if (show) { form.clearErrors(); form.setData({ fiscal_year_id: defaultFiscalYearId ?? fiscalYears[0]?.id ?? '', period_ids: [], amount_types: ['budget', 'actual'] }); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);
    const toggle = (key, v) => form.setData(key, form.data[key].includes(v) ? form.data[key].filter((x) => x !== v) : [...form.data[key], v]);
    const submit = () => {
        form.transform((d) => ({ ...d, period_ids: d.period_ids.length ? d.period_ids : null }));
        form.post(route('finance.allocations.run'), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || form.data.amount_types.length === 0 || !form.data.fiscal_year_id} title="Run allocations" submitLabel="Run">
            <div><label className={LABEL} htmlFor="ra-fy">Fiscal year</label><select id="ra-fy" value={form.data.fiscal_year_id} onChange={(e) => form.setData('fiscal_year_id', e.target.value)} className={FIELD}>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select><InputError message={form.errors.fiscal_year_id} /></div>
            <fieldset>
                <legend className={LABEL}>Periods (none selected means all)</legend>
                <div className="mt-1 grid grid-cols-2 gap-1 sm:grid-cols-3">
                    {periods.map((p) => <Check key={p.id} label={`${p.name}${p.status !== 'open' ? ` (${p.status})` : ''}`} checked={form.data.period_ids.includes(p.id)} onChange={() => toggle('period_ids', p.id)} />)}
                </div>
                <InputError message={form.errors.period_ids} />
            </fieldset>
            <fieldset>
                <legend className={LABEL}>Amounts</legend>
                <div className="mt-1 flex gap-4">{amountTypes.map((o) => <Check key={o.value} label={o.label ?? humanize(o.value)} checked={form.data.amount_types.includes(o.value)} onChange={() => toggle('amount_types', o.value)} />)}</div>
                <InputError message={form.errors.amount_types} />
            </fieldset>
            <p className="text-xs text-[#718096]">Open periods are recomputed. Closed and locked periods with results are frozen and skipped.</p>
        </FormModal>
    );
}
