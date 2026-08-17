import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function EditControl({ control, users, parentControls }) {
    const { data, setData, put, processing, errors } = useForm({
        title: control.title || '', description: control.description || '',
        domain: control.domain || '', category: control.category || '',
        type: control.type || '', nature: control.nature || '',
        frequency: control.frequency || '', owner_id: control.owner_id || '',
        status: control.status || 'active', effectiveness: control.effectiveness || '',
        is_key_control: control.is_key_control || false,
        implementation_notes: control.implementation_notes || '',
        next_review_date: control.next_review_date || '',
    });

    const submit = (e) => { e.preventDefault(); put(route('controls.update', control.id)); };

    return (
        <AuthenticatedLayout header={`Edit ${control.control_code}`}>
            <Head title={`Edit ${control.control_code}`} />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div><InputLabel value="Title *" /><TextInput value={data.title} className="mt-1 block w-full" onChange={e => setData('title', e.target.value)} required /><InputError message={errors.title} className="mt-1" /></div>
                        <div><InputLabel value="Description" /><textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" /></div>
                        <div className="grid grid-cols-3 gap-4">
                            <div><InputLabel value="Status" /><select value={data.status} onChange={e => setData('status', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm">{['draft','active','inactive','deprecated'].map(s=><option key={s} value={s}>{s.charAt(0).toUpperCase()+s.slice(1)}</option>)}</select></div>
                            <div><InputLabel value="Effectiveness" /><select value={data.effectiveness} onChange={e => setData('effectiveness', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm"><option value="">Not Assessed</option>{['effective','partially_effective','ineffective'].map(e_=><option key={e_} value={e_}>{e_.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}</option>)}</select></div>
                            <div><InputLabel value="Type" /><select value={data.type} onChange={e => setData('type', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm"><option value="">Select...</option>{['preventive','detective','corrective','deterrent'].map(t=><option key={t} value={t}>{t.charAt(0).toUpperCase()+t.slice(1)}</option>)}</select></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div><InputLabel value="Domain" /><TextInput value={data.domain} className="mt-1 block w-full" onChange={e => setData('domain', e.target.value)} /></div>
                            <div><InputLabel value="Owner" /><select value={data.owner_id} onChange={e => setData('owner_id', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm"><option value="">Select...</option>{users.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select></div>
                        </div>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={data.is_key_control} onChange={e => setData('is_key_control', e.target.checked)} className="rounded border-gray-300 text-[#1A365D] shadow-sm" />
                            <span className="text-sm text-[#2D3748]">Key control</span>
                        </label>
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('controls.show', control.id)} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Control</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
