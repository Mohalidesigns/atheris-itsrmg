import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Request a change to cost or schedule. Within tolerance it applies at once; beyond tolerance it goes to approval. */
export default function ChangeRequestModal({ show, onClose, investment, baseCurrency, viewAmounts = true, reasonMin = 20 }) {
    const form = useForm({ description: '', reason: '', cost_delta: '0', schedule_delta_days: '0' });
    useEffect(() => { if (show) { form.reset(); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [show]);
    const e = form.errors;
    const submit = () => form.post(route('finance.investment-changes.store', investment.id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.description.trim() || form.data.reason.trim().length < reasonMin} title="Request a change">
            <div><label className={LABEL} htmlFor="ch-d">What changes</label><textarea id="ch-d" rows={2} value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} className={FIELD} /><InputError message={e.description} /></div>
            <div className="grid grid-cols-2 gap-3">
                {viewAmounts && <div><label className={LABEL} htmlFor="ch-c">Cost change ({baseCurrency}, negative to reduce)</label><input id="ch-c" type="number" step="any" value={form.data.cost_delta} onChange={(ev) => form.setData('cost_delta', ev.target.value)} className={FIELD} /><InputError message={e.cost_delta} /></div>}
                <div><label className={LABEL} htmlFor="ch-s">Schedule change (days)</label><input id="ch-s" type="number" step="1" value={form.data.schedule_delta_days} onChange={(ev) => form.setData('schedule_delta_days', ev.target.value)} className={FIELD} /><InputError message={e.schedule_delta_days} /></div>
            </div>
            <div><label className={LABEL} htmlFor="ch-r">Reason (at least {reasonMin} characters)</label><textarea id="ch-r" rows={2} value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} className={FIELD} /><InputError message={e.reason} /></div>
            <p className="text-xs text-[#718096]">Tolerance: {Number(investment.cost_tolerance_pct)}% of the budget at completion in total, or the schedule limit. Beyond it the change waits for approval.</p>
            <InputError message={e.change} />
        </FormModal>
    );
}
