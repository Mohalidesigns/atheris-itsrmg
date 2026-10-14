import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDate, fmtDateTime, Pill } from '@/Components/Monitoring/format';
import { fmtNum, kriTone, limits, unitLabel, withUnit } from '@/Pages/Kri/Index';

function RecordReading({ kri }) {
    const form = useForm({ value: '', commentary: '', recorded_at: '' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('kri.readings.store', kri.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="space-y-2">
            <label className="block text-[11px] text-[#718096]">Value{unitLabel(kri.unit) ? ` (${unitLabel(kri.unit)})` : ''} for {kri.period} *<input type="number" step="any" min="0" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('value')}</label>
            <textarea rows={3} value={form.data.commentary} onChange={(e) => form.setData('commentary', e.target.value)} placeholder="Commentary (required when amber or red): cause and response" className="w-full rounded-lg border-gray-300 text-xs" />{err('commentary')}
            <label className="block text-[11px] text-[#718096]">Measured on (leave blank for today)<input type="date" value={form.data.recorded_at} onChange={(e) => form.setData('recorded_at', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('recorded_at')}</label>
            <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Record reading</button>
        </form>
    );
}

function Acknowledge({ breach }) {
    const form = useForm({ action_plan: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('kri.breaches.acknowledge', breach.id), { preserveScroll: true }); }} className="mt-2 space-y-1">
            <textarea rows={2} value={form.data.action_plan} onChange={(e) => form.setData('action_plan', e.target.value)} placeholder="Action plan: what will bring this KRI back within appetite, by when *" className="w-full rounded-lg border-gray-300 text-xs" />
            {form.errors.action_plan && <p className="text-[11px] text-[#C53030]">{form.errors.action_plan}</p>}
            <button type="submit" disabled={form.processing} className="rounded-lg border border-[#0A1F44] px-3 py-1 text-[11px] font-semibold text-[#0A1F44]">Record action plan</button>
        </form>
    );
}

function Definition({ kri, users, risks, options }) {
    const form = useForm({
        code: kri.code, name: kri.name, description: kri.description || '', category: kri.category, unit: kri.unit, direction: kri.direction,
        threshold_green: kri.threshold_green, threshold_amber: kri.threshold_amber, threshold_red: kri.threshold_red,
        frequency: kri.frequency, owner_id: kri.owner_id ?? '', risk_id: kri.risk_id ?? '',
    });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const input = 'mt-1 w-full rounded-lg border-gray-300 text-xs';
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(route('kri.update', kri.id), { preserveScroll: true }); }} className="space-y-2">
            <label className="block text-[11px] text-[#718096]">Owner<select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className={input}><option value="">— none —</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>{err('owner_id')}</label>
            <label className="block text-[11px] text-[#718096]">Risk it indicates<select value={form.data.risk_id} onChange={(e) => form.setData('risk_id', e.target.value)} className={input}><option value="">— none —</option>{risks.map((r) => <option key={r.id} value={r.id}>{r.risk_id_code} · {r.title}</option>)}</select>{err('risk_id')}</label>
            <label className="block text-[11px] text-[#718096]">Frequency<select value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} className={input}>{options.frequencies.map((f) => <option key={f}>{f}</option>)}</select></label>
            <label className="block text-[11px] text-[#718096]">Direction<select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)} className={input}><option value="higher_worse">higher is worse</option><option value="lower_worse">lower is worse</option></select></label>
            <div className="grid grid-cols-3 gap-2">
                {[['threshold_green', 'Green'], ['threshold_amber', 'Amber'], ['threshold_red', 'Severe']].map(([k, l]) => (
                    <label key={k} className="text-[11px] text-[#718096]">{l}<input type="number" step="any" value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} className={input} /></label>
                ))}
            </div>
            {err('threshold_amber')}{err('code')}
            <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Save definition</button>
        </form>
    );
}

export default function KriShow({ kri, readings = [], breaches = [], users = [], risks = [], options = {}, can = {} }) {
    const errors = usePage().props.errors || {};
    const [tab, setTab] = useState('reading');
    const latest = readings[0];
    const series = [...readings].reverse().slice(-26);
    const max = Math.max(...series.map((r) => Number(r.value)), Number(kri.threshold_amber) || 0, Number(kri.threshold_red) || 0, 1);
    const line = (v) => `${100 - (Number(v) / max) * 100}%`;
    return (
        <AuthenticatedLayout header="KRI Dashboard">
            <Head title={kri.code} />
            <PageHeader
                breadcrumbs={[{ label: 'KRIs & Dashboards' }, { label: 'KRI Dashboard', href: route('kri.index') }, { label: kri.code }]}
                title={`${kri.code} · ${kri.name}`}
                subtitle={`${kri.category} · ${kri.frequency} · ${options.sources?.[kri.source]} · ${limits(kri)}${unitLabel(kri.unit) ? ` (${unitLabel(kri.unit)})` : ''}`}
                actions={can.record && kri.source === 'platform' && kri.measurable && <button onClick={() => router.post(route('kri.measure', kri.id), {}, { preserveScroll: true })} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Measure now</button>}
            />
            {(errors.kri || errors.value) && <p className="mb-3 rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">{errors.kri || errors.value}</p>}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <div className="flex flex-wrap items-center gap-3">
                            {latest && <Pill value={latest.status} tones={kriTone} />}
                            <span className="text-2xl font-bold text-[#0A1F44]">{latest ? withUnit(latest.value, kri.unit) : 'No reading yet'}</span>
                            {latest && <span className="text-xs text-[#718096]">{latest.period} · {fmtDateTime(latest.recorded_at)} · {latest.recorder?.name || 'measured automatically'}</span>}
                            {kri.stale && <span className="rounded bg-[#FEF3C7] px-2 py-0.5 text-[11px] text-[#8A6400]">reading overdue</span>}
                        </div>
                        {latest?.commentary && <p className="mt-2 text-sm text-[#4A5568]">{latest.commentary}</p>}
                        {kri.description && <p className="mt-2 text-xs text-[#718096]">{kri.description}</p>}
                        {series.length > 1 && (
                            <div className="relative mt-4 h-32 border-b border-l border-gray-100">
                                <div className="absolute left-0 right-0 border-t border-dashed border-[#2D7D46]" style={{ top: line(kri.threshold_green) }} title="green limit" />
                                <div className="absolute left-0 right-0 border-t border-dashed border-[#E5A100]" style={{ top: line(kri.threshold_amber) }} title="amber limit" />
                                <div className="absolute inset-0 flex items-end gap-1 px-1">
                                    {series.map((r) => <div key={r.id} title={`${r.period}: ${r.value} (${r.status})`} className="flex-1 rounded-t" style={{ height: `${Math.max(3, (Number(r.value) / max) * 100)}%`, background: r.status === 'red' ? '#B3261E' : r.status === 'amber' ? '#E5A100' : '#2D7D46' }} />)}
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Breaches</h3>
                        {breaches.length === 0 && <p className="mt-2 text-xs text-[#718096]">This KRI has stayed within appetite.</p>}
                        <ul className="mt-2 divide-y divide-gray-100">
                            {breaches.map((b) => (
                                <li key={b.id} className="py-3 text-xs">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Pill value={b.level} tones={kriTone} />
                                        <span className="text-[#2D3748]">{fmtDate(b.occurred_at)} → {b.resolved_at ? `resolved ${fmtDate(b.resolved_at)}` : <b className="text-[#C53030]">open</b>}</span>
                                        {b.issue && <Link href={route('issues.show', b.issue.id)} className="text-[#0A1F44] underline">{b.issue.issue_code} ({b.issue.status.replace(/_/g, ' ')})</Link>}
                                    </div>
                                    {b.notes && <p className="mt-1 whitespace-pre-line text-[#718096]">{b.notes}</p>}
                                    {b.action_plan ? <p className="mt-1 text-[#2D3748]"><b>Action plan</b> ({b.acknowledger?.name}, {fmtDate(b.acknowledged_at)}): {b.action_plan}</p>
                                        : !b.resolved_at && (can.record ? <Acknowledge breach={b} /> : <p className="mt-1 text-[#C53030]">No action plan yet.</p>)}
                                    {errors.breach && !b.resolved_at && <p className="text-[11px] text-[#C53030]">{errors.breach}</p>}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Period</th><th className="px-3 py-2">Value</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Commentary</th><th className="px-3 py-2">By</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {readings.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-3 py-2 text-xs text-[#4A5568]">{r.period}</td>
                                        <td className="px-3 py-2 text-xs font-semibold text-[#0A1F44]">{fmtNum(r.value)}</td>
                                        <td className="px-3 py-2"><Pill value={r.status} tones={kriTone} /></td>
                                        <td className="px-3 py-2 text-xs text-[#4A5568]">{r.commentary || '—'}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{r.recorder?.name || 'automatic'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <aside className="space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-xs space-y-2">
                        <p><span className="text-[#718096]">Owner:</span> {kri.owner?.name || <span className="text-[#C53030]">none</span>}</p>
                        <p><span className="text-[#718096]">Indicates:</span> {kri.risk ? <Link href={route('risks.show', kri.risk.id)} className="text-[#0A1F44] underline">{kri.risk.risk_id_code} {kri.risk.title}</Link> : 'no risk linked'}</p>
                        <p><span className="text-[#718096]">Severe threshold:</span> {withUnit(kri.threshold_red, kri.unit)}</p>
                    </div>
                    {can.record && kri.source !== 'ea' && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                            <div className="mb-3 flex gap-2 text-xs">
                                {kri.source === 'manual' && <button onClick={() => setTab('reading')} className={`rounded-full px-3 py-1 ${tab === 'reading' ? 'bg-[#0A1F44] text-white' : 'border border-gray-200'}`}>Record reading</button>}
                                {can.edit && <button onClick={() => setTab('definition')} className={`rounded-full px-3 py-1 ${tab === 'definition' || kri.source !== 'manual' ? 'bg-[#0A1F44] text-white' : 'border border-gray-200'}`}>Definition</button>}
                            </div>
                            {kri.source === 'manual' && tab === 'reading' ? <RecordReading kri={kri} /> : can.edit && <Definition kri={kri} users={users} risks={risks} options={options} />}
                        </div>
                    )}
                    {kri.source === 'ea' && <p className="rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#718096] shadow-sm">Published from Enterprise Architecture. Edit the definition and readings in EA.</p>}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
