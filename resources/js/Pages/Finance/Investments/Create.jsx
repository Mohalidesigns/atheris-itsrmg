import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL } from '@/Utils/finance';
import { Head, Link, useForm } from '@inertiajs/react';

function Field({ id, label, error, children, className = '' }) {
    return <div className={className}><label className={LABEL} htmlFor={id}>{label}</label>{children}<InputError message={error} /></div>;
}

export default function Create({ options, users, costCentres, legalEntities, fiscalYears, defaults }) {
    const form = useForm({
        title: '', type: options.types[0]?.value ?? 'project', rgt: 'transform', driver: options.drivers[0]?.value ?? 'strategic', sponsor_id: '', manager_id: '',
        cost_centre_id: '', legal_entity_id: '', fiscal_year_id: fiscalYears[0]?.id ?? '', starts_on: '', planned_ends_on: '',
        is_regulatory: false, regulatory_reference: '', strategic_objective: '', description: '', cost_tolerance_pct: defaults?.cost_tolerance_pct ?? 10, external_reference: '',
    });
    const d = form.data; const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((x) => { const o = { ...x }; Object.keys(o).forEach((k) => { if (o[k] === '') o[k] = null; }); if (!o.is_regulatory) o.regulatory_reference = null; return o; });
        form.post(route('finance.investments.store'));
    };
    const users_ = (id, value, key) => <select id={id} value={value} onChange={set(key)} className={FIELD}><option value="">Select…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? `, ${u.job_title}` : ''}</option>)}</select>;
    return (
        <AuthenticatedLayout header="New investment">
            <Head title="New investment" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Investments & Value', href: route('finance.investments.index') }, { label: 'New investment' }]}
                title="New investment" subtitle="Start as an idea; the business case, scoring and gates follow on the investment page" />
            <form onSubmit={submit} className="space-y-4">
                <Card title="What and why">
                    <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field id="iv-title" label="Title" error={e.title} className="sm:col-span-2 lg:col-span-3"><input id="iv-title" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /></Field>
                        <Field id="iv-type" label="Type" error={e.type}><select id="iv-type" value={d.type} onChange={set('type')} className={FIELD}>{options.types.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                        <Field id="iv-rgt" label="Run, grow or transform" error={e.rgt}><select id="iv-rgt" value={d.rgt} onChange={set('rgt')} className={FIELD}>{options.rgt.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                        <Field id="iv-driver" label="Driver" error={e.driver}><select id="iv-driver" value={d.driver} onChange={set('driver')} className={FIELD}>{options.drivers.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                        <div className="flex items-center gap-2 sm:col-span-2 lg:col-span-1"><input id="iv-reg" type="checkbox" checked={d.is_regulatory} onChange={(ev) => form.setData('is_regulatory', ev.target.checked)} className="rounded border-gray-300" /><label htmlFor="iv-reg" className="text-sm text-[#2D3748]">Regulatory mandate</label></div>
                        {d.is_regulatory && <Field id="iv-regref" label="Regulatory reference" error={e.regulatory_reference} className="sm:col-span-2"><input id="iv-regref" maxLength={200} value={d.regulatory_reference} onChange={set('regulatory_reference')} className={FIELD} placeholder="e.g. CBN circular or framework clause" /></Field>}
                        <Field id="iv-obj" label="Strategic objective" error={e.strategic_objective} className="sm:col-span-2 lg:col-span-3"><textarea id="iv-obj" rows={2} value={d.strategic_objective} onChange={set('strategic_objective')} className={FIELD} /></Field>
                        <Field id="iv-desc" label="Description" error={e.description} className="sm:col-span-2 lg:col-span-3"><textarea id="iv-desc" rows={3} value={d.description} onChange={set('description')} className={FIELD} /></Field>
                    </div>
                </Card>
                <Card title="Ownership and funding">
                    <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field id="iv-sp" label="Sponsor" error={e.sponsor_id}>{users_('iv-sp', d.sponsor_id, 'sponsor_id')}</Field>
                        <Field id="iv-mg" label="Manager" error={e.manager_id}>{users_('iv-mg', d.manager_id, 'manager_id')}</Field>
                        <Field id="iv-cc" label="Cost centre" error={e.cost_centre_id}><select id="iv-cc" value={d.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select></Field>
                        <Field id="iv-le" label="Legal entity" error={e.legal_entity_id}><select id="iv-le" value={d.legal_entity_id} onChange={set('legal_entity_id')} className={FIELD}><option value="">None</option>{legalEntities.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select></Field>
                        <Field id="iv-fy" label="Funding fiscal year" error={e.fiscal_year_id}><select id="iv-fy" value={d.fiscal_year_id} onChange={set('fiscal_year_id')} className={FIELD}><option value="">Select…</option>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select></Field>
                        <Field id="iv-tol" label="Cost tolerance (%)" error={e.cost_tolerance_pct}><input id="iv-tol" type="number" step="0.01" min="0" max="100" value={d.cost_tolerance_pct} onChange={set('cost_tolerance_pct')} className={FIELD} /></Field>
                        <Field id="iv-st" label="Starts on" error={e.starts_on}><input id="iv-st" type="date" value={d.starts_on} onChange={set('starts_on')} className={FIELD} /></Field>
                        <Field id="iv-en" label="Planned end" error={e.planned_ends_on}><input id="iv-en" type="date" value={d.planned_ends_on} onChange={set('planned_ends_on')} className={FIELD} /></Field>
                        <Field id="iv-ex" label="External reference" error={e.external_reference}><input id="iv-ex" maxLength={100} value={d.external_reference} onChange={set('external_reference')} className={FIELD} /></Field>
                    </div>
                </Card>
                <div className="flex justify-end gap-2">
                    <Link href={route('finance.investments.index')} className={BTN_SECONDARY}>Cancel</Link>
                    <button disabled={form.processing || !d.title.trim()} className={BTN_PRIMARY}>Create investment</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
