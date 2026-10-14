import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { fmtDateTime, ModeTag, Pill } from '@/Components/Monitoring/format';

function Settings({ test, controls, users, definition }) {
    const form = useForm({
        control_id: test.control_id ?? '', owner_id: test.owner_id ?? '', status: test.status,
        pass: test.overrides?.pass ?? '', warn: test.overrides?.warn ?? '',
    });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(route('ccm.update', test.id), { preserveScroll: true }); }} className="space-y-2">
            <label className="block text-[11px] text-[#718096]">Control it evidences
                <select value={form.data.control_id} onChange={(e) => form.setData('control_id', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs">
                    <option value="">— not linked —</option>
                    {controls.map((c) => <option key={c.id} value={c.id}>{c.control_code} · {c.title}</option>)}
                </select>{err('control_id')}
            </label>
            <label className="block text-[11px] text-[#718096]">Owner (receives the issue when it fails)
                <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs">
                    <option value="">— control owner —</option>
                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>{err('owner_id')}
            </label>
            <label className="block text-[11px] text-[#718096]">Status
                <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs">
                    {['enabled', 'paused', 'disabled'].map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            </label>
            <div className="grid grid-cols-2 gap-2">
                <label className="block text-[11px] text-[#718096]">Pass limit ({definition.unit})<input type="number" step="any" value={form.data.pass} placeholder={String(definition.pass)} onChange={(e) => form.setData('pass', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('pass')}</label>
                <label className="block text-[11px] text-[#718096]">Warn limit ({definition.unit})<input type="number" step="any" value={form.data.warn} placeholder={String(definition.warn)} onChange={(e) => form.setData('warn', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('warn')}</label>
            </div>
            <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Save</button>
        </form>
    );
}

export default function CcmShow({ test, definition, runs = [], controls = [], users = [], canEdit = false }) {
    const last = runs[0];
    const max = Math.max(...runs.map((r) => Number(r.measured_value) || 0), Number(definition.pass) || 0, 1);
    const series = [...runs].reverse().slice(-30);
    return (
        <AuthenticatedLayout header="CCM Test">
            <Head title={test.test.code} />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'CCM Console', href: route('ccm.index') }, { label: test.test.code }]}
                title={`${test.test.code} · ${test.test.title}`}
                subtitle={`Measures ${definition.metric}. ${definition.threshold}.`}
                actions={canEdit && test.status !== 'disabled' && <button onClick={() => router.post(route('ccm.run', test.id), {}, { preserveScroll: true })} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Run now</button>}
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <div className="flex flex-wrap items-center gap-3">
                            <Pill value={test.last_status} />
                            <span className="text-2xl font-bold text-[#0A1F44]">{test.last_value || '—'}</span>
                            <ModeTag mode={definition.mode} />
                            <span className="text-xs text-[#718096]">last run {fmtDateTime(test.last_run_at)} · next {fmtDateTime(test.next_run_at)} · schedule <code>{test.test.cadence_cron}</code></span>
                        </div>
                        {definition.mode === 'sandbox' && <p className="mt-2 text-xs text-[#7A5C1E]">Adapter <b>{test.test.adapter_key}</b> is not connected for this bank, so the reading comes from the sandbox catalogue. Connect the tool to measure it live.</p>}
                        {last?.findings?.length > 0 && (
                            <div className="mt-3">
                                <h4 className="text-xs font-semibold text-[#2D3748]">Findings from the last run</h4>
                                <ul className="mt-1 list-disc pl-5 text-xs text-[#4A5568]">{last.findings.map((f, i) => <li key={i}>{f}</li>)}</ul>
                            </div>
                        )}
                        {series.length > 1 && (
                            <div className="mt-4">
                                <h4 className="text-xs font-semibold text-[#2D3748]">Measured value, last {series.length} runs</h4>
                                <div className="mt-2 flex h-24 items-end gap-1">
                                    {series.map((r) => (
                                        <div key={r.id} className="flex-1" title={`${fmtDateTime(r.ran_at)}: ${r.measured_value} ${definition.unit} (${r.status})`}>
                                            <div className={`w-full rounded-t ${r.status === 'pass' ? 'bg-[#2D7D46]' : r.status === 'warn' ? 'bg-[#E5A100]' : 'bg-[#B3261E]'}`} style={{ height: `${Math.max(4, (Number(r.measured_value) / max) * 96)}px` }} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Ran</th><th className="px-3 py-2">Result</th><th className="px-3 py-2">Value</th><th className="px-3 py-2">By</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {runs.length === 0 && <tr><td colSpan={4} className="px-3 py-6 text-center text-xs text-[#718096]">Not run yet.</td></tr>}
                                {runs.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-3 py-2 text-xs text-[#4A5568]">{fmtDateTime(r.ran_at)}</td>
                                        <td className="px-3 py-2"><Pill value={r.status} /></td>
                                        <td className="px-3 py-2 text-xs text-[#2D3748]">{r.output_summary}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{r.trigger?.name || 'Scheduler'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <aside className="space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-xs space-y-2">
                        <p><span className="text-[#718096]">Frameworks:</span> {(test.test.framework_refs || []).join(' · ') || '—'}</p>
                        <p><span className="text-[#718096]">Control:</span> {test.control ? <Link href={route('controls.show', test.control.id)} className="text-[#0A1F44] underline">{test.control.control_code} {test.control.title}</Link> : 'not linked'}{test.control && <> · rated <b>{(test.control.effectiveness || 'not assessed').replace(/_/g, ' ')}</b></>}</p>
                        <p><span className="text-[#718096]">Owner:</span> {test.owner?.name || 'control owner'}</p>
                        <p><span className="text-[#718096]">Issue:</span> {test.issue ? <Link href={route('issues.show', test.issue.id)} className="text-[#0A1F44] underline">{test.issue.issue_code} ({test.issue.status.replace(/_/g, ' ')})</Link> : 'none'}</p>
                    </div>
                    {canEdit && <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5"><h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Settings</h3><Settings test={test} controls={controls} users={users} definition={definition} /></div>}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
