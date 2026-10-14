import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDate, fmtDateTime, Pill } from '@/Components/Monitoring/format';
import { LevelBar, levelColour, statusTone } from '@/Pages/Ea/Maturity/Index';

function Practice({ assessment, q, response, evidence, levels, rules, canAnswer }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        level: response?.selected_level ?? '', comment: response?.comment ?? '', evidence_note: response?.evidence_note ?? '',
        evidence_refs: (response?.evidence_refs || []).map((r) => r.key),
    });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const toggle = (key) => form.setData('evidence_refs', form.data.evidence_refs.includes(key) ? form.data.evidence_refs.filter((k) => k !== key) : [...form.data.evidence_refs, key]);
    const lvl = Number(form.data.level) || 0;
    return (
        <li className="py-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-2xl">
                    <p className="font-mono text-xs text-[#C9A86A]">{q.code}</p>
                    <p className="text-sm text-[#2D3748]">{q.question}</p>
                    <p className="mt-0.5 text-[11px] text-[#718096]">Evidence: {q.guidance}</p>
                </div>
                <div className="text-right">
                    {response ? <span className="inline-block rounded px-2 py-1 text-sm font-bold text-white" style={{ background: levelColour[response.selected_level] }}>L{response.selected_level} · {levels[response.selected_level]?.name}</span> : <span className="text-xs text-[#A0AEC0]">not rated</span>}
                    <p className="mt-1 text-[10px] text-[#718096]">repository evidence {evidence?.score ?? 0}%</p>
                </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
                {(evidence?.indicators || []).map((i) => (
                    <span key={i.key} className="rounded border border-gray-200 px-2 py-0.5 text-[11px] text-[#4A5568]">
                        {i.route ? <Link href={route(i.route)} className="hover:underline">{i.label}</Link> : i.label}: <b>{i.value}</b>
                    </span>
                ))}
            </div>
            {response && !editing && (
                <div className="mt-2 text-xs text-[#4A5568]">
                    {response.evidence_note && <p><b>Evidence:</b> {response.evidence_note}</p>}
                    {response.evidence_refs?.length > 0 && <p><b>Cited:</b> {response.evidence_refs.map((r) => `${r.label} (${r.value})`).join('; ')}</p>}
                    {response.comment && <p><b>Comment:</b> {response.comment}</p>}
                    <p className="text-[#A0AEC0]">{response.answerer?.name}, {fmtDateTime(response.answered_at)}</p>
                </div>
            )}
            {canAnswer && !editing && <button onClick={() => setEditing(true)} className="mt-2 text-xs text-[#0A1F44] underline">{response ? 'Change rating' : 'Rate this practice'}</button>}
            {canAnswer && editing && (
                <form onSubmit={(e) => { e.preventDefault(); form.post(route('ea.maturity.answer', [assessment.id, q.id]), { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="mt-3 space-y-2 rounded-lg bg-[#F7FAFC] p-3">
                    <div className="grid grid-cols-1 gap-1 sm:grid-cols-5">
                        {Object.entries(levels).map(([n, l]) => (
                            <label key={n} className={`cursor-pointer rounded-lg border p-2 text-[11px] ${String(form.data.level) === n ? 'border-[#0A1F44] bg-white' : 'border-gray-200'}`}>
                                <input type="radio" name={`level-${q.id}`} value={n} checked={String(form.data.level) === n} onChange={() => form.setData('level', n)} className="mr-1" />
                                <b>{n} · {l.name}</b><span className="block text-[#718096]">{l.descriptor}</span>
                            </label>
                        ))}
                    </div>
                    {err('level')}
                    {lvl >= rules.note && <><textarea rows={2} value={form.data.evidence_note} onChange={(e) => form.setData('evidence_note', e.target.value)} placeholder="Evidence: what shows this practice is standard across the bank (documents, owners, review dates) *" className="w-full rounded-lg border-gray-300 text-xs" />{err('evidence_note')}</>}
                    {lvl >= rules.measured && (
                        <div className="text-[11px] text-[#4A5568]">
                            <p className="mb-1">Cite the measured indicators that support level {lvl} *</p>
                            {(evidence?.indicators || []).map((i) => (
                                <label key={i.key} className="mr-3 inline-flex items-center gap-1"><input type="checkbox" checked={form.data.evidence_refs.includes(i.key)} onChange={() => toggle(i.key)} className="rounded border-gray-300" />{i.label} ({i.value})</label>
                            ))}
                            {err('evidence_refs')}
                        </div>
                    )}
                    <textarea rows={2} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} placeholder="Comment / improvement actions" className="w-full rounded-lg border-gray-300 text-xs" />
                    <div className="flex gap-2"><button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white">Save rating</button><button type="button" onClick={() => setEditing(false)} className="text-xs text-[#718096]">Cancel</button></div>
                </form>
            )}
        </li>
    );
}

function Approve({ assessment, results }) {
    const form = useForm({ approval_note: '' });
    const flagged = Object.values(results.domains).filter((d) => d.overstated);
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm space-y-2">
            <h3 className="text-sm font-semibold text-[#2D3748]">Approve or return</h3>
            {flagged.length > 0 && <p className="rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">Rated two or more levels above the repository's documentation: {flagged.map((d) => d.name).join(', ')}. Record why you accept the rating.</p>}
            <textarea rows={3} value={form.data.approval_note} onChange={(e) => form.setData('approval_note', e.target.value)} placeholder="Approval note, or what needs to change before approval" className="w-full rounded-lg border-gray-300 text-xs" />
            {form.errors.approval_note && <p className="text-[11px] text-[#C53030]">{form.errors.approval_note}</p>}
            <div className="flex gap-2">
                <button onClick={() => form.post(route('ea.maturity.approve', assessment.id), { preserveScroll: true })} disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white">Approve</button>
                <button onClick={() => form.post(route('ea.maturity.return', assessment.id), { preserveScroll: true })} disabled={form.processing} className="rounded-lg border border-[#0A1F44] px-3 py-2 text-xs font-semibold text-[#0A1F44]">Return to assessor</button>
            </div>
        </div>
    );
}

function Targets({ assessment, domains }) {
    const form = useForm({ targets: { ...(assessment.targets || {}) } });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(route('ea.maturity.targets', assessment.id), { preserveScroll: true }); }} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm space-y-2">
            <h3 className="text-sm font-semibold text-[#2D3748]">Target levels</h3>
            {domains.map((d) => (
                <label key={d.code} className="flex items-center justify-between text-xs text-[#4A5568]">{d.name}
                    <select value={form.data.targets[d.code] ?? 3} onChange={(e) => form.setData('targets', { ...form.data.targets, [d.code]: Number(e.target.value) })} className="rounded-lg border-gray-300 text-xs">{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select>
                </label>
            ))}
            <button disabled={form.processing} className="w-full rounded-lg border border-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-[#0A1F44]">Save targets</button>
        </form>
    );
}

export default function MaturityShow({ assessment, domains = [], responses = {}, results, evidence = {}, levels = {}, rules = {}, can = {} }) {
    const errors = usePage().props.errors || {};
    const [open, setOpen] = useState(domains[0]?.code);
    return (
        <AuthenticatedLayout header="EA Maturity">
            <Head title={assessment.title} />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'EA Maturity (CMMI)', href: route('ea.cbn-maturity') }, { label: String(assessment.year) }]}
                title={assessment.title}
                subtitle={`Started by ${assessment.creator?.name || '—'}${assessment.submitter ? ` · submitted by ${assessment.submitter.name} ${fmtDate(assessment.submitted_at)}` : ''}${assessment.approver ? ` · approved by ${assessment.approver.name} ${fmtDate(assessment.approved_at)}` : ''}`}
                actions={<div className="flex items-center gap-2"><Pill value={assessment.status} tones={statusTone} />{can.answer && <button onClick={() => router.post(route('ea.maturity.submit', assessment.id), {}, { preserveScroll: true })} disabled={results.answered < results.total} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-40">Submit for approval</button>}</div>}
            />
            {errors.assessment && <p className="mb-3 rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">{errors.assessment}</p>}
            {assessment.approval_note && <p className="mb-3 rounded-lg bg-[#F7FAFC] px-3 py-2 text-xs text-[#4A5568]"><b>Approver's note:</b> {assessment.approval_note}</p>}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-3 lg:col-span-2">
                    {domains.map((d) => {
                        const r = results.domains[d.code];
                        return (
                            <div key={d.code} className="rounded-xl border border-gray-100 bg-white shadow-sm">
                                <button onClick={() => setOpen(open === d.code ? null : d.code)} className="flex w-full flex-wrap items-center justify-between gap-3 p-4 text-left">
                                    <div><p className="text-sm font-semibold text-[#0A1F44]">{d.name}</p><p className="text-[11px] text-[#718096]">{r.answered}/{r.total} rated · average {r.average ?? '—'} · documentation supports {r.indicative}</p></div>
                                    <div className="flex items-center gap-3"><LevelBar level={r.level} target={r.target} indicative={r.indicative} />{r.overstated && <span className="text-xs font-semibold text-[#C53030]">overstated?</span>}</div>
                                </button>
                                {open === d.code && (
                                    <ul className="divide-y divide-gray-100 border-t border-gray-100 px-4">
                                        {d.questions.map((q) => <Practice key={q.id} assessment={assessment} q={q} response={responses[q.id]} evidence={evidence[q.code]} levels={levels} rules={rules} canAnswer={can.answer} />)}
                                    </ul>
                                )}
                            </div>
                        );
                    })}
                </div>
                <aside className="space-y-4">
                    {['submitted', 'approved'].includes(assessment.status) && <ApprovalTrail process="ea.maturity" id={assessment.id} />}
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Results</h3>
                        <p className="mt-1 text-3xl font-bold text-[#0A1F44]">{results.overall ?? '—'}</p>
                        <p className="text-[11px] text-[#718096]">overall · {results.answered}/{results.total} rated · {results.at_target} domains at target</p>
                        <ul className="mt-3 space-y-1 text-xs">
                            {Object.values(results.domains).map((d) => (
                                <li key={d.code} className="flex justify-between"><span className="text-[#4A5568]">{d.name}</span><span className="font-semibold" style={{ color: levelColour[d.level || 0] }}>{d.level ?? '—'} / {d.target}{d.gap ? <span className="text-[#C53030]"> (−{d.gap})</span> : ''}</span></li>
                            ))}
                        </ul>
                        <p className="mt-3 text-[11px] text-[#718096]">A domain's level is its lowest-rated practice (CMMI staged rating). Level {rules.note}+ needs an evidence note; level {rules.measured}+ also cites measured repository indicators.</p>
                    </div>
                    {can.approve && <Approve assessment={assessment} results={results} />}
                    {can.answer && <Targets assessment={assessment} domains={domains} />}
                    {assessment.status === 'submitted' && !can.approve && <p className="rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#718096] shadow-sm">Submitted. Someone other than the submitter, with EA approval rights, approves it.</p>}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
