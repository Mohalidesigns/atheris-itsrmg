import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateIncident({ users, types }) {
    const now = new Date();
    const defaultDetected = now.toISOString().slice(0, 16);

    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        type: '',
        severity: '',
        source: '',
        assigned_to: '',
        lead_investigator_id: '',
        detected_at: defaultDetected,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('incidents.store'));
    };

    const incidentTypes = types || [
        'malware', 'phishing', 'data_leak', 'unauthorized_access', 'dos', 'insider_threat', 'other',
    ];

    return (
        <AuthenticatedLayout header="Report New Incident">
            <Head title="New Incident" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Incident Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. Phishing attack targeting finance department"
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
                                placeholder="Describe the incident, how it was detected, and initial observations..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="type" value="Incident Type *" />
                                <select
                                    id="type"
                                    value={data.type}
                                    onChange={e => setData('type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    required
                                >
                                    <option value="">Select type...</option>
                                    {incidentTypes.map(t => (
                                        <option key={t} value={t}>
                                            {t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="severity" value="Severity *" />
                                <select
                                    id="severity"
                                    value={data.severity}
                                    onChange={e => setData('severity', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    required
                                >
                                    <option value="">Select severity...</option>
                                    <option value="critical">Critical</option>
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                </select>
                                <InputError message={errors.severity} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="source" value="Detection Source" />
                                <TextInput
                                    id="source"
                                    value={data.source}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('source', e.target.value)}
                                    placeholder="e.g. SIEM, User Report, IDS Alert"
                                />
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="detected_at" value="Detected At" />
                                <TextInput
                                    id="detected_at"
                                    type="datetime-local"
                                    value={data.detected_at}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('detected_at', e.target.value)}
                                />
                                <InputError message={errors.detected_at} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                            <div>
                                <InputLabel htmlFor="lead_investigator_id" value="Lead Investigator" />
                                <select
                                    id="lead_investigator_id"
                                    value={data.lead_investigator_id}
                                    onChange={e => setData('lead_investigator_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select investigator...</option>
                                    {(users || []).map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.lead_investigator_id} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('incidents.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Report Incident
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
