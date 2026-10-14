import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { CATEGORIES, toDateTimeInput } from '@/Utils/changes';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const blank = { name: '', starts_at: '', ends_at: '', scope: 'all', categories: [], reason: '', allow_emergency: true };

function FreezeForm({ form, categories, onSubmit, submitLabel, onCancel }) {
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';
    const toggle = (c) => form.setData('categories', form.data.categories.includes(c) ? form.data.categories.filter((x) => x !== c) : [...form.data.categories, c]);
    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }} className="space-y-3">
            <div><label className={label}>Name</label><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Month-end close, CBN returns week" className={field} /><InputError message={form.errors.name} /></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={label}>Starts</label><input type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} className={field} /><InputError message={form.errors.starts_at} /></div>
                <div><label className={label}>Ends</label><input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} className={field} /><InputError message={form.errors.ends_at} /></div>
            </div>
            <div>
                <label className={label}>Scope</label>
                <div className="mt-1 flex gap-2">
                    {[['all', 'All changes'], ['categories', 'Selected categories']].map(([v, l]) => <button key={v} type="button" onClick={() => form.setData('scope', v)} className={`rounded-lg border px-3 py-1 text-xs ${form.data.scope === v ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568]'} whitespace-nowrap`}>{l}</button>)}
                </div>
                {form.data.scope === 'categories' && (
                    <div className="mt-2 flex flex-wrap gap-1">
                        {categories.map((c) => <button key={c} type="button" onClick={() => toggle(c)} className={`rounded-lg border px-2 py-0.5 text-[11px] ${form.data.categories.includes(c) ? 'border-[#0A1F44] bg-[#0A1F44]/10 text-[#0A1F44]' : 'border-gray-200 text-[#4A5568]'} whitespace-nowrap`}>{humanize(c)}</button>)}
                    </div>
                )}
                <InputError message={form.errors.categories || form.errors.scope} />
            </div>
            <div><label className={label}>Reason</label><textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={2} className={field} /></div>
            <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={form.data.allow_emergency} onChange={(e) => form.setData('allow_emergency', e.target.checked)} className="rounded border-gray-300" /> Emergency changes may proceed during this freeze</label>
            <div className="flex justify-end gap-2 pt-2">
                {onCancel && <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>}
                <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">{submitLabel}</button>
            </div>
        </form>
    );
}

function WindowRow({ window: w, categories, canManage }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: w.name, starts_at: toDateTimeInput(w.starts_at), ends_at: toDateTimeInput(w.ends_at), scope: w.scope, categories: w.categories || [], reason: w.reason || '', allow_emergency: !!w.allow_emergency });
    return (
        <>
            <tr className={`align-top ${w.is_past ? 'opacity-50' : ''}`}>
                <td className="px-4 py-3">
                    <p className="font-medium text-[#2D3748]">{w.name}</p>
                    {w.reason && <p className="text-[11px] text-[#718096]">{w.reason}</p>}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-xs text-[#2D3748]">{formatDate(w.starts_at, true)}<br />→ {formatDate(w.ends_at, true)}</td>
                <td className="px-4 py-3 text-xs text-[#4A5568]">{w.scope === 'all' ? 'All changes' : (w.categories || []).map(humanize).join(', ')}</td>
                <td className="px-4 py-3 text-xs">{w.allow_emergency ? <span className="text-[#2D7D46]">Allowed</span> : <span className="text-[#B3261E]">Blocked</span>}</td>
                <td className="px-4 py-3">{w.is_current ? <StatusBadge status="red" label="Active now" /> : w.is_past ? <StatusBadge status="closed" label="Past" /> : <StatusBadge status="scheduled" label="Upcoming" />}</td>
                <td className="px-4 py-3 text-right text-xs">
                    {canManage && (
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setEditing(!editing)} className="text-[#0A1F44] underline">{editing ? 'Close' : 'Edit'}</button>
                            <button type="button" onClick={() => { if (confirm(`Remove freeze window "${w.name}"?`)) router.delete(route('changes.freeze.destroy', w.id), { preserveScroll: true }); }} className="text-[#B3261E] underline">Remove</button>
                        </div>
                    )}
                    {w.creator && <p className="mt-1 text-[10px] text-[#A0AEC0]">by {w.creator.name}</p>}
                </td>
            </tr>
            {editing && (
                <tr><td colSpan={6} className="bg-[#F7FAFC] px-4 py-3">
                    <FreezeForm form={form} categories={categories} submitLabel="Save window" onCancel={() => setEditing(false)}
                        onSubmit={() => form.put(route('changes.freeze.update', w.id), { preserveScroll: true, onSuccess: () => setEditing(false) })} />
                </td></tr>
            )}
        </>
    );
}

export default function FreezeWindows({ windows = [], categories = CATEGORIES, canManage = false }) {
    const [creating, setCreating] = useState(false);
    const form = useForm(blank);
    const current = windows.filter((w) => w.is_current).length;
    const upcoming = windows.filter((w) => !w.is_current && !w.is_past).length;

    return (
        <AuthenticatedLayout header="Freeze Windows">
            <Head title="Freeze Windows" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'Freeze Windows' }]}
                title="Change Freeze Windows"
                subtitle="Periods when changes are not scheduled: month-end and year-end close, regulatory return weeks, major events. Normal and standard changes need an override reason and the Change Manager's authorisation to go ahead inside one."
                actions={canManage && <button type="button" onClick={() => setCreating(true)} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Add freeze window</button>}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Active now" value={current} tone={current ? 'red' : 'white'} />
                <KpiCard label="Upcoming" value={upcoming} tone="navy" />
                <KpiCard label="Emergency-blocking" value={windows.filter((w) => !w.is_past && !w.allow_emergency).length} tone="white" />
                <KpiCard label="Calendar" value="→" sublabel={<Link href={route('changes.calendar')} className="underline">see freezes on the schedule</Link>} tone="gold" />
            </div>

            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]"><th className="px-4 py-2">Window</th><th className="px-4 py-2">Period</th><th className="px-4 py-2">Scope</th><th className="px-4 py-2">Emergencies</th><th className="px-4 py-2">State</th><th className="px-4 py-2" /></tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {windows.map((w) => <WindowRow key={w.id} window={w} categories={categories} canManage={canManage} />)}
                        {windows.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-xs text-[#718096]">No freeze window defined.</td></tr>}
                    </tbody>
                </table>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="xl">
                <div className="p-6">
                    <h2 className="mb-3 text-lg font-semibold text-[#0A1F44]">Add a freeze window</h2>
                    <FreezeForm form={form} categories={categories} submitLabel="Add window" onCancel={() => setCreating(false)}
                        onSubmit={() => form.post(route('changes.freeze.store'), { preserveScroll: true, onSuccess: () => { setCreating(false); form.reset(); } })} />
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
