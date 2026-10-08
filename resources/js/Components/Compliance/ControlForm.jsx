import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import { humanize } from '@/Utils/compliance';
import { EFFECTIVENESS } from '@/Utils/compliance';

const field = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';

function Select({ label, value, onChange, options, error, placeholder = 'Select…', labelFor = humanize }) {
    return (
        <div>
            <InputLabel value={label} />
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={field}>
                {placeholder !== null && <option value="">{placeholder}</option>}
                {options.map((o) => <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? labelFor(o)}</option>)}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** Fields shared by Create and Edit. `withStatus` adds lifecycle, effectiveness and review dates. */
export default function ControlForm({ data, setData, errors, users, parentControls, domains = [], options, withStatus = false }) {
    return (
        <div className="space-y-5">
            <div>
                <InputLabel value="Control title *" />
                <TextInput value={data.title} className="mt-1 block w-full" onChange={(e) => setData('title', e.target.value)} />
                <InputError message={errors.title} className="mt-1" />
            </div>
            <div>
                <InputLabel value="Description" />
                <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} className={field} placeholder="What the control does and how it operates" />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <InputLabel value="Domain" />
                    <TextInput value={data.domain} className="mt-1 block w-full" list="control-domains" onChange={(e) => setData('domain', e.target.value)} placeholder="e.g. Identity & Access" />
                    <datalist id="control-domains">{domains.map((d) => <option key={d} value={d} />)}</datalist>
                    <InputError message={errors.domain} className="mt-1" />
                </div>
                <Select label="Type" value={data.type} onChange={(v) => setData('type', v)} options={options.types} error={errors.type} />
                <Select label="Nature" value={data.nature} onChange={(v) => setData('nature', v)} options={options.natures} error={errors.nature} />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Select label="Owner" value={data.owner_id} onChange={(v) => setData('owner_id', v)} options={users.map((u) => ({ value: u.id, label: u.name }))} error={errors.owner_id} />
                <Select label="Frequency" value={data.frequency} onChange={(v) => setData('frequency', v)} options={options.frequencies} error={errors.frequency} />
                <Select label="Parent control" value={data.parent_id} onChange={(v) => setData('parent_id', v)} placeholder="None (top level)"
                    options={parentControls.map((c) => ({ value: c.id, label: `${c.control_code} — ${c.title}` }))} error={errors.parent_id} />
            </div>

            {withStatus && (
                <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <Select label="Status" value={data.status} onChange={(v) => setData('status', v)} options={options.statuses} placeholder={null} error={errors.status} />
                    <Select label="Effectiveness" value={data.effectiveness} onChange={(v) => setData('effectiveness', v)} placeholder={null}
                        options={options.effectiveness.map((e) => ({ value: e, label: EFFECTIVENESS[e]?.label || humanize(e) }))} error={errors.effectiveness} />
                    <div>
                        <InputLabel value="Last tested" />
                        <input type="date" value={data.last_tested} onChange={(e) => setData('last_tested', e.target.value)} className={field} />
                        <InputError message={errors.last_tested} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Next review" />
                        <input type="date" value={data.next_review_date} onChange={(e) => setData('next_review_date', e.target.value)} className={field} />
                        <InputError message={errors.next_review_date} className="mt-1" />
                    </div>
                </div>
            )}

            <div>
                <InputLabel value="Implementation notes" />
                <textarea value={data.implementation_notes} onChange={(e) => setData('implementation_notes', e.target.value)} rows={2} className={field} placeholder="Systems, tooling, procedures that implement the control" />
            </div>

            <label className="flex items-center gap-2">
                <input type="checkbox" checked={!!data.is_key_control} onChange={(e) => setData('is_key_control', e.target.checked)}
                    className="rounded border-gray-300 text-[#1A365D] shadow-sm focus:ring-[#1A365D]/30" />
                <span className="text-sm text-[#2D3748]">Key control <span className="text-xs text-[#718096]">(tested every assessment cycle)</span></span>
            </label>
        </div>
    );
}
