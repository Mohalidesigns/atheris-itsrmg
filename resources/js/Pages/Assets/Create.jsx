import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import AssetForm, { emptyAsset } from '@/Components/Assets/AssetForm';

export default function CreateAsset({ nextCode, ...options }) {
    const { data, setData, post, processing, errors } = useForm({ ...emptyAsset });

    return (
        <AuthenticatedLayout header="Register New Asset">
            <Head title="New Asset" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{nextCode}</span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Asset Details</h3>
                    </div>
                    <AssetForm {...options} data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); post(route('assets.store')); }}
                        submitLabel="Register Asset" cancelHref={route('assets.index')} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
