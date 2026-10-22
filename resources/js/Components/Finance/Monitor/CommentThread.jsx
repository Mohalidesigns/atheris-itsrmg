import InputError from '@/Components/InputError';
import { BTN_PRIMARY, FIELD } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { useForm } from '@inertiajs/react';

/** Commentary thread under a variance; posts to finance.monitor.variances.comment. */
export default function CommentThread({ note }) {
    const form = useForm({ body: '' });
    const submit = (e) => { e.preventDefault(); form.post(route('finance.monitor.variances.comment', note.id), { preserveScroll: true, onSuccess: () => form.reset('body') }); };
    return (
        <div className="space-y-2">
            {note.comments.length === 0 && <p className="text-xs text-[#718096]">No comments yet.</p>}
            <ul className="space-y-2">
                {note.comments.map((c) => (
                    <li key={c.id} className="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                        <p className="text-[11px] text-[#718096]"><b className="text-[#2D3748]">{c.author}</b> · {formatDate(c.at)}</p>
                        <p className="whitespace-pre-line text-[#2D3748]">{c.body}</p>
                    </li>
                ))}
            </ul>
            {note.can.comment && (
                <form onSubmit={submit} className="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div className="flex-1">
                        <label htmlFor={`cm-${note.id}`} className="sr-only">Add a comment</label>
                        <textarea id={`cm-${note.id}`} rows={2} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder="Add a comment" className={FIELD.replace('mt-1 ', '')} />
                        <InputError message={form.errors.body} />
                    </div>
                    <button disabled={form.processing || !form.data.body.trim()} className={BTN_PRIMARY}>Comment</button>
                </form>
            )}
        </div>
    );
}
