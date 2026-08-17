import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function CreateThreat({ nextCode, categories = [], sources = [], types = [], severities = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        category: '',
        source: '',
        type: '',
        likelihood: '',
        capability: '',
        intent: '',
        severity: '',
        countermeasures: '',
        last_seen: '',
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('threats.store'));
    };

    return (
        <AuthenticatedLayout header="Register New Threat">
            <Head title="New Threat" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{nextCode}</span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Threat Details</h3>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="name" value="Threat Name *" />
                            <TextInput id="name" value={data.name} className="mt-1 block w-full"
                                onChange={e => setData('name', e.target.value)} required
                                placeholder="e.g. Phishing campaign targeting finance staff" />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the threat actor, vector, and context..." />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Category" />
                                <select value={data.category} onChange={e => setData('category', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {categories.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                                </select>
                                <InputError message={errors.category} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Source" />
                                <select value={data.source} onChange={e => setData('source', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {sources.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Type" />
                                <select value={data.type} onChange={e => setData('type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {types.map(t => <option key={t} value={t}>{cap(t)}</option>)}
                                </select>
                                <InputError message={errors.type} className="mt-1" />
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
                                <InputError message={errors.severity} className="mt-1" />
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
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Existing or recommended countermeasures..." />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="last_seen" value="Last Seen" />
                                <TextInput id="last_seen" type="date" value={data.last_seen} className="mt-1 block w-full"
                                    onChange={e => setData('last_seen', e.target.value)} />
                                <InputError message={errors.last_seen} className="mt-1" />
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
                            <Link href={route('threats.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Register Threat</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
