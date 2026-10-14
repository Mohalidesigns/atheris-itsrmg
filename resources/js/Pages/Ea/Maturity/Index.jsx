import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link, useForm } from '@inertiajs/react';
import { fmtDate, Pill } from '@/Components/Monitoring/format';

export const levelColour = ['#E2E8F0', '#C53030', '#DD6B20', '#D69E2E', '#2F855A', '#0A1F44'];
export const statusTone = { draft: 'bg-[#FEF3C7] text-[#8A6400]', submitted: 'bg-[#DBEAFE] text-[#1E40AF]', approved: 'bg-[#D1FAE5] text-[#2D7D46]', superseded: 'bg-gray-100 text-[#4A5568]' };

/** Five cells filled to the level, with the target outlined. */
export function LevelBar({ level, target, indicative }) {
    return (
        <div className="flex items-center gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
                <div key={n} title={`Level ${n}`} className={`relative h-5 w-7 rounded ${n === target ? 'ring-2 ring-[#C9A86A] ring-offset-1' : ''}`} style={{ background: level && n <= level ? levelColour[level] : '#EDF2F7' }}>
                    {indicative === n && <span className="absolute -bottom-3 left-1/2 -translate-x-1/2 text-[9px] text-[#718096]">▲</span>}
                </div>
            ))}
        </div>
    );
}

function Start({ defaultTarget }) {
    const form = useForm({ year: new Date().getFullYear(), title: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('ea.maturity.store')); }} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="text-sm font-semibold text-[#2D3748]">Start an assessment</h3>
            <p className="mt-1 text-xs text-[#718096]">Rate 25 practice areas across the five domains. Targets default to level {defaultTarget}, the ITSB target for the bank's category.</p>
            <div className="mt-3 flex gap-2">
                <input type="number" value={form.data.year} onChange={(e) => form.setData('year', e.target.value)} className="w-24 rounded-lg border-gray-300 text-sm" />
                <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Title (optional)" className="flex-1 rounded-lg border-gray-300 text-sm" />
                <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Start</button>
            </div>
            {(form.errors.assessment || form.errors.year) && <p className="mt-1 text-[11px] text-[#C53030]">{form.errors.assessment || form.errors.year}</p>}
        </form>
    );
}

export default function MaturityIndex({ domains = [], current = null, open = null, history = [], assessments = [], evidence = {}, levels = {}, defaultTarget = 3, can = {} }) {
    const results = current?.results?.domains || {};
    return (
        <AuthenticatedLayout header="EA Maturity">
            <Head title="EA Maturity (CMMI)" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'EA Maturity (CMMI)' }]}
                title="Enterprise Architecture Maturity"
                subtitle="The CBN IT blueprint's five EA domains rated on the CMMI scale. A domain's level is its weakest practice area; ▲ marks what the repository's own documentation supports, the gold outline the target."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.cbn-maturity')} current="ea.cbn-maturity" />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{current ? `Rated maturity — ${current.title}` : 'Rated maturity'}</h3>
                                {current && <p className="text-xs text-[#718096]">Approved by {current.approver || '—'} on {fmtDate(current.approved_at)}</p>}
                            </div>
                            {current?.results?.overall != null && <div className="text-right"><p className="text-3xl font-bold text-[#0A1F44]">{current.results.overall}</p><p className="text-[10px] text-[#718096]">overall · {current.results.at_target}/{domains.length} domains at target</p></div>}
                        </div>
                        {!current && <p className="mb-3 rounded-lg bg-[#FEF3C7] px-3 py-2 text-xs text-[#8A6400]">No approved assessment yet. The bars below show only what the repository documents.</p>}
                        <table className="min-w-full text-sm">
                            <thead><tr className="text-left text-[11px] uppercase text-[#718096]"><th className="py-2">Domain</th><th className="py-2">Level</th><th className="py-2 text-center">Rated</th><th className="py-2 text-center">Documented</th><th className="py-2 text-center">Target</th><th className="py-2 text-center">Gap</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {domains.map((d) => {
                                    const r = results[d.code];
                                    const doc = evidence[d.code];
                                    const target = r?.target ?? defaultTarget;
                                    return (
                                        <tr key={d.code}>
                                            <td className="py-3 pr-3"><p className="font-medium text-[#0A1F44]">{d.name}</p><p className="text-[11px] text-[#718096]">{d.description}</p></td>
                                            <td className="py-3 pr-3"><LevelBar level={r?.level} target={target} indicative={doc?.level} /></td>
                                            <td className="py-3 text-center font-semibold text-[#0A1F44]">{r?.level ?? '—'}{r?.overstated && <span title="Rated two or more levels above the documentation" className="ml-1 text-[#C53030]">!</span>}</td>
                                            <td className="py-3 text-center text-[#4A5568]">{doc?.level ?? 0}<span className="block text-[10px] text-[#A0AEC0]">{doc?.score ?? 0}% documented</span></td>
                                            <td className="py-3 text-center text-[#4A5568]">{target}</td>
                                            <td className={`py-3 text-center font-semibold ${r?.gap ? 'text-[#C53030]' : 'text-[#2F855A]'}`}>{r ? (r.gap ? `−${r.gap}` : 'met') : '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {history.length > 1 && (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">Trend between approved assessments</h3>
                            <table className="min-w-full text-xs">
                                <thead><tr className="text-left text-[11px] uppercase text-[#718096]"><th className="py-1">Domain</th>{history.map((h) => <th key={h.id} className="py-1 text-center">{h.year}</th>)}</tr></thead>
                                <tbody className="divide-y divide-gray-100">
                                    {domains.map((d) => (
                                        <tr key={d.code}><td className="py-1.5 text-[#2D3748]">{d.name}</td>{history.map((h) => <td key={h.id} className="py-1.5 text-center font-semibold" style={{ color: levelColour[h.domains[d.code] || 0] }}>{h.domains[d.code] ?? '—'}</td>)}</tr>
                                    ))}
                                    <tr><td className="py-1.5 font-semibold text-[#0A1F44]">Overall</td>{history.map((h) => <td key={h.id} className="py-1.5 text-center font-semibold text-[#0A1F44]">{h.overall ?? '—'}</td>)}</tr>
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white shadow-sm overflow-x-auto">
                        <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Assessments</h3>
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Assessment</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Overall</th><th className="px-3 py-2">Approved</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {assessments.length === 0 && <tr><td colSpan={4} className="px-3 py-6 text-center text-xs text-[#718096]">No assessments yet.</td></tr>}
                                {assessments.map((a) => (
                                    <tr key={a.id}>
                                        <td className="px-3 py-2"><Link href={route('ea.maturity.show', a.id)} className="text-[#0A1F44] hover:underline">{a.title}</Link><p className="text-xs text-[#718096]">{a.year} · started by {a.creator || '—'}</p></td>
                                        <td className="px-3 py-2"><Pill value={a.status} tones={statusTone} /></td>
                                        <td className="px-3 py-2 text-[#0A1F44]">{a.overall_score ?? '—'}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{a.approver ? `${a.approver}, ${fmtDate(a.approved_at)}` : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside className="space-y-4">
                    {open && (
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#2D3748]">In progress</h3><Pill value={open.status} tones={statusTone} /></div>
                            <Link href={route('ea.maturity.show', open.id)} className="mt-1 block text-sm text-[#0A1F44] underline">{open.title}</Link>
                            <div className="mt-2 h-2 overflow-hidden rounded bg-gray-100"><div className="h-2 bg-[#0A1F44]" style={{ width: `${Math.round((open.progress.answered / Math.max(1, open.progress.total)) * 100)}%` }} /></div>
                            <p className="mt-1 text-[11px] text-[#718096]">{open.progress.answered} of {open.progress.total} practice areas rated</p>
                        </div>
                    )}
                    {can.start && <Start defaultTarget={defaultTarget} />}
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#2D3748]">CMMI levels</h3>
                        <ul className="mt-2 space-y-2 text-xs">
                            {Object.entries(levels).map(([n, l]) => (
                                <li key={n} className="flex gap-2"><span className="mt-0.5 h-4 w-4 shrink-0 rounded" style={{ background: levelColour[n] }} /><span><b className="text-[#2D3748]">{n} · {l.name}</b> <span className="text-[#A0AEC0]">(ITSB: {l.itsb})</span><br /><span className="text-[#718096]">{l.descriptor}</span></span></li>
                            ))}
                        </ul>
                        <p className="mt-3 text-[11px] text-[#718096]">ITSB targets: Category One institutions level 3 (Defined), Category Two level 2 (Repeatable).</p>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
