import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function EditVendor({ vendor, riskLevels, statuses, dataAccessLevels }) {
    const { data, setData, put, processing, errors } = useForm({
        name: vendor.name || '',
        description: vendor.description || '',
        category: vendor.category || '',
        risk_level: vendor.risk_level || 'medium',
        status: vendor.status || 'active',
        contact_name: vendor.contact_name || '',
        contact_email: vendor.contact_email || '',
        contact_phone: vendor.contact_phone || '',
        website: vendor.website || '',
        country: vendor.country || 'NG',
        services_provided: vendor.services_provided || '',
        contract_start: vendor.contract_start || '',
        contract_end: vendor.contract_end || '',
        contract_value: vendor.contract_value || '',
        contract_currency: vendor.contract_currency || 'NGN',
        data_access_level: vendor.data_access_level || '',
        sla_details: vendor.sla_details || '',
        next_review_date: vendor.next_review_date || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('vendors.update', vendor.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${vendor.vendor_code}`}>
            <Head title={`Edit ${vendor.vendor_code}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="name" value="Vendor Name *" />
                            <TextInput id="name" value={data.name} className="mt-1 block w-full" onChange={e => setData('name', e.target.value)} required />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="category" value="Category" />
                                <TextInput id="category" value={data.category} className="mt-1 block w-full" onChange={e => setData('category', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel htmlFor="risk_level" value="Risk Level" />
                                <select id="risk_level" value={data.risk_level} onChange={e => setData('risk_level', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {riskLevels.map(r => (
                                        <option key={r} value={r}>{r.charAt(0).toUpperCase() + r.slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="status" value="Status" />
                                <select id="status" value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {statuses.map(s => (
                                        <option key={s} value={s}>{s.replace('_', ' ').charAt(0).toUpperCase() + s.replace('_', ' ').slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="data_access_level" value="Data Access Level" />
                                <select id="data_access_level" value={data.data_access_level} onChange={e => setData('data_access_level', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {dataAccessLevels.map(l => (
                                        <option key={l} value={l}>{l.charAt(0).toUpperCase() + l.slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Contact Information */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Contact Information</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="contact_name" value="Contact Name" />
                                    <TextInput id="contact_name" value={data.contact_name} className="mt-1 block w-full" onChange={e => setData('contact_name', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="contact_email" value="Contact Email" />
                                    <TextInput id="contact_email" type="email" value={data.contact_email} className="mt-1 block w-full" onChange={e => setData('contact_email', e.target.value)} />
                                    <InputError message={errors.contact_email} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="contact_phone" value="Contact Phone" />
                                    <TextInput id="contact_phone" value={data.contact_phone} className="mt-1 block w-full" onChange={e => setData('contact_phone', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="website" value="Website" />
                                    <TextInput id="website" value={data.website} className="mt-1 block w-full" onChange={e => setData('website', e.target.value)} />
                                    <InputError message={errors.website} className="mt-1" />
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="country" value="Country" />
                                <TextInput id="country" value={data.country} className="mt-1 block w-full" onChange={e => setData('country', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel htmlFor="next_review_date" value="Next Review Date" />
                                <TextInput id="next_review_date" type="date" value={data.next_review_date} className="mt-1 block w-full" onChange={e => setData('next_review_date', e.target.value)} />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="services_provided" value="Services Provided" />
                            <textarea id="services_provided" value={data.services_provided} onChange={e => setData('services_provided', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                        </div>

                        {/* Contract Details */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Contract Details</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <InputLabel htmlFor="contract_start" value="Contract Start" />
                                    <TextInput id="contract_start" type="date" value={data.contract_start} className="mt-1 block w-full" onChange={e => setData('contract_start', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="contract_end" value="Contract End" />
                                    <TextInput id="contract_end" type="date" value={data.contract_end} className="mt-1 block w-full" onChange={e => setData('contract_end', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="contract_value" value="Contract Value (NGN)" />
                                    <TextInput id="contract_value" type="number" step="0.01" value={data.contract_value} className="mt-1 block w-full" onChange={e => setData('contract_value', e.target.value)} />
                                </div>
                            </div>
                            <div className="mt-4">
                                <InputLabel htmlFor="sla_details" value="SLA Details" />
                                <textarea id="sla_details" value={data.sla_details} onChange={e => setData('sla_details', e.target.value)} rows={2}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('vendors.show', vendor.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Update Vendor
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
