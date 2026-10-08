import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

import { formatDate, threatLabel } from '@/Utils/risk';

const cap = threatLabel;

export default function EditThreat({ threat, categories = [], sources = [], types = [], severities = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        name: threat.name || '',
        description: threat.description || '',
        category: threat.category || '',
        source: threat.source || '',
        type: threat.type || '',
        likelihood: threat.likelihood || '',
        capability: threat.capability || '',
        intent: threat.intent || '',
        severity: threat.severity || '',
        countermeasures: threat.countermeasures || '',
        last_seen: threat.last_seen ? String(threat.last_seen).slice(0, 10) : '',
        is_active: !!threat.is_active,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('threats.update', threat.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${threat.threat_id_code}`}>
            <Head title={`Edit ${threat.threat_id_code}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="name" value="Threat Name *" />
                            <TextInput id="name" value={data.name} className="mt-1 block w-full"
                                onChange={e => setData('name', e.target.value)} required />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Category" />
                                <select value={data.category} onChange={e => setData('category', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {categories.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Source" />
                                <select value={data.source} onChange={e => setData('source', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {sources.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Type" />
                                <select value={data.type} onChange={e => setData('type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {types.map(t => <option key={t} value={t}>{cap(t)}</option>)}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                            <div>
                                <InputLabel value="Severity" />
                                <select value={data.severity} onChange={e => setData('severity', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {severities.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Likelihood (1-5)" />
                                <select value={data.likelihood} onChange={e => setData('likelihood', parseInt(e.target.value) || '')}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">--</option>
                                    {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Capability (1-5)" />
                                <select value={data.capability} onChange={e => setData('capability', parseInt(e.target.value) || '')}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">--</option>
                                    {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Intent (1-5)" />
                                <select value={data.intent} onChange={e => setData('intent', parseInt(e.target.value) || '')}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">--</option>
                                    {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                </select>
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="countermeasures" value="Countermeasures" />
                            <textarea id="countermeasures" value={data.countermeasures} onChange={e => setData('countermeasures', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="last_seen" value="Last Seen" />
                                <TextInput id="last_seen" type="date" value={data.last_seen} className="mt-1 block w-full"
                                    onChange={e => setData('last_seen', e.target.value)} />
                            </div>
                            <div className="flex items-end pb-2">
                                <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                                    <input type="checkbox" checked={data.is_active} onChange={e => setData('is_active', e.target.checked)}
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30" />
                                    Threat is active
                                </label>
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('threats.show', threat.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Threat</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
