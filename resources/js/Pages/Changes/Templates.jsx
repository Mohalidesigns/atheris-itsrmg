import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize, toDateInput } from '@/Utils/risk';
import { CATEGORIES } from '@/Utils/changes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STATE = {
    approved: ['Approved', 'approved'], awaiting_approval: ['Awaiting approval', 'pending'], review_overdue: ['Review overdue', 'expired'], inactive: ['Inactive', 'disabled'],
};

const blank = { name: '', category: 'infrastructure', description: '', implementation_plan: '', backout_plan: '', risk_level: 'low', expected_downtime_minutes: '', review_due_at: '', is_active: true };

function TemplateForm({ form, categories, onSubmit, submitLabel, onCancel }) {
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';
    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }} className="space-y-3">
            <div><label className={label}>Name</label><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} /><InputError message={form.errors.name} /></div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><label className={label}>Category</label><select value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} className={field}>{categories.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}</select></div>
                <div><label className={label}>Risk level</label><select value={form.data.risk_level} onChange={(e) => form.setData('risk_level', e.target.value)} className={field}><option value="low">Low</option><option value="medium">Medium</option></select><InputError message={form.errors.risk_level} /></div>
                <div><label className={label}>Downtime (min)</label><input type="number" min="0" value={form.data.expected_downtime_minutes} onChange={(e) => form.setData('expected_downtime_minutes', e.target.value)} className={field} /></div>
                <div><label className={label}>Review due</label><input type="date" value={form.data.review_due_at} onChange={(e) => form.setData('review_due_at', e.target.value)} className={field} /></div>
            </div>
            <div><label className={label}>Description</label><textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} className={field} /></div>
            <div><label className={label}>Implementation plan</label><textarea value={form.data.implementation_plan} onChange={(e) => form.setData('implementation_plan', e.target.value)} rows={4} className={field} /><InputError message={form.errors.implementation_plan} /></div>
            <div><label className={label}>Back-out plan</label><textarea value={form.data.backout_plan} onChange={(e) => form.setData('backout_plan', e.target.value)} rows={3} className={field} /><InputError message={form.errors.backout_plan} /></div>
            <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="rounded border-gray-300" /> Active</label>
            <p className="text-[11px] text-[#8A6400]">Changing the procedure, category or risk level withdraws the standing approval; the template must be approved again before use.</p>
            <div className="flex justify-end gap-2 pt-2">
                {onCancel && <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>}
                <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">{submitLabel}</button>
            </div>
        </form>
    );
}

function TemplateCard({ template: t, categories, canApprove, canEdit }) {
    const [editing, setEditing] = useState(false);
    const [open, setOpen] = useState(false);
    const form = useForm({ ...blank, ...Object.fromEntries(Object.keys(blank).map((k) => [k, t[k] ?? blank[k]])), review_due_at: toDateInput(t.review_due_at), is_active: !!t.is_active, expected_downtime_minutes: t.expected_downtime_minutes ?? '' });
    const [stateLabel, stateBadge] = STATE[t.state] || [humanize(t.state), t.state];
    const approve = () => router.post(route('changes.templates.approve', t.id), {}, { preserveScroll: true });

    return (
        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="flex flex-wrap items-center gap-2 text-sm font-semibold text-[#0A1F44]">{t.name} <StatusBadge status={stateBadge} label={stateLabel} /><StatusBadge status={t.risk_level} label={`${humanize(t.risk_level)} risk`} /></h3>
                    <p className="mt-1 text-xs text-[#718096]">{humanize(t.category)} · used {t.usage_count}× · {t.expected_downtime_minutes ? `${t.expected_downtime_minutes} min downtime` : 'no downtime'}</p>
                    <p className="text-[11px] text-[#718096]">{t.approved_at ? `Approved ${formatDate(t.approved_at)}${t.approver ? ` by ${t.approver}` : ''}` : 'Not yet approved'}{t.review_due_at ? ` · review due ${formatDate(t.review_due_at)}` : ''}</p>
                </div>
                <div className="flex items-center gap-2 text-xs">
                    <button type="button" onClick={() => setOpen(!open)} className="text-[#0A1F44] underline">{open ? 'Hide procedure' : 'Procedure'}</button>
                    {canEdit && <button type="button" onClick={() => setEditing(!editing)} className="text-[#0A1F44] underline">{editing ? 'Close' : 'Edit'}</button>}
                    {canApprove && t.state !== 'approved' && t.is_active && <button type="button" onClick={approve} className="rounded-lg bg-[#2D7D46] px-3 py-1.5 text-white">Approve for 12 months</button>}
                    {canApprove && t.state === 'approved' && <button type="button" onClick={approve} className="rounded-lg border border-gray-200 px-3 py-1.5 text-[#2D3748]">Re-approve</button>}
                </div>
            </div>
            {t.description && <p className="mt-2 text-sm text-[#4A5568]">{t.description}</p>}
            {open && (
                <div className="mt-3 grid grid-cols-1 gap-3 rounded-lg bg-[#F7FAFC] p-3 text-xs sm:grid-cols-2">
                    <div><p className="font-semibold uppercase text-[#718096]">Implementation plan</p><p className="mt-1 whitespace-pre-line text-[#2D3748]">{t.implementation_plan}</p></div>
                    <div><p className="font-semibold uppercase text-[#718096]">Back-out plan</p><p className="mt-1 whitespace-pre-line text-[#2D3748]">{t.backout_plan}</p></div>
                </div>
            )}
            {editing && (
                <div className="mt-3 rounded-lg bg-[#F7FAFC] p-3">
                    <TemplateForm form={form} categories={categories} submitLabel="Save template" onCancel={() => setEditing(false)}
                        onSubmit={() => form.put(route('changes.templates.update', t.id), { preserveScroll: true, onSuccess: () => setEditing(false) })} />
                </div>
            )}
        </div>
    );
}

export default function ChangeTemplates({ templates = [], categories = CATEGORIES, canApprove = false, canEdit = false }) {
    const { auth } = usePage().props;
    const canCreate = (auth?.user?.permissions || []).includes('create changes') || (auth?.user?.roles || []).includes('Super Admin');
    const [creating, setCreating] = useState(false);
    const form = useForm(blank);
    const counts = templates.reduce((acc, t) => ({ ...acc, [t.state]: (acc[t.state] || 0) + 1 }), {});

    return (
        <AuthenticatedLayout header="Standard Changes">
            <Head title="Standard Changes" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'Standard Changes' }]}
                title="Standard Change Templates"
                subtitle="Pre-approved, low-risk, repeatable procedures. A change raised from an approved template is approved on submission; the template itself is approved for a fixed period and re-approved after review."
                actions={canCreate && <button type="button" onClick={() => setCreating(true)} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">New template</button>}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Approved" value={counts.approved || 0} tone="green" />
                <KpiCard label="Awaiting approval" value={counts.awaiting_approval || 0} tone={counts.awaiting_approval ? 'gold' : 'white'} />
                <KpiCard label="Review overdue" value={counts.review_overdue || 0} tone={counts.review_overdue ? 'red' : 'white'} />
                <KpiCard label="Uses to date" value={templates.reduce((n, t) => n + (t.usage_count || 0), 0)} sublabel={<Link href={route('changes.index', { type: 'standard' })} className="underline">standard changes →</Link>} tone="white" />
            </div>

            <div className="space-y-3">
                {templates.length === 0 && <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">No template yet.</p>}
                {templates.map((t) => <TemplateCard key={t.id} template={t} categories={categories} canApprove={canApprove} canEdit={canEdit} />)}
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                <div className="p-6">
                    <h2 className="mb-3 text-lg font-semibold text-[#0A1F44]">New standard change template</h2>
                    <TemplateForm form={form} categories={categories} submitLabel="Create template" onCancel={() => setCreating(false)}
                        onSubmit={() => form.post(route('changes.templates.store'), { preserveScroll: true, onSuccess: () => { setCreating(false); form.reset(); } })} />
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
