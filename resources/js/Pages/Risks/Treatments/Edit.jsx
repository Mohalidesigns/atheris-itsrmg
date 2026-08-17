import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function EditRiskTreatment({ treatment, users = [], strategies = [], statuses = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: treatment.title || '',
        description: treatment.description || '',
        strategy: treatment.strategy || 'mitigate',
        status: treatment.status || '',
        assigned_to: treatment.assigned_to || '',
        due_date: treatment.due_date ? String(treatment.due_date).slice(0, 10) : '',
        priority: treatment.priority || '',
        estimated_cost: treatment.estimated_cost ?? '',
        completion_percentage: treatment.completion_percentage ?? '',
        target_likelihood: treatment.target_likelihood || '',
        target_impact: treatment.target_impact || '',
        notes: treatment.notes || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('risk-treatments.update', treatment.id));
    };

    return (
        <AuthenticatedLayout header={`Edit Treatment — ${treatment.title}`}>
            <Head title={`Edit Treatment — ${treatment.title}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    {treatment.risk && (
                        <p className="text-sm text-[#718096] mb-6">
                            For risk{' '}
                            <Link href={route('risks.show', treatment.risk.id)} className="font-mono-data text-[#1A365D] hover:underline">
                                {treatment.risk.risk_id_code}
                            </Link>{' '}
                            — {treatment.risk.title}
                        </p>
                    )}

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
                                <InputLabel value="Strategy *" />
                                <select value={data.strategy} onChange={e => setData('strategy', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {strategies.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.strategy} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Unchanged</option>
                                    {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
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

                        <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                            <div>
                                <InputLabel htmlFor="due_date" value="Due Date" />
                                <TextInput id="due_date" type="date" value={data.due_date} className="mt-1 block w-full"
                                    onChange={e => setData('due_date', e.target.value)} />
                                <InputError message={errors.due_date} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Priority" />
                                <select value={data.priority} onChange={e => setData('priority', parseInt(e.target.value) || '')}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">--</option>
                                    {[1, 2, 3, 4, 5].map(p => <option key={p} value={p}>P{p}</option>)}
                                </select>
                                <InputError message={errors.priority} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="estimated_cost" value="Estimated Cost" />
                                <TextInput id="estimated_cost" type="number" min="0" step="0.01" value={data.estimated_cost}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('estimated_cost', e.target.value)} />
                                <InputError message={errors.estimated_cost} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="completion_percentage" value="Progress (%)" />
                                <TextInput id="completion_percentage" type="number" min="0" max="100" value={data.completion_percentage}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('completion_percentage', e.target.value)} />
                                <InputError message={errors.completion_percentage} className="mt-1" />
                            </div>
                        </div>

                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Target Residual Risk</h4>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Target Likelihood (1-5)" />
                                    <select value={data.target_likelihood} onChange={e => setData('target_likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Target Impact (1-5)" />
                                    <select value={data.target_impact} onChange={e => setData('target_impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="notes" value="Notes" />
                            <textarea id="notes" value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.notes} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('risk-treatments.show', treatment.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Treatment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
