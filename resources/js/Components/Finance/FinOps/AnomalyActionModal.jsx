import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const COPY = {
    acknowledge: { title: 'Acknowledge anomaly', label: 'Note', field: 'note', min: 0, button: 'Acknowledge' },
    resolve: { title: 'Resolve anomaly', label: 'What caused it and what was done', field: 'note', min: 10, button: 'Resolve' },
    dismiss: { title: 'Dismiss as false positive', label: 'Why this is not a real anomaly', field: 'reason', min: 10, button: 'Dismiss' },
};

/** `action` is { kind: 'acknowledge' | 'resolve' | 'dismiss', anomaly }. Notes under 10 characters are refused for resolve and dismiss. */
export default function AnomalyActionModal({ action, onClose }) {
    const kind = action?.kind;
    const c = COPY[kind] ?? COPY.acknowledge;
    const form = useForm({ note: '', reason: '' });
    useEffect(() => { form.reset(); form.clearErrors(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [action?.anomaly?.id, kind]);
    if (!action) return null;
    const text = form.data[c.field];
    const submit = () => {
        form.transform((d) => ({ note: d[c.field] || null, reason: d[c.field] || null }));
        form.post(route(`finance.cloud.anomalies.${kind}`, action.anomaly.id), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <FormModal show onClose={onClose} onSubmit={submit} processing={form.processing || text.trim().length < c.min} title={`${c.title}: ${action.anomaly.scope_label}`} submitLabel={c.button}>
            <div>
                <label className={LABEL} htmlFor="an-t">{c.label}{c.min ? ` (at least ${c.min} characters)` : ' (optional)'}</label>
                <textarea id="an-t" rows={3} value={text} onChange={(ev) => form.setData(c.field, ev.target.value)} className={FIELD} />
                <InputError message={form.errors[c.field] || form.errors.anomaly} />
            </div>
        </FormModal>
    );
}
