import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import AssetForm, { emptyAsset, toDateInput } from '@/Components/Assets/AssetForm';

export default function EditAsset({ asset, linkedServiceIds = [], ...options }) {
    const initial = Object.fromEntries(Object.keys(emptyAsset).map((k) => [k, asset[k] ?? emptyAsset[k]]));
    const { data, setData, put, processing, errors } = useForm({
        ...initial,
        purchase_date: toDateInput(asset.purchase_date),
        end_of_life: toDateInput(asset.end_of_life),
        business_service_ids: linkedServiceIds,
    });

    return (
        <AuthenticatedLayout header={`Edit ${asset.asset_id_code}`}>
            <Head title={`Edit ${asset.asset_id_code}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <AssetForm {...options} data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); put(route('assets.update', asset.id)); }}
                        submitLabel="Update Asset" cancelHref={route('assets.show', asset.id)} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
