import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Complete a value review: summary (20+ characters) and lessons. Realised % and cost outcome are computed by the server. */
export default function CompleteReviewModal({ show, onClose, review, reasonMin = 20 }) {
    const form = useForm({ summary: '', lessons: '' });
    useEffect(() => { if (show) { form.reset(); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [show, review?.id]);
    if (!review) return null;
    const submit = () => { form.transform((d) => ({ ...d, lessons: d.lessons || null })); form.post(route('finance.value-reviews.complete', review.id), { preserveScroll: true, onSuccess: onClose }); };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || form.data.summary.trim().length < reasonMin} title="Complete value review" submitLabel="Complete review">
            <div><label className={LABEL} htmlFor="vr-s">Summary (at least {reasonMin} characters)</label><textarea id="vr-s" rows={4} value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} className={FIELD} /><InputError message={form.errors.summary} /></div>
            <div><label className={LABEL} htmlFor="vr-l">Lessons learned</label><textarea id="vr-l" rows={3} value={form.data.lessons} onChange={(e) => form.setData('lessons', e.target.value)} className={FIELD} /><InputError message={form.errors.lessons} /></div>
            <p className="text-xs text-[#718096]">Benefits realised and the cost outcome (estimate at completion against budget, within 5%) are calculated when you complete the review.</p>
        </FormModal>
    );
}
