import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const MIN = 20;

/** Explain a variance: explanation (at least 20 characters) and an optional corrective action. */
export default function ExplainModal({ note, onClose }) {
    const form = useForm({ explanation: '', corrective_action: '' });
    useEffect(() => { if (note) { form.setData({ explanation: note.explanation ?? '', corrective_action: note.corrective_action ?? '' }); form.clearErrors(); } }, [note?.id]); // eslint-disable-line react-hooks/exhaustive-deps
    const submit = () => form.post(route('finance.monitor.variances.explain', note.id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show={!!note} onClose={onClose} onSubmit={submit} processing={form.processing || form.data.explanation.trim().length < MIN} title={note ? `Explain variance: ${note.line.label}` : ''} submitLabel="Save explanation">
            {note?.status === 'acknowledged' && <p className="rounded-lg bg-[#FCEBC4] px-3 py-2 text-xs text-[#5F4300]">Explaining again resets the acknowledgement.</p>}
            <div>
                <label className={LABEL} htmlFor="ex-text">Explanation (at least {MIN} characters)</label>
                <textarea id="ex-text" rows={4} value={form.data.explanation} onChange={(e) => form.setData('explanation', e.target.value)} className={FIELD} />
                <p className="text-right text-[11px] text-[#718096]">{form.data.explanation.trim().length}/{MIN}</p>
                <InputError message={form.errors.explanation} />
            </div>
            <div>
                <label className={LABEL} htmlFor="ex-action">Corrective action (optional)</label>
                <textarea id="ex-action" rows={2} value={form.data.corrective_action} onChange={(e) => form.setData('corrective_action', e.target.value)} className={FIELD} />
                <InputError message={form.errors.corrective_action} />
            </div>
        </FormModal>
    );
}
