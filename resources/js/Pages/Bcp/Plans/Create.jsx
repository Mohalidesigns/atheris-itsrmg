import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const typeLabels = { bcp: 'Business Continuity Plan', dr: 'Disaster Recovery Plan', crisis: 'Crisis Management Plan' };

export default function CreatePlan({ users, nextCode, planTypes }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        plan_type: 'bcp',
        scope: '',
        objectives: '',
        rto_hours: '',
        rpo_hours: '',
        owner_id: '',
        next_review_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('bcp.plans.store'));
    };

    return (
        <AuthenticatedLayout header="Create BCP/DR Plan">
            <Head title="New Plan" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">
                            {nextCode}
                        </span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Plan Details</h3>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Plan Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. IT Disaster Recovery Plan"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="plan_type" value="Plan Type *" />
                            <select
                                id="plan_type"
                                value={data.plan_type}
                                onChange={e => setData('plan_type', e.target.value)}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                            >
                                {planTypes.map(t => (
                                    <option key={t} value={t}>{typeLabels[t] || t}</option>
                                ))}
                            </select>
                            <InputError message={errors.plan_type} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Brief description of the plan..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="scope" value="Scope" />
                            <textarea
                                id="scope"
                                value={data.scope}
                                onChange={e => setData('scope', e.target.value)}
                                rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Define the scope of this plan..."
                            />
                            <InputError message={errors.scope} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="objectives" value="Objectives" />
                            <textarea
                                id="objectives"
                                value={data.objectives}
                                onChange={e => setData('objectives', e.target.value)}
                                rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Key objectives of this plan..."
                            />
                            <InputError message={errors.objectives} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="rto_hours" value="RTO (hours)" />
                                <TextInput
                                    id="rto_hours"
                                    type="number"
                                    min="0"
                                    value={data.rto_hours}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('rto_hours', e.target.value)}
                                    placeholder="e.g. 4"
                                />
                                <p className="mt-1 text-xs text-[#718096]">Recovery Time Objective</p>
                                <InputError message={errors.rto_hours} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="rpo_hours" value="RPO (hours)" />
                                <TextInput
                                    id="rpo_hours"
                                    type="number"
                                    min="0"
                                    value={data.rpo_hours}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('rpo_hours', e.target.value)}
                                    placeholder="e.g. 1"
                                />
                                <p className="mt-1 text-xs text-[#718096]">Recovery Point Objective</p>
                                <InputError message={errors.rpo_hours} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="owner_id" value="Plan Owner" />
                            <select
                                id="owner_id"
                                value={data.owner_id}
                                onChange={e => setData('owner_id', e.target.value)}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                            >
                                <option value="">Select owner...</option>
                                {users.map(u => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </select>
                            <InputError message={errors.owner_id} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="next_review_date" value="Next Review Date" />
                            <TextInput
                                id="next_review_date"
                                type="date"
                                value={data.next_review_date}
                                className="mt-1 block w-full"
                                onChange={e => setData('next_review_date', e.target.value)}
                            />
                            <InputError message={errors.next_review_date} className="mt-1" />
                        </div>

                        <div className="flex items-center gap-3 pt-4 border-t border-gray-100">
                            <PrimaryButton disabled={processing}>
                                {processing ? 'Creating...' : 'Create Plan'}
                            </PrimaryButton>
                            <a
                                href={route('bcp.plans')}
                                className="text-sm text-[#718096] hover:text-[#2D3748]"
                            >
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
