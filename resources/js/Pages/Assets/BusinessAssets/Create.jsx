import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function CreateBusinessAsset({ capabilities = [], users = [], criticalities = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        capability_id: '',
        owner_id: '',
        criticality: 'medium',
        recovery_time_objective_min: '',
        recovery_point_objective_min: '',
        description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('business-assets.store'));
    };

    return (
        <AuthenticatedLayout header="New Business Service">
            <Head title="New Business Service" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Business Service Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="name" value="Service Name *" />
                            <TextInput id="name" value={data.name} className="mt-1 block w-full"
                                onChange={e => setData('name', e.target.value)} required
                                placeholder="e.g. Internet Banking" />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Capability" />
                                <select value={data.capability_id} onChange={e => setData('capability_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {capabilities.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <InputError message={errors.capability_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Owner" />
                                <select value={data.owner_id} onChange={e => setData('owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                                <InputError message={errors.owner_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Criticality *" />
                                <select value={data.criticality} onChange={e => setData('criticality', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {criticalities.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                                </select>
                                <InputError message={errors.criticality} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="rto" value="Recovery Time Objective (minutes)" />
                                <TextInput id="rto" type="number" min="0" value={data.recovery_time_objective_min}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('recovery_time_objective_min', e.target.value)} />
                                <InputError message={errors.recovery_time_objective_min} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="rpo" value="Recovery Point Objective (minutes)" />
                                <TextInput id="rpo" type="number" min="0" value={data.recovery_point_objective_min}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('recovery_point_objective_min', e.target.value)} />
                                <InputError message={errors.recovery_point_objective_min} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('business-assets.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Service</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
