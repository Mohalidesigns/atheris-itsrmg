import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateAssessment({ frameworks, users }) {
    const { data, setData, post, processing, errors } = useForm({
        framework_id: '', title: '', description: '', lead_assessor_id: '', due_date: '',
    });

    const selectedFw = frameworks.find(f => f.id == data.framework_id);

    const submit = (e) => { e.preventDefault(); post(route('compliance-assessments.store')); };

    return (
        <AuthenticatedLayout header="Start Compliance Assessment">
            <Head title="New Assessment" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel value="Framework *" />
                            <select value={data.framework_id} onChange={e => {
                                setData('framework_id', e.target.value);
                                const fw = frameworks.find(f => f.id == e.target.value);
                                if (fw && !data.title) setData('title', `${fw.short_name} Assessment - ${new Date().toLocaleDateString()}`);
                            }} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" required>
                                <option value="">Select framework...</option>
                                {frameworks.map(fw => <option key={fw.id} value={fw.id}>{fw.short_name} - {fw.name} ({fw.requirements_count} requirements)</option>)}
                            </select>
                            <InputError message={errors.framework_id} className="mt-1" />
                        </div>
                        {selectedFw && (
                            <div className="bg-gray-50 rounded-lg p-3 text-sm">
                                <p className="font-medium text-[#2D3748]">{selectedFw.name}</p>
                                <p className="text-xs text-[#718096] mt-1">{selectedFw.requirements_count} requirements will be assessed</p>
                            </div>
                        )}
                        <div><InputLabel value="Assessment Title *" /><TextInput value={data.title} className="mt-1 block w-full" onChange={e => setData('title', e.target.value)} required /><InputError message={errors.title} className="mt-1" /></div>
                        <div><InputLabel value="Description" /><textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={2} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" /></div>
                        <div className="grid grid-cols-2 gap-4">
                            <div><InputLabel value="Lead Assessor" /><select value={data.lead_assessor_id} onChange={e => setData('lead_assessor_id', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm"><option value="">Select...</option>{users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select></div>
                            <div><InputLabel value="Due Date" /><input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)} className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm" /></div>
                        </div>
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('compliance-assessments.index')} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Start Assessment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
