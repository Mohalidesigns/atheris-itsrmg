import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

const toLocalInput = (value) => {
    if (!value) return '';
    const d = new Date(value);
    if (isNaN(d)) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export default function EditSecurityAlert({ alert, severities = [], statuses = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: alert.title || '',
        description: alert.description || '',
        source: alert.source || '',
        severity: alert.severity || 'medium',
        status: alert.status || 'new',
        received_at: toLocalInput(alert.received_at),
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('security-alerts.update', alert.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${alert.alert_id_code}`}>
            <Head title={`Edit ${alert.alert_id_code}`} />

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

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="source" value="Source" />
                                <TextInput id="source" value={data.source} className="mt-1 block w-full"
                                    onChange={e => setData('source', e.target.value)} />
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="received_at" value="Received At" />
                                <TextInput id="received_at" type="datetime-local" value={data.received_at} className="mt-1 block w-full"
                                    onChange={e => setData('received_at', e.target.value)} />
                                <InputError message={errors.received_at} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Severity *" />
                                <select value={data.severity} onChange={e => setData('severity', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {severities.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.severity} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Status *" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('security-alerts.show', alert.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Alert</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
