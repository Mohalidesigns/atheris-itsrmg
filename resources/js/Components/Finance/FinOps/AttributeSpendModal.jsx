import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, finMoney } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Tag unattributed baseline lines to an application or business service (classification only, no money changes). */
export default function AttributeSpendModal({ show, onClose, subject, subjects, filters, unattributed, baseCurrency, money }) {
    const form = useForm({ line_ids: [], subject_id: '' });
    const [search, setSearch] = useState('');
    useEffect(() => {
        if (!show) return;
        form.reset(); form.clearErrors(); setSearch('');
        router.reload({ only: ['unattributed'], data: { ...filters, unattributed: 1 } });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);
    const lines = unattributed ?? [];
    const toggle = (id) => form.setData('line_ids', form.data.line_ids.includes(id) ? form.data.line_ids.filter((x) => x !== id) : [...form.data.line_ids, id]);
    const find = () => router.reload({ only: ['unattributed'], data: { ...filters, unattributed: 1, search } });
    const key = subject === 'ea_application' ? 'ea_application_id' : 'business_service_id';
    const submit = () => {
        form.transform((d) => ({ line_ids: d.line_ids, [key]: Number(d.subject_id) }));
        form.post(route('finance.budgets.lines.classify'), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || form.data.line_ids.length === 0 || !form.data.subject_id} title={`Attribute spend to ${subject === 'ea_application' ? 'an application' : 'a business service'}`} submitLabel={`Tag ${form.data.line_ids.length} lines`} maxWidth="2xl">
            <div><label className={LABEL} htmlFor="as-s">{subject === 'ea_application' ? 'Application' : 'Business service'}</label><select id="as-s" value={form.data.subject_id} onChange={(e) => form.setData('subject_id', e.target.value)} className={FIELD}><option value="">Select…</option>{subjects.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select><InputError message={form.errors[key] || form.errors.subject_id} /></div>
            <div className="flex gap-2">
                <input aria-label="Search lines" value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); find(); } }} placeholder="Search budget lines" className={`${FIELD} mt-0 flex-1`} />
                <button type="button" onClick={find} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">Search</button>
            </div>
            <div className="max-h-64 overflow-y-auto rounded-lg border border-gray-100">
                {lines.length === 0 ? <p className="px-4 py-6 text-center text-sm text-[#718096]">No unattributed baseline lines found.</p> : (
                    <ul className="divide-y divide-gray-50">{lines.map((l) => (
                        <li key={l.id}><label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm"><input type="checkbox" checked={form.data.line_ids.includes(l.id)} onChange={() => toggle(l.id)} className="rounded border-gray-300" />
                            <span className="min-w-0 flex-1"><span className="block truncate text-[#2D3748]">{l.label}</span><span className="block text-[11px] text-[#718096]">{l.cost_centre}</span></span>
                            <span className="whitespace-nowrap tabular-nums text-xs">{finMoney(l.current_base_amount, baseCurrency, money, { decimals: 0 })}</span></label></li>
                    ))}</ul>
                )}
            </div>
            <InputError message={form.errors.line_ids || form.errors.classify} />
        </FormModal>
    );
}
