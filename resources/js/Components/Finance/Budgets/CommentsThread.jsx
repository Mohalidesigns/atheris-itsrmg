import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD, LABEL } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { useForm } from '@inertiajs/react';

export default function CommentsThread({ budget, comments, lines, can }) {
    const form = useForm({ body: '', budget_line_id: '' });
    const lineNo = (id) => lines.find((l) => l.id === id)?.line_no;
    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, budget_line_id: d.budget_line_id || null }));
        form.post(route('finance.budgets.comments.store', budget.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <Card title="Comments">
            {comments.length === 0 ? <Empty>No comments yet.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {comments.map((c) => (
                        <li key={c.id} className="px-5 py-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2 text-xs text-[#718096]">
                                <span className="font-medium text-[#0A1F44]">{c.user?.name ?? 'Unknown'}</span>
                                <span>{formatDate(c.created_at, true)}</span>
                                {c.kind && c.kind !== 'comment' && <Chip size="xs" tone={c.kind === 'rejection' ? 'error' : 'warning'}>{c.kind}</Chip>}
                                {c.budget_line_id && <Chip size="xs" tone="neutral">Line {lineNo(c.budget_line_id) ?? c.budget_line_id}</Chip>}
                            </div>
                            <p className="mt-1 whitespace-pre-wrap text-[#2D3748]">{c.body}</p>
                        </li>
                    ))}
                </ul>
            )}
            {can.comment && (
                <form onSubmit={submit} className="space-y-2 border-t border-gray-100 px-5 py-4">
                    <div><label className={LABEL} htmlFor="c-line">About line (optional)</label>
                        <select id="c-line" value={form.data.budget_line_id} onChange={(e) => form.setData('budget_line_id', e.target.value)} className={FIELD}>
                            <option value="">Whole budget</option>
                            {lines.map((l) => <option key={l.id} value={l.id}>#{l.line_no} {l.description}</option>)}
                        </select></div>
                    <div><label className={LABEL} htmlFor="c-body">Comment</label>
                        <textarea id="c-body" rows={3} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className={FIELD} />
                        <InputError message={form.errors.body} /></div>
                    <button disabled={form.processing || !form.data.body.trim()} className={BTN_PRIMARY}>Add comment</button>
                </form>
            )}
        </Card>
    );
}
