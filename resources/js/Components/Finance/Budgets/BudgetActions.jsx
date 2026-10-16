import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL } from '@/Utils/finance';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Note modal for approve / return / reject style decisions. `lines` enables per-line comments
 * (budget return / reject). `onSubmit(payload, finish)` posts it.
 */
export function DecisionModal({ show, title, noteRequired, lines, onClose, onSubmit, errors = {}, submitLabel = 'Confirm' }) {
    const [note, setNote] = useState('');
    const [comments, setComments] = useState([]);
    const [busy, setBusy] = useState(false);
    const reset = () => { setNote(''); setComments([]); };
    const close = () => { reset(); onClose(); };
    const go = () => {
        setBusy(true);
        onSubmit({ note: note || null, ...(lines ? { line_comments: comments.filter((c) => c.body.trim()).map((c) => ({ line_id: c.line_id || null, body: c.body })) } : {}) },
            { onSuccess: () => { reset(); onClose(); }, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show={show} onClose={close} onSubmit={go} processing={busy || (noteRequired && !note.trim())} title={title} submitLabel={submitLabel}>
            <div><label className={LABEL} htmlFor="decision-note">Note{noteRequired ? ' (required)' : ' (optional)'}</label>
                <textarea id="decision-note" rows={3} value={note} onChange={(e) => setNote(e.target.value)} className={FIELD} />
                <InputError message={errors.note} /></div>
            {lines && (
                <div>
                    <p className={LABEL}>Line comments</p>
                    {comments.map((c, i) => (
                        <div key={i} className="mt-2 flex gap-2">
                            <select aria-label="Line" value={c.line_id} onChange={(e) => setComments(comments.map((x, j) => (j === i ? { ...x, line_id: e.target.value } : x)))} className="w-40 rounded-lg border border-gray-200 px-2 py-1 text-sm">
                                <option value="">Whole budget</option>{lines.map((l) => <option key={l.id} value={l.id}>#{l.line_no} {l.description}</option>)}
                            </select>
                            <input aria-label="Comment" value={c.body} onChange={(e) => setComments(comments.map((x, j) => (j === i ? { ...x, body: e.target.value } : x)))} className="min-w-0 flex-1 rounded-lg border border-gray-200 px-2 py-1 text-sm" />
                            <button type="button" onClick={() => setComments(comments.filter((_, j) => j !== i))} className="text-xs text-[#B3261E] underline">Remove</button>
                        </div>
                    ))}
                    <button type="button" onClick={() => setComments([...comments, { line_id: '', body: '' }])} className="mt-2 rounded-lg border border-gray-200 px-3 py-1 text-xs">Add line comment</button>
                </div>
            )}
        </FormModal>
    );
}

export default function BudgetActions({ budget, can, lines, errors }) {
    const [decision, setDecision] = useState(null);
    const post = (name, confirmText) => () => {
        if (confirmText && !confirm(confirmText)) return;
        router.post(route(name, budget.id), {}, { preserveScroll: true });
    };
    const decide = (payload, opts) => router.post(route(`finance.budgets.${decision.action}`, budget.id), payload, { preserveScroll: true, ...opts });
    return (
        <div className="mb-4">
            <div className="flex flex-wrap items-center gap-2">
                {can.edit && <Link href={route('finance.budgets.edit', budget.id)} className={BTN_SECONDARY}>Open workbook</Link>}
                {can.submit && <button type="button" onClick={post('finance.budgets.submit', 'Submit this budget for approval?')} className={BTN_PRIMARY}>Submit for approval</button>}
                {can.withdraw && <button type="button" onClick={post('finance.budgets.withdraw', 'Withdraw this budget back to draft?')} className={BTN_SECONDARY}>Withdraw</button>}
                {can.approve && <button type="button" onClick={() => setDecision({ action: 'approve', title: 'Approve budget', noteRequired: false })} className={BTN_PRIMARY}>Approve</button>}
                {can.return && <button type="button" onClick={() => setDecision({ action: 'return', title: 'Return to draft', noteRequired: true, lines: true })} className={BTN_SECONDARY}>Return</button>}
                {can.reject && <button type="button" onClick={() => setDecision({ action: 'reject', title: 'Reject budget', noteRequired: true, lines: true })} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                {can.baseline && <button type="button" onClick={post('finance.budgets.baseline', `Make ${budget.version_label} the baseline? It becomes the reference for actuals.`)} className={BTN_PRIMARY}>Set as baseline</button>}
                {can.revise && <button type="button" onClick={post('finance.budgets.revise', 'Create a new draft version from this one?')} className={BTN_SECONDARY}>Create revision</button>}
                {can.export && <a href={route('finance.budgets.workbook', { budget: budget.id, format: 'csv' })} className={BTN_SECONDARY}>Export CSV</a>}
            </div>
            {errors?.approval && <p className="mt-2 text-sm text-[#B3261E]">{errors.approval}</p>}
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.noteRequired} lines={decision.lines ? lines : null} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title.split(' ')[0]} />}
        </div>
    );
}
