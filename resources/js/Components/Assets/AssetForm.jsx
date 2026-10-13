import { Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { typeLabels } from '@/Components/Assets/AssetBadges';

const selectClass = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const label = (s) => s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase());

/** Date columns arrive as ISO timestamps; a date input only accepts YYYY-MM-DD. */
export const toDateInput = (v) => (v ? String(v).slice(0, 10) : '');

export const emptyAsset = {
    name: '', description: '', asset_type: '', category: '', criticality: 'medium', status: 'active',
    owner_id: '', custodian_id: '', department: '', location: '', ip_address: '', hostname: '', vendor: '',
    version: '', license_type: '', data_classification: '', purchase_date: '', end_of_life: '', business_service_ids: [],
};

function Field({ id, title, error, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={title} />
            {children}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** The asset form shared by Create and Edit. */
export default function AssetForm({ data, setData, errors, processing, onSubmit, submitLabel, cancelHref, users, services = [], assetTypes, criticalities, statuses, dataClassifications }) {
    const text = (id, title, props = {}) => (
        <Field id={id} title={title} error={errors[id]}>
            <TextInput id={id} value={data[id] ?? ''} className="mt-1 block w-full" onChange={(e) => setData(id, e.target.value)} {...props} />
        </Field>
    );

    const toggleService = (id) => setData('business_service_ids', data.business_service_ids.includes(id)
        ? data.business_service_ids.filter((x) => x !== id)
        : [...data.business_service_ids, id]);

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            {text('name', 'Asset Name *', { required: true, placeholder: 'e.g. Finacle core database (primary)' })}

            <Field id="description" title="Description" error={errors.description}>
                <textarea id="description" value={data.description ?? ''} onChange={(e) => setData('description', e.target.value)} rows={3} className={selectClass} />
            </Field>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field id="asset_type" title="Asset Type *" error={errors.asset_type}>
                    <select id="asset_type" value={data.asset_type} onChange={(e) => setData('asset_type', e.target.value)} className={selectClass} required>
                        <option value="">Select type...</option>
                        {assetTypes.map((t) => <option key={t} value={t}>{typeLabels[t] || t}</option>)}
                    </select>
                </Field>
                {text('category', 'Category', { placeholder: 'e.g. Core Banking, Network, Data' })}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field id="criticality" title="Criticality (owner's rating) *" error={errors.criticality}>
                    <select id="criticality" value={data.criticality} onChange={(e) => setData('criticality', e.target.value)} className={selectClass}>
                        {criticalities.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                    </select>
                </Field>
                <Field id="status" title="Status" error={errors.status}>
                    <select id="status" value={data.status} onChange={(e) => setData('status', e.target.value)} className={selectClass}>
                        {statuses.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                    </select>
                </Field>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Field id="owner_id" title="Owner (accountable)" error={errors.owner_id}>
                    <select id="owner_id" value={data.owner_id ?? ''} onChange={(e) => setData('owner_id', e.target.value)} className={selectClass}>
                        <option value="">Unassigned</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </Field>
                <Field id="custodian_id" title="Custodian (operates it)" error={errors.custodian_id}>
                    <select id="custodian_id" value={data.custodian_id ?? ''} onChange={(e) => setData('custodian_id', e.target.value)} className={selectClass}>
                        <option value="">Unassigned</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </Field>
                {text('department', 'Department')}
            </div>

            {services.length > 0 && (
                <Field id="business_service_ids" title="Business services this asset supports" error={errors.business_service_ids || errors['business_service_ids.0']}>
                    <p className="text-xs text-[#718096]">The asset inherits the criticality of the most critical service it supports.</p>
                    <div className="mt-2 grid max-h-48 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2">
                        {services.map((s) => (
                            <label key={s.id} className="flex items-center gap-2 text-sm text-[#2D3748]">
                                <input type="checkbox" checked={data.business_service_ids.includes(s.id)} onChange={() => toggleService(s.id)} className="rounded border-gray-300 text-[#0A1F44]" />
                                <span className="font-mono-data text-xs text-[#718096]">{s.code}</span> {s.name}
                                <span className="text-[10px] uppercase text-[#718096]">{s.criticality}</span>
                            </label>
                        ))}
                    </div>
                </Field>
            )}

            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Technical Details</h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {text('location', 'Location')}
                    {text('ip_address', 'IP Address', { placeholder: 'e.g. 10.10.4.31' })}
                    {text('hostname', 'Hostname')}
                    {text('vendor', 'Vendor')}
                    {text('version', 'Version')}
                    {text('license_type', 'License Type')}
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <Field id="data_classification" title="Data Classification" error={errors.data_classification}>
                    <select id="data_classification" value={data.data_classification ?? ''} onChange={(e) => setData('data_classification', e.target.value)} className={selectClass}>
                        <option value="">Not yet classified</option>
                        {dataClassifications.map((dc) => <option key={dc} value={dc}>{label(dc)}</option>)}
                    </select>
                </Field>
                {text('purchase_date', 'Purchase Date', { type: 'date' })}
                {text('end_of_life', 'End of Life', { type: 'date' })}
            </div>

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <Link href={cancelHref} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
