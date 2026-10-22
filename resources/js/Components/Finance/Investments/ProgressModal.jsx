import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Manager-reported % complete with an as-of date, plus an optional manual EAC override (reason of 20+ characters). */
export default function ProgressModal({ show, onClose, investment, today, viewAmounts = true, reasonMin = 20, errors = {} }) {
    const fresh = () => ({
        percent_complete: investment.percent_complete ?? 0, percent_complete_as_of: today, override: !!investment.eac_override,
        eac: investment.eac_override ?? '', eac_reason: investment.eac_reason ?? '',
    });
    const [d, setD] = useState(fresh);
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (show) setD(fresh()); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [show]);
    const set = (k) => (e) => setD({ ...d, [k]: e.target.value });
    const bad = d.override && viewAmounts && (d.eac === '' || d.eac_reason.trim().length < reasonMin);
    const go = () => {
        setBusy(true);
        router.post(route('finance.investments.progress', investment.id), {
            percent_complete: d.percent_complete, as_of: d.percent_complete_as_of,
            eac: d.override ? d.eac : null, eac_reason: d.override ? d.eac_reason : null, clear_eac: !d.override,
        }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={go} processing={busy || bad} title="Update progress">
            <div className="grid grid-cols-2 gap-3">
                <div><label className={LABEL} htmlFor="pg-pc">Percent complete</label><input id="pg-pc" type="number" min="0" max="100" step="0.5" value={d.percent_complete} onChange={set('percent_complete')} className={FIELD} /><InputError message={errors.percent_complete} /></div>
                <div><label className={LABEL} htmlFor="pg-as">As of</label><input id="pg-as" type="date" value={d.percent_complete_as_of ?? ''} max={today} onChange={set('percent_complete_as_of')} className={FIELD} /><InputError message={errors.as_of} /></div>
            </div>
            <p className="text-xs text-[#718096]">Earned value is the budget at completion multiplied by this percentage.</p>
            {viewAmounts && (
                <fieldset className="rounded-lg border border-gray-100 p-3">
                    <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={d.override} onChange={(e) => setD({ ...d, override: e.target.checked })} /> Override the estimate at completion</label>
                    {d.override && (
                        <div className="mt-3 space-y-3">
                            <div><label className={LABEL} htmlFor="pg-eac">Estimate at completion</label><input id="pg-eac" type="number" step="any" min="0" value={d.eac} onChange={set('eac')} className={FIELD} /><InputError message={errors.eac} /></div>
                            <div><label className={LABEL} htmlFor="pg-why">Reason (at least {reasonMin} characters)</label><textarea id="pg-why" rows={2} value={d.eac_reason} onChange={set('eac_reason')} className={FIELD} /><InputError message={errors.eac_reason} /></div>
                        </div>
                    )}
                </fieldset>
            )}
        </FormModal>
    );
}
