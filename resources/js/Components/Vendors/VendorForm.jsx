import { Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const selectClass = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const label = (s) => s.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** Date columns arrive as ISO timestamps; a date input only accepts YYYY-MM-DD. */
export const toDateInput = (v) => (v ? String(v).slice(0, 10) : '');

export const emptyVendor = {
    name: '', description: '', category: '', risk_level: 'medium', status: 'active', owner_id: '',
    contact_name: '', contact_email: '', contact_phone: '', website: '', domain: '', country: 'NG',
    services_provided: '', contract_start: '', contract_end: '', contract_value: '', contract_currency: 'NGN',
    data_access_level: '', processes_personal_data: false, dpa_signed_at: '', material_outsourcing: false,
    regulator_notified_at: '', shared_directory_id: '', sla_details: '', next_review_date: '',
};

export const DATE_FIELDS = ['contract_start', 'contract_end', 'dpa_signed_at', 'regulator_notified_at', 'next_review_date'];

function Field({ id, title, error, hint, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={title} />
            {children}
            {hint && <p className="mt-1 text-[11px] text-[#718096]">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** The vendor form shared by Create and Edit. */
export default function VendorForm({ data, setData, errors, processing, onSubmit, submitLabel, cancelHref, users = [], directory = [], riskLevels, statuses, dataAccessLevels, editing = false }) {
    const text = (id, title, props = {}, hint = null) => (
        <Field id={id} title={title} error={errors[id]} hint={hint}>
            <TextInput id={id} value={data[id] ?? ''} className="mt-1 block w-full" onChange={(e) => setData(id, e.target.value)} {...props} />
        </Field>
    );
    const check = (id, title, hint) => (
        <label className="flex items-start gap-2 text-sm text-[#2D3748]">
            <input type="checkbox" checked={Boolean(data[id])} onChange={(e) => setData(id, e.target.checked)} className="mt-0.5 rounded border-gray-300 text-[#0A1F44]" />
            <span>{title}<span className="block text-[11px] text-[#718096]">{hint}</span></span>
        </label>
    );

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            {text('name', 'Vendor Name *', { required: true, placeholder: 'e.g. Interswitch Limited' })}

            <Field id="description" title="Description" error={errors.description}>
                <textarea id="description" value={data.description ?? ''} onChange={(e) => setData('description', e.target.value)} rows={3} className={selectClass} />
            </Field>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {text('category', 'Category', { placeholder: 'e.g. Payments, Cloud' })}
                <Field id="risk_level" title="Bank's risk rating" error={errors.risk_level}>
                    <select id="risk_level" value={data.risk_level ?? ''} onChange={(e) => setData('risk_level', e.target.value)} className={selectClass}>
                        {riskLevels.map((r) => <option key={r} value={r}>{label(r)}</option>)}
                    </select>
                </Field>
                <Field id="status" title="Status" error={errors.status}>
                    <select id="status" value={data.status ?? ''} onChange={(e) => setData('status', e.target.value)} className={selectClass}>
                        {statuses.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                    </select>
                </Field>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field id="owner_id" title="Relationship owner" error={errors.owner_id}>
                    <select id="owner_id" value={data.owner_id ?? ''} onChange={(e) => setData('owner_id', e.target.value)} className={selectClass}>
                        <option value="">Unassigned</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </Field>
                <Field id="shared_directory_id" title="Shared Directory entry" error={errors.shared_directory_id} hint="Links the Atheris-maintained baseline DDQ and contacts.">
                    <select id="shared_directory_id" value={data.shared_directory_id ?? ''} onChange={(e) => setData('shared_directory_id', e.target.value)} className={selectClass}>
                        <option value="">Not in the directory</option>
                        {directory.map((d) => <option key={d.id} value={d.id}>{d.legal_name}</option>)}
                    </select>
                </Field>
            </div>

            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50 space-y-4">
                <h4 className="text-sm font-semibold text-[#2D3748]">Tiering inputs</h4>
                <p className="text-xs text-[#718096] -mt-2">The tier is calculated from these facts, together with the applications the vendor supplies in the architecture repository. It sets how often the vendor must be reassessed.</p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field id="data_access_level" title="Access to bank data" error={errors.data_access_level}>
                        <select id="data_access_level" value={data.data_access_level ?? ''} onChange={(e) => setData('data_access_level', e.target.value)} className={selectClass}>
                            <option value="">Not recorded</option>
                            {dataAccessLevels.map((d) => <option key={d} value={d}>{label(d)}</option>)}
                        </select>
                    </Field>
                    {text('domain', 'Rating domain', { placeholder: 'e.g. interswitchgroup.com' }, 'Used by the security-rating provider.')}
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="space-y-2">
                        {check('processes_personal_data', 'Processes personal data for the bank', 'An NDPA 2023 data processor: a signed data-processing agreement is required.')}
                        {data.processes_personal_data && text('dpa_signed_at', 'DPA signed on', { type: 'date' })}
                    </div>
                    <div className="space-y-2">
                        {check('material_outsourcing', 'Material outsourcing arrangement', 'Under the CBN outsourcing guidelines the bank must notify the CBN.')}
                        {data.material_outsourcing && text('regulator_notified_at', 'CBN notified on', { type: 'date' })}
                    </div>
                </div>
            </div>

            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Contact</h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {text('contact_name', 'Contact name')}
                    {text('contact_email', 'Contact email', { type: 'email' })}
                    {text('contact_phone', 'Contact phone')}
                    {text('website', 'Website', { placeholder: 'https://…' })}
                    {text('country', 'Country')}
                </div>
            </div>

            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Contract</h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {text('contract_start', 'Contract start', { type: 'date' })}
                    {text('contract_end', 'Contract end', { type: 'date' })}
                    {text('contract_value', 'Contract value', { type: 'number', min: 0, step: '0.01' })}
                    {text('contract_currency', 'Currency')}
                </div>
                <div className="mt-4">
                    <Field id="services_provided" title="Services provided" error={errors.services_provided}>
                        <textarea id="services_provided" value={data.services_provided ?? ''} onChange={(e) => setData('services_provided', e.target.value)} rows={2} className={selectClass} />
                    </Field>
                </div>
                <div className="mt-4">
                    <Field id="sla_details" title="SLA details" error={errors.sla_details}>
                        <textarea id="sla_details" value={data.sla_details ?? ''} onChange={(e) => setData('sla_details', e.target.value)} rows={2} className={selectClass} />
                    </Field>
                </div>
            </div>

            {editing && text('next_review_date', 'Next review date', { type: 'date' }, 'Set automatically when an assessment is completed; change it only to agree an exception.')}

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <Link href={cancelHref} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
