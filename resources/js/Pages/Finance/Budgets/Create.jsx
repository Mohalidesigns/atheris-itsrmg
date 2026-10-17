import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL, formatMoney, humanize } from '@/Utils/finance';
import { Head, Link, useForm } from '@inertiajs/react';

const MODE_HINT = {
    zero_based: 'Start empty and justify every line.',
    incremental: 'Copy a prior-year baseline and apply a percentage increment.',
    from_actuals: 'Re-budget a prior-year baseline at its posted actuals.',
};

export default function Create({ cycles, costCentres, users, modes, priorBaselines, defaults, baseCurrency }) {
    const form = useForm({
        cycle_id: defaults.cycle_id ?? '', cost_centre_id: '', title: '', mode: 'zero_based', source_budget_id: '', increment_pct: '', owner_id: defaults.owner_id ?? '', executive_summary: '',
    });
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const sources = priorBaselines.filter((b) => !form.data.cost_centre_id || String(b.cost_centre_id) === String(form.data.cost_centre_id));
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, source_budget_id: d.mode !== 'zero_based' ? d.source_budget_id || null : null, increment_pct: d.mode !== 'zero_based' ? d.increment_pct || null : null, owner_id: d.owner_id || null }));
        form.post(route('finance.budgets.store'));
    };

    return (
        <AuthenticatedLayout header="New budget">
            <Head title="New budget" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'New budget' }]} title="New budget" subtitle="Start a version 1 draft for a cost centre in an open cycle" />
            <Card>
                {cycles.length === 0 ? (
                    <p className="px-5 py-8 text-center text-sm text-[#718096]">No cycle is open for planning. <Link className="underline" href={route('finance.cycles.index')}>Open a cycle</Link> first.</p>
                ) : (
                    <form onSubmit={submit} className="space-y-4 px-5 py-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div><label className={LABEL} htmlFor="b-cycle">Cycle</label><select id="b-cycle" value={form.data.cycle_id} onChange={set('cycle_id')} className={FIELD}><option value="">Select…</option>{cycles.map((c) => <option key={c.id} value={c.id}>{c.cycle_code} · {c.name}</option>)}</select><InputError message={e.cycle_id} /></div>
                            <div><label className={LABEL} htmlFor="b-cc">Cost centre</label><select id="b-cc" value={form.data.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.cost_centre_id} /></div>
                        </div>
                        <div><label className={LABEL} htmlFor="b-title">Title</label><input id="b-title" maxLength={200} value={form.data.title} onChange={set('title')} className={FIELD} /><InputError message={e.title} /></div>
                        <fieldset>
                            <legend className={LABEL}>Basis</legend>
                            <div className="mt-1 space-y-1">
                                {modes.map((m) => {
                                    const disabled = false;
                                    return (
                                        <label key={m} className={`flex items-start gap-2 text-sm ${disabled ? 'text-[#A0A4AB]' : 'text-[#2D3748]'}`}>
                                            <input type="radio" name="mode" disabled={disabled} checked={form.data.mode === m} onChange={() => form.setData('mode', m)} className="mt-1" />
                                            <span><span className="font-medium">{humanize(m)}</span> <span className="text-xs text-[#718096]">{MODE_HINT[m] ?? ''}</span></span>
                                        </label>
                                    );
                                })}
                            </div>
                            <InputError message={e.mode} />
                        </fieldset>
                        {form.data.mode !== 'zero_based' && (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div className="sm:col-span-2"><label className={LABEL} htmlFor="b-src">Source baseline</label>
                                    <select id="b-src" value={form.data.source_budget_id} onChange={set('source_budget_id')} className={FIELD}>
                                        <option value="">Select…</option>{sources.map((b) => <option key={b.id} value={b.id}>{b.budget_code} v{b.version_no} · {b.fiscal_year_label} · {formatMoney(b.total_base_amount, baseCurrency, { decimals: 0 })}</option>)}
                                    </select><InputError message={e.source_budget_id} />
                                    {sources.length === 0 && <p className="mt-1 text-xs text-[#718096]">No prior baseline exists for this cost centre.</p>}</div>
                                <div><label className={LABEL} htmlFor="b-inc">{form.data.mode === 'from_actuals' ? 'Uplift % on actuals (optional)' : 'Increment %'}</label><input id="b-inc" type="number" step="any" min="-100" max="100" value={form.data.increment_pct} onChange={set('increment_pct')} className={FIELD} /><InputError message={e.increment_pct} /></div>
                            </div>
                        )}
                        <div><label className={LABEL} htmlFor="b-owner">Owner</label><select id="b-owner" value={form.data.owner_id} onChange={set('owner_id')} className={FIELD}><option value="">Me</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` · ${u.job_title}` : ''}</option>)}</select><InputError message={e.owner_id} /></div>
                        <div><label className={LABEL} htmlFor="b-sum">Executive summary</label><textarea id="b-sum" rows={4} value={form.data.executive_summary} onChange={set('executive_summary')} className={FIELD} /><InputError message={e.executive_summary} /></div>
                        <InputError message={e.workflow} />
                        <div className="flex justify-end gap-2">
                            <Link href={route('finance.budgets.index')} className={BTN_SECONDARY}>Cancel</Link>
                            <button disabled={form.processing} className={BTN_PRIMARY}>Create draft</button>
                        </div>
                    </form>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
