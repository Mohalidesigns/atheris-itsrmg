import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';

export const resultTone = { pass: 'bg-[#D1FAE5] text-[#2D7D46]', partial: 'bg-[#FEF3C7] text-[#8A6400]', fail: 'bg-[#FEE2E2] text-[#C53030]' };
const fmt = (v) => (v ? new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

function RecordForm({ runbook }) {
    const form = useForm({ started_at: '', rto_achieved_min: '', participants: '', outcome_notes: '', lessons: '' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('dr.exercises.store', runbook.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="mt-4 space-y-2 border-t border-gray-100 pt-4">
            <h4 className="text-xs font-semibold text-[#2D3748]">Record an exercise</h4>
            <label className="block text-[11px] text-[#718096]">Started<input type="datetime-local" value={form.data.started_at} onChange={(e) => form.setData('started_at', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('started_at')}</label>
            <label className="block text-[11px] text-[#718096]">Recovered after (minutes); target {runbook.estimated_duration_min}<input type="number" min="1" value={form.data.rto_achieved_min} onChange={(e) => form.setData('rto_achieved_min', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('rto_achieved_min')}</label>
            <input value={form.data.participants} onChange={(e) => form.setData('participants', e.target.value)} placeholder="Participants, comma-separated" className="w-full rounded-lg border-gray-300 text-xs" />
            <textarea rows={2} value={form.data.outcome_notes} onChange={(e) => form.setData('outcome_notes', e.target.value)} placeholder="What happened *" className="w-full rounded-lg border-gray-300 text-xs" />{err('outcome_notes')}
            <textarea rows={2} value={form.data.lessons} onChange={(e) => form.setData('lessons', e.target.value)} placeholder="Lessons learned" className="w-full rounded-lg border-gray-300 text-xs" />
            <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Record exercise</button>
        </form>
    );
}

export default function RunbookShow({ runbook, exercises = [], canRecord = false }) {
    return (
        <AuthenticatedLayout header="DR Runbook">
            <Head title={runbook.name} />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Runbooks', href: route('dr.runbooks') }, { label: runbook.name }]}
                title={runbook.name}
                subtitle={`${runbook.category} · Owner: ${runbook.owner_role || '—'} · Recovery target ${runbook.estimated_duration_min} minutes`}
                actions={<Link href={route('dr.runbooks')} className="text-sm text-[#0A1F44] underline">← Runbooks</Link>}
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Steps</h3>
                    <ol className="mt-3 space-y-3">
                        {(runbook.steps || []).map((s, i) => (
                            <li key={i} className="flex items-start gap-3">
                                <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0">{i + 1}</span>
                                <div>
                                    <p className="text-sm font-medium text-[#2D3748]">{s.title || s}</p>
                                    {s.description && <p className="text-xs text-[#718096] mt-0.5">{s.description}</p>}
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Exercises</h3>
                    {exercises.length === 0 && <p className="text-xs text-[#718096] mt-2">No exercises yet.</p>}
                    <ul className="mt-2 divide-y divide-gray-100">
                        {exercises.map((e) => (
                            <li key={e.id} className="py-2 text-xs">
                                <div className="flex items-center justify-between">
                                    <span className="text-[#2D3748]">{fmt(e.started_at || e.scheduled_at)}</span>
                                    {e.result ? <span className={`rounded-lg px-2 py-0.5 ${resultTone[e.result]}`}>{e.result === 'pass' ? 'within RTO' : e.result === 'partial' ? 'over RTO' : 'failed RTO'}</span> : <span className="text-[#718096]">{e.status}</span>}
                                </div>
                                {e.rto_achieved_min != null && <p className="text-[#718096]">{e.rto_achieved_min} min against {e.rto_target_min} min target</p>}
                                {e.outcome_notes && <p className="text-[#4A5568]">{e.outcome_notes}</p>}
                                {e.lessons && <p className="text-[#8A6400]">Lesson: {e.lessons}</p>}
                            </li>
                        ))}
                    </ul>
                    {canRecord && <RecordForm runbook={runbook} />}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
