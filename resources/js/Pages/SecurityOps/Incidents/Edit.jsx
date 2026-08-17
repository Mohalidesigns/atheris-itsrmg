import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function EditIncident({ incident, users = [], statuses = [], types = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: incident.title || '',
        description: incident.description || '',
        type: incident.type || '',
        severity: incident.severity || 'medium',
        status: incident.status || 'detected',
        source: incident.source || '',
        assigned_to: incident.assigned_to || '',
        lead_investigator_id: incident.lead_investigator_id || '',
        affected_users_count: incident.affected_users_count ?? '',
        root_cause: incident.root_cause || '',
        lessons_learned: incident.lessons_learned || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('incidents.update', incident.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${incident.incident_id_code}`}>
            <Head title={`Edit ${incident.incident_id_code}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput id="title" value={data.title} className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)} required />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={4}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Type" />
                                <select value={data.type} onChange={e => setData('type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select type...</option>
                                    {types.map(t => <option key={t} value={t}>{cap(t)}</option>)}
                                </select>
                                <InputError message={errors.type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Severity *" />
                                <select value={data.severity} onChange={e => setData('severity', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {['critical', 'high', 'medium', 'low'].map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.severity} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="source" value="Source" />
                                <TextInput id="source" value={data.source} className="mt-1 block w-full"
                                    onChange={e => setData('source', e.target.value)} />
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Assigned To" />
                                <select value={data.assigned_to} onChange={e => setData('assigned_to', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Unassigned</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                                <InputError message={errors.assigned_to} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Lead Investigator" />
                                <select value={data.lead_investigator_id} onChange={e => setData('lead_investigator_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                                <InputError message={errors.lead_investigator_id} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="affected_users_count" value="Affected Users Count" />
                            <TextInput id="affected_users_count" type="number" min="0" value={data.affected_users_count}
                                className="mt-1 block w-full font-mono-data"
                                onChange={e => setData('affected_users_count', e.target.value)} />
                            <InputError message={errors.affected_users_count} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="root_cause" value="Root Cause" />
                            <textarea id="root_cause" value={data.root_cause} onChange={e => setData('root_cause', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.root_cause} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="lessons_learned" value="Lessons Learned" />
                            <textarea id="lessons_learned" value={data.lessons_learned} onChange={e => setData('lessons_learned', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.lessons_learned} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('incidents.show', incident.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Incident</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
