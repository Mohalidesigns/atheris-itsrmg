import { Link } from '@inertiajs/react';
import { useState } from 'react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const field = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';

export const toDateInput = (v) => (v ? String(v).slice(0, 10) : '');

export const emptyPolicy = {
    title: '', description: '', content: '', category: '', owner_id: '', is_mandatory: true,
    effective_date: '', review_date: '', expiry_date: '', control_ids: [], change_summary: '',
};

/** The policy form shared by Create and Edit. Status is not here: it moves through the workflow. */
export default function PolicyForm({ data, setData, errors, processing, onSubmit, submitLabel, cancelHref, users = [], controls = [], categories = {}, revising = false }) {
    const [filter, setFilter] = useState('');
    const toggle = (id) => setData('control_ids', data.control_ids.includes(id) ? data.control_ids.filter((x) => x !== id) : [...data.control_ids, id]);
    const shown = controls.filter((c) => !filter || `${c.control_code} ${c.title} ${c.domain}`.toLowerCase().includes(filter.toLowerCase()));

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            <div>
                <InputLabel htmlFor="title" value="Policy title *" />
                <TextInput id="title" value={data.title} className="mt-1 block w-full" onChange={(e) => setData('title', e.target.value)} required />
                <InputError message={errors.title} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="description" value="Purpose and scope" />
                <textarea id="description" rows={2} value={data.description ?? ''} onChange={(e) => setData('description', e.target.value)} className={field} />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <InputLabel htmlFor="category" value="Category" />
                    <select id="category" value={data.category ?? ''} onChange={(e) => setData('category', e.target.value)} className={field}>
                        <option value="">Select…</option>
                        {Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <InputError message={errors.category} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="owner_id" value="Policy owner" />
                    <select id="owner_id" value={data.owner_id ?? ''} onChange={(e) => setData('owner_id', e.target.value)} className={field}>
                        <option value="">Unassigned</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    <InputError message={errors.owner_id} className="mt-1" />
                </div>
                <label className="mt-6 flex items-start gap-2 text-sm text-[#2D3748]">
                    <input type="checkbox" checked={Boolean(data.is_mandatory)} onChange={(e) => setData('is_mandatory', e.target.checked)} className="mt-0.5 rounded border-gray-300 text-[#0A1F44]" />
                    <span>Mandatory<span className="block text-[11px] text-[#718096]">All staff are asked to attest when it is published.</span></span>
                </label>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {[['effective_date', 'Effective date'], ['review_date', 'Next review'], ['expiry_date', 'Expiry (optional)']].map(([id, label]) => (
                    <div key={id}>
                        <InputLabel htmlFor={id} value={label} />
                        <TextInput id={id} type="date" value={data[id] ?? ''} className="mt-1 block w-full" onChange={(e) => setData(id, e.target.value)} />
                        <InputError message={errors[id]} className="mt-1" />
                    </div>
                ))}
            </div>
            <div>
                <InputLabel htmlFor="content" value="Policy text" />
                <textarea id="content" rows={12} value={data.content ?? ''} onChange={(e) => setData('content', e.target.value)} className={`${field} font-mono text-xs`} />
                <InputError message={errors.content} className="mt-1" />
            </div>
            {revising && (
                <div>
                    <InputLabel htmlFor="change_summary" value="What changed (if you edited the text)" />
                    <TextInput id="change_summary" value={data.change_summary ?? ''} className="mt-1 block w-full" onChange={(e) => setData('change_summary', e.target.value)} placeholder="e.g. Added MFA requirement for remote access" />
                    <p className="mt-1 text-[11px] text-[#8A6400]">Changing the text of an approved or in-force policy creates a new version and sends it back for approval.</p>
                </div>
            )}
            <div>
                <InputLabel value="Controls that implement this policy" />
                <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Filter controls…" className="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                <div className="mt-2 max-h-56 overflow-y-auto rounded-lg border border-gray-200 p-2 grid grid-cols-1 sm:grid-cols-2 gap-1">
                    {shown.map((c) => (
                        <label key={c.id} className="flex items-center gap-2 text-sm text-[#2D3748]">
                            <input type="checkbox" checked={data.control_ids.includes(c.id)} onChange={() => toggle(c.id)} className="rounded border-gray-300 text-[#0A1F44]" />
                            <span className="font-mono-data text-xs text-[#718096]">{c.control_code}</span> <span className="truncate">{c.title}</span>
                        </label>
                    ))}
                </div>
                <p className="mt-1 text-[11px] text-[#718096]">{data.control_ids.length} selected</p>
                <InputError message={errors.control_ids || errors['control_ids.0']} className="mt-1" />
            </div>
            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <Link href={cancelHref} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
