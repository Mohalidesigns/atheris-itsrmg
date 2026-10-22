import Chip from '@/Components/Material/Chip';
import InputError from '@/Components/InputError';
import { Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import CompleteReviewModal from '@/Components/Finance/Investments/CompleteReviewModal';
import { BTN_PRIMARY, FIELD, LABEL, REVIEW_TONES, daysLabel, diffDays, humanize, isoDate, localDate } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const OUTCOME = { under: ['success', 'Under budget'], on: ['info', 'On budget'], over: ['error', 'Over budget'] };

/** Post-implementation / value reviews: scheduled on delivery, ad hoc on demand, completed with a summary. */
export default function ValueReviewsPanel({ investment, reviews, users, options, today, can, errors = {} }) {
    const [complete, setComplete] = useState(null);
    const [edit, setEdit] = useState(null); // review | 'new'
    const [d, setD] = useState({ due_on: '', reviewer_id: '', reason: '' });
    const open = (r) => { setD({ due_on: r === 'new' ? '' : r.due_on, reviewer_id: r === 'new' ? '' : (r.reviewer?.id ?? ''), reason: '' }); setEdit(r); };
    const go = () => {
        const body = { due_on: d.due_on, reviewer_id: d.reviewer_id || null, reason: d.reason || null };
        const opts = { preserveScroll: true, onSuccess: () => setEdit(null) };
        if (edit === 'new') router.post(route('finance.value-reviews.store', investment.id), body, opts);
        else router.put(route('finance.value-reviews.update', edit.id), body, opts);
    };
    const moving = edit && edit !== 'new' && d.due_on !== edit.due_on;
    return (
        <Card title="Value reviews" right={can.addReview && <button type="button" onClick={() => open('new')} className={BTN_PRIMARY}>Add ad hoc review</button>}>
            <Table head={['Review', 'Due', 'Status', 'Reviewer', 'Benefits realised', 'Cost outcome', '']} empty={reviews.length === 0 && <Empty>No reviews yet. The 3, 6 and 12 month reviews are scheduled when delivery is accepted.</Empty>}>
                {reviews.map((r) => (
                    <tr key={r.id}>
                        <td className="whitespace-nowrap px-4 py-2 text-[#2D3748]">{options.reviewTypes?.find((t) => t.value === r.review_type)?.label ?? humanize(r.review_type)}
                            {r.summary && <details className="text-xs text-[#4A5568]"><summary className="cursor-pointer text-[#718096]">Summary</summary><p className="whitespace-pre-line">{r.summary}</p>{r.lessons && <p className="mt-1 whitespace-pre-line"><b>Lessons:</b> {r.lessons}</p>}</details>}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(r.completed_on ?? r.due_on)}{!r.completed_on && ['scheduled', 'due', 'overdue'].includes(r.status) && <span className="block text-[#718096]">{daysLabel(diffDays(r.due_on, today))}</span>}</td>
                        <td className="px-4 py-2"><Chip size="xs" tone={REVIEW_TONES[r.status] ?? 'neutral'}>{humanize(r.status)}</Chip></td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{r.reviewer?.name ?? '—'}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right text-xs tabular-nums">{r.benefits_realised_pct === null || r.benefits_realised_pct === undefined ? '—' : `${Number(r.benefits_realised_pct).toFixed(0)}%`}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{r.cost_outcome ? <Chip size="xs" tone={OUTCOME[r.cost_outcome]?.[0] ?? 'neutral'}>{OUTCOME[r.cost_outcome]?.[1] ?? r.cost_outcome}</Chip> : '—'}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                            {r.can?.complete && <button type="button" onClick={() => setComplete(r)} className="mr-3 text-[#0A1F44] underline">Complete</button>}
                            {r.can?.edit && <button type="button" onClick={() => open(r)} className="text-[#0A1F44] underline">Reschedule</button>}
                        </td>
                    </tr>
                ))}
            </Table>
            {errors.review && <p role="alert" className="px-5 py-2 text-sm text-[#B3261E]">{errors.review}</p>}
            <CompleteReviewModal show={complete !== null} onClose={() => setComplete(null)} review={complete} />
            <FormModal show={edit !== null} onClose={() => setEdit(null)} onSubmit={go} processing={!d.due_on || (moving && d.reason.trim().length < 5)} title={edit === 'new' ? 'Add ad hoc review' : 'Reschedule review'}>
                <div><label className={LABEL} htmlFor="vr-d">Due on</label><input id="vr-d" type="date" min={edit === 'new' ? today ?? isoDate(new Date()) : undefined} value={d.due_on} onChange={(e) => setD({ ...d, due_on: e.target.value })} className={FIELD} /><InputError message={errors.due_on} /></div>
                <div><label className={LABEL} htmlFor="vr-r">Reviewer</label><select id="vr-r" value={d.reviewer_id} onChange={(e) => setD({ ...d, reviewer_id: e.target.value })} className={FIELD}><option value="">Investment manager</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={errors.reviewer_id} /></div>
                {moving && <div><label className={LABEL} htmlFor="vr-w">Reason for moving the date</label><textarea id="vr-w" rows={2} value={d.reason} onChange={(e) => setD({ ...d, reason: e.target.value })} className={FIELD} /><InputError message={errors.reason} /></div>}
            </FormModal>
        </Card>
    );
}
