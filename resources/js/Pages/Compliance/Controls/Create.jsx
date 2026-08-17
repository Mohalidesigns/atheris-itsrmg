import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateControl({ users, parentControls, nextCode }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '', description: '', domain: '', category: '',
        type: '', nature: '', frequency: '', owner_id: '',
        parent_id: '', is_key_control: false, implementation_notes: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('controls.store')); };

    return (
        <AuthenticatedLayout header="Add Control">
            <Head title="New Control" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{nextCode}</span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Control Details</h3>
                    </div>
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel value="Control Title *" />
                            <TextInput value={data.title} className="mt-1 block w-full" onChange={e => setData('title', e.target.value)} required />
                            <InputError message={errors.title} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Description" />
                            <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div><InputLabel value="Domain" /><TextInput value={data.domain} className="mt-1 block w-full" onChange={e => setData('domain', e.target.value)} placeholder="e.g. Access Control" /></div>
                            <div>
                                <InputLabel value="Type" />
                                <select value={data.type} onChange={e => setData('type', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {['preventive','detective','corrective','deterrent'].map(t => <option key={t} value={t}>{t.charAt(0).toUpperCase()+t.slice(1)}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Nature" />
                                <select value={data.nature} onChange={e => setData('nature', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {['technical','administrative','physical'].map(n => <option key={n} value={n}>{n.charAt(0).toUpperCase()+n.slice(1)}</option>)}
                                </select>
                            </div>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Owner" />
                                <select value={data.owner_id} onChange={e => setData('owner_id', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Frequency" />
                                <select value={data.frequency} onChange={e => setData('frequency', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {['continuous','daily','weekly','monthly','quarterly','annual'].map(f => <option key={f} value={f}>{f.charAt(0).toUpperCase()+f.slice(1)}</option>)}
                                </select>
                            </div>
                        </div>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={data.is_key_control} onChange={e => setData('is_key_control', e.target.checked)}
                                className="rounded border-gray-300 text-[#1A365D] shadow-sm focus:ring-[#1A365D]/30" />
                            <span className="text-sm text-[#2D3748]">This is a key control</span>
                        </label>
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('controls.index')} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Control</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
