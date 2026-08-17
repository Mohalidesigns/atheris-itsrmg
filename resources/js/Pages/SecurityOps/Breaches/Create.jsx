import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateBreach({ users, incidents, breachTypes }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        breach_type: '',
        incident_id: '',
        data_types_affected: '',
        records_affected: '',
        ndpa_notification_required: false,
        assigned_to: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('breaches.store'));
    };

    const defaultBreachTypes = breachTypes || [
        'confidentiality', 'integrity', 'availability', 'combined',
    ];

    return (
        <AuthenticatedLayout header="Report Data Breach">
            <Head title="New Data Breach" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Breach Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. Customer PII Exposure via Misconfigured S3 Bucket"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={4}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the breach, scope, and data involved..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="breach_type" value="Breach Type *" />
                                <select
                                    id="breach_type"
                                    value={data.breach_type}
                                    onChange={e => setData('breach_type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    required
                                >
                                    <option value="">Select type...</option>
                                    {defaultBreachTypes.map(t => (
                                        <option key={t} value={t}>
                                            {t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.breach_type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="incident_id" value="Linked Incident (optional)" />
                                <select
                                    id="incident_id"
                                    value={data.incident_id}
                                    onChange={e => setData('incident_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">None</option>
                                    {(incidents || []).map(i => (
                                        <option key={i.id} value={i.id}>
                                            {i.incident_id_code || `INC-${String(i.id).padStart(4, '0')}`} - {i.title}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.incident_id} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="data_types_affected" value="Data Types Affected" />
                            <TextInput
                                id="data_types_affected"
                                value={data.data_types_affected}
                                className="mt-1 block w-full"
                                onChange={e => setData('data_types_affected', e.target.value)}
                                placeholder="e.g. Names, Email addresses, Phone numbers, Financial data"
                            />
                            <InputError message={errors.data_types_affected} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="records_affected" value="Records Affected" />
                                <TextInput
                                    id="records_affected"
                                    type="number"
                                    min="0"
                                    value={data.records_affected}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('records_affected', e.target.value)}
                                    placeholder="Number of records"
                                />
                                <InputError message={errors.records_affected} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="assigned_to" value="Assign To" />
                                <select
                                    id="assigned_to"
                                    value={data.assigned_to}
                                    onChange={e => setData('assigned_to', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select assignee...</option>
                                    {(users || []).map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.assigned_to} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center gap-3 p-4 bg-gray-50/50 rounded-xl border border-gray-200">
                            <input
                                id="ndpa_notification_required"
                                type="checkbox"
                                checked={data.ndpa_notification_required}
                                onChange={e => setData('ndpa_notification_required', e.target.checked)}
                                className="w-4 h-4 text-[#1A365D] border-gray-300 rounded focus:ring-[#1A365D]/30"
                            />
                            <div>
                                <InputLabel htmlFor="ndpa_notification_required" value="NDPA Notification Required" className="!mb-0" />
                                <p className="text-xs text-[#718096]">
                                    Check this if the breach requires notification to the Nigeria Data Protection Authority.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('breaches.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Report Breach
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
