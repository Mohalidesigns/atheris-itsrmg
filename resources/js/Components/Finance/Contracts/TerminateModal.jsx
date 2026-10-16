import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

export default function TerminateModal({ show, onClose, contract }) {
    const form = useForm({ reason: '', terminated_on: '' });
    const submit = () => {
        form.transform((d) => ({ ...d, terminated_on: d.terminated_on || null }));
        form.post(route('finance.contracts.terminate', contract.id), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.reason.trim()} title={`Terminate ${contract.contract_code}`} submitLabel="Terminate">
            <p className="text-sm text-[#4A5568]">Early termination closes the contract and withdraws any open renewal. It cannot be undone.</p>
            <div><label className={LABEL} htmlFor="t-reason">Reason</label><textarea id="t-reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} /><InputError message={form.errors.reason} /></div>
            <div><label className={LABEL} htmlFor="t-on">Terminated on (defaults to today)</label><input id="t-on" type="date" value={form.data.terminated_on} onChange={(e) => form.setData('terminated_on', e.target.value)} className={FIELD} /><InputError message={form.errors.terminated_on} /></div>
        </FormModal>
    );
}
