import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { DECISION_LABELS, FIELD, LABEL, localDate } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

/** Execute an approved renewal: creates the successor, or terminates. Dates are optional overrides. */
export default function ExecuteRenewalModal({ show, onClose, renewal, contract }) {
    const form = useForm({ terminated_on: '', commenced_on: '' });
    const terminating = renewal.recommendation === 'terminate';
    const submit = () => {
        form.transform((d) => ({ terminated_on: terminating ? d.terminated_on || null : null, commenced_on: terminating ? null : d.commenced_on || null }));
        form.post(route('finance.renewals.execute', renewal.id), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} title={`Execute ${renewal.renewal_code}`} submitLabel="Execute">
            <p className="text-sm text-[#4A5568]">Outcome: <b>{DECISION_LABELS[renewal.recommendation] ?? renewal.recommendation}</b>. {terminating ? 'The contract will be closed as terminated.' : renewal.recommendation === 'replace' ? 'A draft replacement contract is created and this one is marked replaced.' : 'A successor term is created and this contract is marked renewed.'}</p>
            {terminating ? (
                <div><label className={LABEL} htmlFor="x-term">Terminated on (defaults to {localDate(contract.ends_on)})</label><input id="x-term" type="date" value={form.data.terminated_on} onChange={(e) => form.setData('terminated_on', e.target.value)} className={FIELD} /><InputError message={form.errors.terminated_on} /></div>
            ) : (
                <div><label className={LABEL} htmlFor="x-from">Next term commences on (defaults to the day after {localDate(contract.ends_on)})</label><input id="x-from" type="date" value={form.data.commenced_on} onChange={(e) => form.setData('commenced_on', e.target.value)} className={FIELD} /><InputError message={form.errors.commenced_on} /></div>
            )}
        </FormModal>
    );
}
