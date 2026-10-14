import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import PolicyForm, { emptyPolicy } from '@/Components/Policies/PolicyForm';

export default function CreatePolicy({ nextCode, ...options }) {
    const { data, setData, post, processing, errors } = useForm({ ...emptyPolicy });

    return (
        <AuthenticatedLayout header="New Policy">
            <Head title="New Policy" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{nextCode}</span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Policy details</h3>
                        <span className="text-xs text-[#718096]">Saved as a draft, version 1.0</span>
                    </div>
                    <PolicyForm {...options} data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); post(route('policies.store')); }}
                        submitLabel="Create draft" cancelHref={route('policies.index')} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
