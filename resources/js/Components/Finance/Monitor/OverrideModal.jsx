import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, moneyOrMask } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useEffect } from 'react';

const MIN = 10;

/** Manual EAC override: amount in base currency and a reason (at least 10 characters). */
export default function OverrideModal({ line, baseCurrency, onClose }) {
    const form = useForm({ amount: '', reason: '' });
    useEffect(() => { if (line) { form.setData({ amount: line.manual?.amount ?? line.eac ?? '', reason: '' }); form.clearErrors(); } }, [line?.line_id]); // eslint-disable-line react-hooks/exhaustive-deps
    const submit = () => form.post(route('finance.monitor.forecast.override', line.line_id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show={!!line} onClose={onClose} onSubmit={submit} processing={form.processing || form.data.amount === '' || form.data.reason.trim().length < MIN} title={line ? `Override forecast: ${line.label}` : ''} submitLabel="Set override">
            {line && <p className="text-xs text-[#4A5568]">Computed: remaining budget {moneyOrMask(line.eac_remaining_budget, baseCurrency, true, { decimals: 0 })}, run rate {moneyOrMask(line.eac_run_rate, baseCurrency, true, { decimals: 0 })}. The override replaces the computed figure until cleared.</p>}
            <div>
                <label className={LABEL} htmlFor="fo-amount">Estimate at completion ({baseCurrency})</label>
                <input id="fo-amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className={FIELD} placeholder="0.00" />
                <InputError message={form.errors.amount} />
            </div>
            <div>
                <label className={LABEL} htmlFor="fo-reason">Reason (at least {MIN} characters)</label>
                <textarea id="fo-reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} />
                <InputError message={form.errors.reason} />
            </div>
        </FormModal>
    );
}

/** Clear a manual override (DELETE) with a reason for the audit trail. */
export function ClearOverrideModal({ line, errors = {}, onClose }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const close = () => { setReason(''); onClose(); };
    const submit = () => {
        setBusy(true);
        router.delete(route('finance.monitor.forecast.clear', line.line_id), { data: { reason }, preserveScroll: true, onSuccess: close, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show={!!line} onClose={close} onSubmit={submit} processing={busy || !reason.trim()} title={line ? `Clear override: ${line.label}` : ''} submitLabel="Clear override">
            <div>
                <label className={LABEL} htmlFor="fc-reason">Reason</label>
                <textarea id="fc-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className={FIELD} />
                <InputError message={errors.reason} />
            </div>
        </FormModal>
    );
}
