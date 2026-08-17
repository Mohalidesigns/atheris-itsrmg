import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const typeLabels = {
    hardware: 'Hardware',
    software: 'Software',
    cloud_service: 'Cloud Service',
    database: 'Database',
    network: 'Network',
    facility: 'Facility',
};

export default function CreateAsset({ users, nextCode, assetTypes, criticalities, statuses, dataClassifications }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        asset_type: '',
        category: '',
        criticality: 'medium',
        status: 'active',
        owner_id: '',
        department: '',
        location: '',
        ip_address: '',
        hostname: '',
        vendor: '',
        version: '',
        license_type: '',
        data_classification: '',
        purchase_date: '',
        end_of_life: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('assets.store'));
    };

    return (
        <AuthenticatedLayout header="Register New Asset">
            <Head title="New Asset" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">
                            {nextCode}
                        </span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Asset Details</h3>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        {/* Basic Information */}
                        <div>
                            <InputLabel htmlFor="name" value="Asset Name *" />
                            <TextInput
                                id="name"
                                value={data.name}
                                className="mt-1 block w-full"
                                onChange={e => setData('name', e.target.value)}
                                required
                                placeholder="e.g. Production Database Server"
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the asset, its purpose, and importance..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="asset_type" value="Asset Type *" />
                                <select
                                    id="asset_type"
                                    value={data.asset_type}
                                    onChange={e => setData('asset_type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    required
                                >
                                    <option value="">Select type...</option>
                                    {assetTypes.map(t => (
                                        <option key={t} value={t}>{typeLabels[t] || t}</option>
                                    ))}
                                </select>
                                <InputError message={errors.asset_type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="category" value="Category" />
                                <TextInput
                                    id="category"
                                    value={data.category}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('category', e.target.value)}
                                    placeholder="e.g. Server, Endpoint, SaaS"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="criticality" value="Criticality" />
                                <select
                                    id="criticality"
                                    value={data.criticality}
                                    onChange={e => setData('criticality', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    {criticalities.map(c => (
                                        <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="status" value="Status" />
                                <select
                                    id="status"
                                    value={data.status}
                                    onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    {statuses.map(s => (
                                        <option key={s} value={s}>{s.replace('_', ' ').charAt(0).toUpperCase() + s.replace('_', ' ').slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="owner_id" value="Owner" />
                                <select
                                    id="owner_id"
                                    value={data.owner_id}
                                    onChange={e => setData('owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select owner...</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="department" value="Department" />
                                <TextInput
                                    id="department"
                                    value={data.department}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('department', e.target.value)}
                                    placeholder="e.g. IT, Finance, Operations"
                                />
                            </div>
                        </div>

                        {/* Technical Details */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Technical Details</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="location" value="Location" />
                                    <TextInput
                                        id="location"
                                        value={data.location}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('location', e.target.value)}
                                        placeholder="e.g. Lagos DC, AWS eu-west-1"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="ip_address" value="IP Address" />
                                    <TextInput
                                        id="ip_address"
                                        value={data.ip_address}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('ip_address', e.target.value)}
                                        placeholder="e.g. 192.168.1.100"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="hostname" value="Hostname" />
                                    <TextInput
                                        id="hostname"
                                        value={data.hostname}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('hostname', e.target.value)}
                                        placeholder="e.g. prod-db-01"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="vendor" value="Vendor" />
                                    <TextInput
                                        id="vendor"
                                        value={data.vendor}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('vendor', e.target.value)}
                                        placeholder="e.g. Dell, Microsoft, AWS"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="version" value="Version" />
                                    <TextInput
                                        id="version"
                                        value={data.version}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('version', e.target.value)}
                                        placeholder="e.g. v14.2, 2024 R2"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="license_type" value="License Type" />
                                    <TextInput
                                        id="license_type"
                                        value={data.license_type}
                                        className="mt-1 block w-full"
                                        onChange={e => setData('license_type', e.target.value)}
                                        placeholder="e.g. Enterprise, Open Source"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Classification & Dates */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="data_classification" value="Data Classification" />
                                <select
                                    id="data_classification"
                                    value={data.data_classification}
                                    onChange={e => setData('data_classification', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select...</option>
                                    {dataClassifications.map(dc => (
                                        <option key={dc} value={dc}>{dc.charAt(0).toUpperCase() + dc.slice(1)}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="purchase_date" value="Purchase Date" />
                                <TextInput
                                    id="purchase_date"
                                    type="date"
                                    value={data.purchase_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('purchase_date', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="end_of_life" value="End of Life" />
                                <TextInput
                                    id="end_of_life"
                                    type="date"
                                    value={data.end_of_life}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('end_of_life', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('assets.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Create Asset
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
