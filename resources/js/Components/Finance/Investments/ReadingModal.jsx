import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Record a benefit reading for a period (one per benefit and period; recording again replaces it). Optional evidence file. */
export default function ReadingModal({ show, onClose, benefit, periods }) {
    const form = useForm({ period_id: '', value: '', note: '', file: null });
    useEffect(() => { if (show) { form.reset(); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [show, benefit?.id]);
    if (!benefit) return null;
    const submit = () => {
        form.transform((d) => ({ ...d, note: d.note || null, file: d.file || undefined }));
        form.post(route('finance.benefits.readings.store', benefit.id), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };
    const existing = benefit.readings.find((r) => String(r.period.id) === String(form.data.period_id));
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.period_id || form.data.value === ''} title={`Record reading: ${benefit.title}`} submitLabel="Record">
            <div><label className={LABEL} htmlFor="rd-p">Period</label>
                <select id="rd-p" value={form.data.period_id} onChange={(e) => form.setData('period_id', e.target.value)} className={FIELD}><option value="">Select…</option>{periods.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.fiscal_year}){p.status === 'locked' ? ' · locked' : ''}</option>)}</select>
                <InputError message={form.errors.period_id} />
                {existing && <p className="mt-1 text-xs text-[#8A6400]">This period already has a reading; saving replaces it.</p>}</div>
            <div><label className={LABEL} htmlFor="rd-v">Value ({benefit.unit === 'currency' ? benefit.currency : benefit.unit}){benefit.aggregation === 'latest' ? ', level at period end' : ', for the period'}</label>
                <input id="rd-v" type="number" step="any" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} className={FIELD} /><InputError message={form.errors.value} /></div>
            <div><label className={LABEL} htmlFor="rd-n">Note (optional)</label><input id="rd-n" maxLength={500} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={FIELD} /><InputError message={form.errors.note} /></div>
            <div><label className={LABEL} htmlFor="rd-f">Evidence file (optional)</label><input id="rd-f" type="file" onChange={(e) => form.setData('file', e.target.files[0] ?? null)} className={FIELD} /><InputError message={form.errors.file || form.errors.document_id} /></div>
        </FormModal>
    );
}
