import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import VendorForm, { emptyVendor } from '@/Components/Vendors/VendorForm';

export default function CreateVendor({ nextCode, prefill = null, ...options }) {
    const { data, setData, post, processing, errors } = useForm({ ...emptyVendor, ...Object.fromEntries(Object.entries(prefill || {}).filter(([, v]) => v != null)) });

    return (
        <AuthenticatedLayout header="Register New Vendor">
            <Head title="New Vendor" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{nextCode}</span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Vendor Details</h3>
                        {prefill && <span className="text-xs text-[#8A6400]">Pre-filled from the Shared Vendor Directory</span>}
                    </div>
                    <VendorForm {...options} data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); post(route('vendors.store')); }}
                        submitLabel="Register Vendor" cancelHref={route('vendors.index')} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
