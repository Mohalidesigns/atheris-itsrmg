import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDate, Pill } from '@/Components/Monitoring/format';

export const kriTone = { green: 'bg-[#D1FAE5] text-[#2D7D46]', amber: 'bg-[#FEF3C7] text-[#8A6400]', red: 'bg-[#FEE2E2] text-[#C53030]' };
const bar = { green: '#2D7D46', amber: '#E5A100', red: '#B3261E' };
export const unitLabel = (u) => ({ count: '', NGN_M: '₦m', '%': '%', hours: 'h' })[u] ?? u;
export const withUnit = (v, u) => (u === 'NGN_M' ? `₦${fmtNum(v)}m` : `${fmtNum(v)}${unitLabel(u) ? (u === '%' || u === 'hours' ? unitLabel(u) : ` ${unitLabel(u)}`) : ''}`);
export const fmtNum = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-GB', { maximumFractionDigits: 2 }));
export const limits = (k) => (k.direction === 'lower_worse'
    ? `green ≥ ${fmtNum(k.threshold_green)} · amber ≥ ${fmtNum(k.threshold_amber)} · red below`
    : `green ≤ ${fmtNum(k.threshold_green)} · amber ≤ ${fmtNum(k.threshold_amber)} · red above`);
const sourceLabel = { manual: 'recorded', platform: 'platform data', ea: 'from EA' };

function NewKri({ onDone }) {
    const form = useForm({ code: '', name: '', category: '', unit: 'count', direction: 'higher_worse', threshold_green: '', threshold_amber: '', threshold_red: '', frequency: 'monthly' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const input = 'mt-1 w-full rounded-lg border-gray-300 text-xs';
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('kri.store'), { onSuccess: onDone }); }} className="mb-4 grid grid-cols-2 gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-6">
            <label className="text-[11px] text-[#718096]">Code *<input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="KRI-…" className={input} />{err('code')}</label>
            <label className="col-span-2 text-[11px] text-[#718096]">Name *<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={input} />{err('name')}</label>
            <label className="text-[11px] text-[#718096]">Category *<input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} className={input} />{err('category')}</label>
            <label className="text-[11px] text-[#718096]">Unit<input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} className={input} /></label>
            <label className="text-[11px] text-[#718096]">Frequency<select value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} className={input}>{['weekly', 'monthly', 'quarterly'].map((f) => <option key={f}>{f}</option>)}</select></label>
            <label className="text-[11px] text-[#718096]">Direction<select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)} className={input}><option value="higher_worse">higher is worse</option><option value="lower_worse">lower is worse</option></select></label>
            <label className="text-[11px] text-[#718096]">Green limit *<input type="number" step="any" value={form.data.threshold_green} onChange={(e) => form.setData('threshold_green', e.target.value)} className={input} />{err('threshold_green')}</label>
            <label className="text-[11px] text-[#718096]">Amber limit *<input type="number" step="any" value={form.data.threshold_amber} onChange={(e) => form.setData('threshold_amber', e.target.value)} className={input} />{err('threshold_amber')}</label>
            <label className="text-[11px] text-[#718096]">Severe (red) *<input type="number" step="any" value={form.data.threshold_red} onChange={(e) => form.setData('threshold_red', e.target.value)} className={input} />{err('threshold_red')}</label>
            <div className="col-span-2 flex items-end"><button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50">Create KRI</button></div>
        </form>
    );
}

export default function KriIndex({ kris = [], summary = {}, categories = [], filters = {}, canCreate = false }) {
    const [creating, setCreating] = useState(false);
    const go = (f) => router.get(route('kri.index'), { ...filters, ...f }, { preserveState: true, replace: true });
    return (
        <AuthenticatedLayout header="KRI Dashboard">
            <Head title="KRI Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'KRIs & Dashboards' }, { label: 'KRI Dashboard' }]}
                title="Key Risk Indicators"
                subtitle="Each KRI's latest reading against its thresholds. Platform KRIs are measured from the bank's records; the rest are recorded by their owners. Amber or red opens a breach that needs an action plan."
                actions={canCreate && <button onClick={() => setCreating((v) => !v)} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">{creating ? 'Cancel' : 'New KRI'}</button>}
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <KpiCard label="KRIs" value={summary.total ?? 0} tone="navy" />
                <KpiCard label="Green" value={summary.green ?? 0} tone="green" />
                <KpiCard label="Amber" value={summary.amber ?? 0} tone="amber" />
                <KpiCard label="Red" value={summary.red ?? 0} tone="red" />
                <KpiCard label="Open breaches" value={summary.breaches_open ?? 0} sublabel={`${summary.breaches_unacknowledged ?? 0} without an action plan`} tone="white" />
                <KpiCard label="Readings overdue" value={summary.stale ?? 0} tone={summary.stale ? 'amber' : 'white'} />
            </div>
            {creating && <NewKri onDone={() => setCreating(false)} />}
            <div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
                {[['All', null], ['Red', 'red'], ['Amber', 'amber'], ['Green', 'green'], ['Open breach', 'breach'], ['Reading overdue', 'stale']].map(([label, s]) => (
                    <button key={label} onClick={() => go({ status: s || undefined })} className={`rounded-lg border px-3 py-1 ${(filters.status || null) === s ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>
                ))}
                <select value={filters.category || ''} onChange={(e) => go({ category: e.target.value || undefined })} className="ml-auto rounded-lg border-gray-300 text-xs">
                    <option value="">All categories</option>{categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {kris.length === 0 && <p className="text-xs text-[#718096]">No KRIs match.</p>}
                {kris.map((k) => {
                    const max = Math.max(...k.trend.map((r) => Number(r.value)), Number(k.threshold_amber) || 0, 1);
                    return (
                        <Link key={k.id} href={route('kri.show', k.id)} className="block rounded-xl border border-gray-100 bg-white p-4 shadow-sm hover:border-[#0A1F44]/30">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-mono text-xs text-[#718096]">{k.code} · {k.category}</p>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{k.name}</h3>
                                </div>
                                {k.latest && <Pill value={k.latest.status} tones={kriTone} />}
                            </div>
                            <div className="mt-2 flex items-end justify-between">
                                <p className="text-2xl font-bold text-[#0A1F44]">{k.latest ? withUnit(k.latest.value, k.unit) : '—'}</p>
                                <p className="text-right text-[10px] text-[#718096]">{k.latest ? `${k.latest.period}` : 'no reading'}<br />{sourceLabel[k.source]} · {k.frequency}</p>
                            </div>
                            <p className="mt-1 text-[10px] text-[#718096]">{limits(k)}</p>
                            {k.trend.length > 1 && (
                                <div className="mt-2 flex h-10 items-end gap-1">
                                    {k.trend.map((r, i) => <div key={i} title={`${r.period}: ${r.value}`} className="flex-1 rounded-t" style={{ height: `${Math.max(6, (Number(r.value) / max) * 100)}%`, background: bar[r.status] }} />)}
                                </div>
                            )}
                            <div className="mt-2 flex flex-wrap gap-2 text-[10px]">
                                {k.breach && <span className={`rounded px-1.5 py-0.5 ${kriTone[k.breach.level]}`}>{k.breach.level} breach since {fmtDate(k.breach.occurred_at)}{!k.breach.acknowledged_at && ' · no action plan'}</span>}
                                {k.stale && <span className="rounded bg-[#FEF3C7] px-1.5 py-0.5 text-[#8A6400]">reading overdue</span>}
                                {!k.owner && <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[#4A5568]">no owner</span>}
                            </div>
                        </Link>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
