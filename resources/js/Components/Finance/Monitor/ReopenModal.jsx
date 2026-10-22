import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const MIN = 20;

/** Request a reopen of a closed or locked period (needs approval). */
export default function ReopenModal({ period, show, onClose }) {
    const form = useForm({ reason: '' });
    const close = () => { form.reset(); form.clearErrors(); onClose(); };
    const submit = () => form.post(route('finance.periods.reopen', period.id), { preserveScroll: true, onSuccess: close });
    return (
        <FormModal show={show} onClose={close} onSubmit={submit} processing={form.processing || form.data.reason.trim().length < MIN} title={`Request reopen of ${period?.name ?? ''}`} submitLabel="Request reopen">
            <p className="text-xs text-[#4A5568]">Only the latest closed period can be reopened, and an approver other than you must agree. The closing history is kept.</p>
            <div>
                <label className={LABEL} htmlFor="ro-reason">Reason (at least {MIN} characters)</label>
                <textarea id="ro-reason" rows={4} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} />
                <p className="text-right text-[11px] text-[#718096]">{form.data.reason.trim().length}/{MIN}</p>
                <InputError message={form.errors.reason} />
            </div>
        </FormModal>
    );
}
