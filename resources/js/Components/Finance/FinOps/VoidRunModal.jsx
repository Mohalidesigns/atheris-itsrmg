import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Void an exported chargeback run so the period can be exported again. Reason of at least 10 characters. */
export default function VoidRunModal({ run, onClose }) {
    const form = useForm({ reason: '' });
    useEffect(() => { form.reset(); form.clearErrors(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [run?.id]);
    if (!run) return null;
    const submit = () => form.post(route('finance.chargeback.void', run.id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show onClose={onClose} onSubmit={submit} processing={form.processing || form.data.reason.trim().length < 10} title={`Void ${run.run_code}`} submitLabel="Void run">
            <div><label className={LABEL} htmlFor="vr-r">Reason (at least 10 characters)</label><textarea id="vr-r" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} /><InputError message={form.errors.reason || form.errors.run} /></div>
            <p className="text-xs text-[#718096]">The file stays on record. Voiding lets the period be exported again after a correction.</p>
        </FormModal>
    );
}
