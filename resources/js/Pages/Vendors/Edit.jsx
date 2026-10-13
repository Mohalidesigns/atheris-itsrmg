import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import VendorForm, { emptyVendor, toDateInput, DATE_FIELDS } from '@/Components/Vendors/VendorForm';

export default function EditVendor({ vendor, ...options }) {
    const initial = Object.fromEntries(Object.keys(emptyVendor).map((k) => [k, DATE_FIELDS.includes(k) ? toDateInput(vendor[k]) : (vendor[k] ?? emptyVendor[k])]));
    const { data, setData, put, processing, errors } = useForm(initial);

    return (
        <AuthenticatedLayout header={`Edit ${vendor.vendor_code}`}>
            <Head title={`Edit ${vendor.vendor_code}`} />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <VendorForm {...options} editing data={data} setData={setData} errors={errors} processing={processing}
                        onSubmit={(e) => { e.preventDefault(); put(route('vendors.update', vendor.id)); }}
                        submitLabel="Update Vendor" cancelHref={route('vendors.show', vendor.id)} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
