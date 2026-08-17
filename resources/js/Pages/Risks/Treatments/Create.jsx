import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateTreatment({ risk, risks, users, strategies }) {
    const { data, setData, post, processing, errors } = useForm({
        risk_id: risk?.id || '',
        title: '',
        description: '',
        strategy: 'mitigate',
        assigned_to: '',
        due_date: '',
        priority: 3,
        estimated_cost: '',
        cost_currency: 'NGN',
        target_likelihood: '',
        target_impact: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk-treatments.store'));
    };

    return (
        <AuthenticatedLayout header="New Treatment Plan">
            <Head title="New Treatment" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        {!risk && (
                            <div>
                                <InputLabel value="Select Risk *" />
                                <select value={data.risk_id} onChange={e => setData('risk_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" required>
                                    <option value="">Choose a risk...</option>
                                    {risks.map(r => <option key={r.id} value={r.id}>{r.risk_id_code} - {r.title}</option>)}
                                </select>
                                <InputError message={errors.risk_id} className="mt-1" />
                            </div>
                        )}

                        {risk && (
                            <div className="bg-gray-50 rounded-lg p-3">
                                <span className="font-mono-data text-xs text-[#1A365D] font-semibold">{risk.risk_id_code}</span>
                                <p className="text-sm text-[#2D3748] font-medium">{risk.title}</p>
                            </div>
                        )}

                        <div>
                            <InputLabel value="Treatment Title *" />
                            <TextInput value={data.title} className="mt-1 block w-full" onChange={e => setData('title', e.target.value)} required
                                placeholder="e.g. Implement multi-factor authentication" />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Description" />
                            <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the treatment plan and expected outcomes..." />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Strategy *" />
                                <select value={data.strategy} onChange={e => setData('strategy', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {strategies.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Assigned To" />
                                <select value={data.assigned_to} onChange={e => setData('assigned_to', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">Select...</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Priority" />
                                <select value={data.priority} onChange={e => setData('priority', parseInt(e.target.value))}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value={1}>1 - Critical</option>
                                    <option value={2}>2 - High</option>
                                    <option value={3}>3 - Medium</option>
                                    <option value={4}>4 - Low</option>
                                    <option value={5}>5 - Very Low</option>
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Due Date" />
                                <input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            </div>
                            <div>
                                <InputLabel value="Estimated Cost (NGN)" />
                                <input type="number" step="0.01" value={data.estimated_cost} onChange={e => setData('estimated_cost', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    placeholder="0.00" />
                            </div>
                        </div>

                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Target Residual Risk (after treatment)</h4>
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <InputLabel value="Target Likelihood" />
                                    <select value={data.target_likelihood} onChange={e => setData('target_likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {[1,2,3,4,5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Target Impact" />
                                    <select value={data.target_impact} onChange={e => setData('target_impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {[1,2,3,4,5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Target Score" />
                                    <div className="mt-1 h-[42px] flex items-center">
                                        <span className="font-mono-data text-lg font-bold text-[#2D3748]">
                                            {data.target_likelihood && data.target_impact ? data.target_likelihood * data.target_impact : '--'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={risk ? route('risks.show', risk.id) : route('risk-treatments.index')}
                                className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Treatment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
