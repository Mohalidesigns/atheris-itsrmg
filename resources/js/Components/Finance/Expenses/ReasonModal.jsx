import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Generic one-text-field action modal (reject / cancel / dispute / override / acknowledge).
 * Posts `{ [field]: text, ...extra }` to a Ziggy route; server errors show inline under the field.
 */
export default function ReasonModal({ show, onClose, title, routeName, params, field = 'reason', label = 'Reason', required = true, submitLabel = 'Confirm', extra = {}, errors = {} }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const close = () => { setText(''); onClose(); };
    const go = () => {
        setBusy(true);
        router.post(route(routeName, params), { ...extra, [field]: text || null }, { preserveScroll: true, onSuccess: close, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show={show} onClose={close} onSubmit={go} processing={busy || (required && !text.trim())} title={title} submitLabel={submitLabel}>
            <div>
                <label className={LABEL} htmlFor="reason-text">{label}{required ? ' (required)' : ' (optional)'}</label>
                <textarea id="reason-text" rows={3} value={text} onChange={(e) => setText(e.target.value)} className={FIELD} />
                <InputError message={errors[field]} />
            </div>
        </FormModal>
    );
}
