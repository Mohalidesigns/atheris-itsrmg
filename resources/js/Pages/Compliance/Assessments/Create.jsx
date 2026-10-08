import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const field = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const today = () => new Date().toISOString().slice(0, 10);

export default function CreateAssessment({ frameworks, users, preselect }) {
    const initialFw = frameworks.find((f) => f.id === preselect);
    const { data, setData, post, processing, errors } = useForm({
        framework_id: initialFw?.id || '',
        title: initialFw ? `${initialFw.short_name} assessment — ${new Date().toLocaleDateString('en-GB', { month: 'short', year: 'numeric' })}` : '',
        description: '', lead_assessor_id: '', start_date: today(), due_date: '',
    });

    const selectedFw = frameworks.find((f) => f.id == data.framework_id);
    const submit = (e) => { e.preventDefault(); post(route('compliance-assessments.store')); };

    return (
        <AuthenticatedLayout header="Start Compliance Assessment">
            <Head title="New Assessment" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel value="Framework *" />
                            <select value={data.framework_id} onChange={(e) => {
                                const fw = frameworks.find((f) => f.id == e.target.value);
                                setData((d) => ({ ...d, framework_id: e.target.value, title: d.title || (fw ? `${fw.short_name} assessment — ${new Date().toLocaleDateString('en-GB', { month: 'short', year: 'numeric' })}` : '') }));
                            }} className={field}>
                                <option value="">Select framework…</option>
                                {frameworks.map((fw) => <option key={fw.id} value={fw.id}>{fw.short_name} — {fw.name}</option>)}
                            </select>
                            <InputError message={errors.framework_id} className="mt-1" />
                        </div>
                        {selectedFw && (
                            <div className="bg-gray-50 rounded-lg p-3 text-sm">
                                <p className="font-medium text-[#2D3748]">{selectedFw.name} {selectedFw.version ? `(${selectedFw.version})` : ''}</p>
                                <p className="text-xs text-[#718096] mt-1">
                                    {selectedFw.assessable_count} requirements will be assessed (domain headings are grouped, not scored).
                                    Each requirement is pre-linked to the first of your controls mapped to it.
                                </p>
                            </div>
                        )}
                        <div><InputLabel value="Assessment title *" /><TextInput value={data.title} className="mt-1 block w-full" onChange={(e) => setData('title', e.target.value)} /><InputError message={errors.title} className="mt-1" /></div>
                        <div><InputLabel value="Scope / description" /><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} className={field} placeholder="Entities, systems and period in scope" /></div>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div><InputLabel value="Lead assessor" /><select value={data.lead_assessor_id} onChange={(e) => setData('lead_assessor_id', e.target.value)} className={field}><option value="">Select…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={errors.lead_assessor_id} className="mt-1" /></div>
                            <div><InputLabel value="Start date" /><input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} className={field} /><p className="text-[10px] text-[#A0AEC0] mt-0.5">A future date schedules it as Planned.</p></div>
                            <div><InputLabel value="Due date" /><input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} className={field} /><InputError message={errors.due_date} className="mt-1" /></div>
                        </div>
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('compliance-assessments.index')} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>{data.start_date > today() ? 'Schedule assessment' : 'Start assessment'}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
