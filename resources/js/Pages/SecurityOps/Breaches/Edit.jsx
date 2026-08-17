import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function EditBreach({ breach, users = [], incidents = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: breach.title || '',
        description: breach.description || '',
        incident_id: breach.incident_id || '',
        breach_type: breach.breach_type || '',
        records_affected: breach.records_affected ?? '',
        status: breach.status || 'identified',
        ndpa_notification_required: !!breach.ndpa_notification_required,
        regulatory_body_notified: !!breach.regulatory_body_notified,
        individuals_notified: !!breach.individuals_notified,
        root_cause: breach.root_cause || '',
        remedial_actions: breach.remedial_actions || '',
        assigned_to: breach.assigned_to || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('data-breaches.update', breach.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${breach.breach_id_code}`}>
            <Head title={`Edit ${breach.breach_id_code}`} />

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
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Linked Incident" />
                                <select value={data.incident_id} onChange={e => setData('incident_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {incidents.map(i => <option key={i.id} value={i.id}>{i.incident_id_code} — {i.title}</option>)}
                                </select>
                                <InputError message={errors.incident_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Breach Type" />
                                <select value={data.breach_type} onChange={e => setData('breach_type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {['confidentiality', 'integrity', 'availability'].map(t => <option key={t} value={t}>{cap(t)}</option>)}
                                </select>
                                <InputError message={errors.breach_type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {['identified', 'investigating', 'contained', 'notified', 'resolved', 'closed'].map(s => (
                                        <option key={s} value={s}>{cap(s)}</option>
                                    ))}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="records_affected" value="Records Affected" />
                                <TextInput id="records_affected" type="number" min="0" value={data.records_affected}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('records_affected', e.target.value)} />
                                <InputError message={errors.records_affected} className="mt-1" />
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
                        </div>

                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50 space-y-3">
                            <h4 className="text-sm font-semibold text-[#2D3748]">Notification Status</h4>
                            <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                                <input type="checkbox" checked={data.ndpa_notification_required}
                                    onChange={e => setData('ndpa_notification_required', e.target.checked)}
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30" />
                                NDPA notification required (72-hour deadline)
                            </label>
                            <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                                <input type="checkbox" checked={data.regulatory_body_notified}
                                    onChange={e => setData('regulatory_body_notified', e.target.checked)}
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30" />
                                Regulatory body notified
                            </label>
                            <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                                <input type="checkbox" checked={data.individuals_notified}
                                    onChange={e => setData('individuals_notified', e.target.checked)}
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30" />
                                Affected individuals notified
                            </label>
                        </div>

                        <div>
                            <InputLabel htmlFor="root_cause" value="Root Cause" />
                            <textarea id="root_cause" value={data.root_cause} onChange={e => setData('root_cause', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.root_cause} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="remedial_actions" value="Remedial Actions" />
                            <textarea id="remedial_actions" value={data.remedial_actions} onChange={e => setData('remedial_actions', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.remedial_actions} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('data-breaches.show', breach.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Breach</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
