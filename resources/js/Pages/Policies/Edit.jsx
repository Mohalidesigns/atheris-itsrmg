import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import PolicyForm, { emptyPolicy, toDateInput } from '@/Components/Policies/PolicyForm';

export default function EditPolicy({ policy, linkedControlIds = [], ...options }) {
    const { data, setData, put, processing, errors } = useForm({
        ...Object.fromEntries(Object.keys(emptyPolicy).map((k) => [k, policy[k] ?? emptyPolicy[k]])),
        effective_date: toDateInput(policy.effective_date),
        review_date: toDateInput(policy.review_date),
        expiry_date: toDateInput(policy.expiry_date),
        control_ids: linkedControlIds,
        change_summary: '',
    });

    return (
        <AuthenticatedLayout header={`Edit ${policy.policy_code}`}>
            <Head title={`Edit ${policy.policy_code}`} />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <PolicyForm {...options} revising={['approved', 'active'].includes(policy.status)} data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); put(route('policies.update', policy.id)); }}
                        submitLabel="Save changes" cancelHref={route('policies.show', policy.id)} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
