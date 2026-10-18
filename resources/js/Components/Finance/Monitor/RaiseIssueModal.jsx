import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Raise the corrective action as an Issue (owned by the cost-centre owner; red is high, amber moderate). */
export default function RaiseIssueModal({ note, onClose }) {
    const form = useForm({ title: '' });
    useEffect(() => { if (note) { form.setData('title', `Budget variance: ${note.line.label}`); form.clearErrors(); } }, [note?.id]); // eslint-disable-line react-hooks/exhaustive-deps
    const submit = () => form.post(route('finance.monitor.variances.raise-issue', note.id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show={!!note} onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.title.trim()} title="Raise an issue" submitLabel="Raise issue">
            <p className="text-xs text-[#4A5568]">The issue is assigned to the cost-centre owner{note?.owner ? ` (${note.owner.name})` : ''} with {note?.severity === 'red' ? 'high' : 'moderate'} severity, and linked back to this variance ({humanize(note?.severity ?? '')}).</p>
            <div>
                <label className={LABEL} htmlFor="ri-title">Title</label>
                <input id="ri-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={FIELD} />
                <InputError message={form.errors.title} />
            </div>
        </FormModal>
    );
}
