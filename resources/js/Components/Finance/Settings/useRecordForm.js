import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Modal create/edit helper. `target` is null (closed), 'new' or the row being edited.
 * `toData(row)` maps a row to form data; `transform` can reshape data before sending.
 */
export default function useRecordForm({ blank, toData, storeRoute, updateRoute, transform }) {
    const form = useForm(blank);
    const [target, setTarget] = useState(null);
    const openNew = (overrides = {}) => { form.setData({ ...blank, ...overrides }); form.clearErrors(); setTarget('new'); };
    const openEdit = (row) => { form.setData(toData ? toData(row) : { ...blank, ...row }); form.clearErrors(); setTarget(row); };
    const close = () => { setTarget(null); form.clearErrors(); };
    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: close };
        if (transform) form.transform(transform);
        if (target === 'new') form.post(route(storeRoute), opts);
        else form.put(route(updateRoute, target.id), opts);
    };
    return { form, target, isNew: target === 'new', openNew, openEdit, close, submit };
}

export function destroy(routeName, id, message) {
    if (confirm(message)) router.delete(route(routeName, id), { preserveScroll: true });
}
