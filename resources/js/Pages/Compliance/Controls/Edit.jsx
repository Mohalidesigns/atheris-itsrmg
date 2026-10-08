import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import ControlForm from '@/Components/Compliance/ControlForm';
import { toDateInput } from '@/Utils/compliance';

export default function EditControl({ control, users, parentControls, domains, options }) {
    const { data, setData, put, processing, errors } = useForm({
        title: control.title || '', description: control.description || '',
        domain: control.domain || '', category: control.category || '',
        type: control.type || '', nature: control.nature || '',
        frequency: control.frequency || '', owner_id: control.owner_id || '',
        parent_id: control.parent_id || '',
        status: control.status || 'active', effectiveness: control.effectiveness || 'not_assessed',
        is_key_control: !!control.is_key_control,
        implementation_notes: control.implementation_notes || '',
        last_tested: toDateInput(control.last_tested),
        next_review_date: toDateInput(control.next_review_date),
    });

    const submit = (e) => { e.preventDefault(); put(route('controls.update', control.id)); };

    return (
        <AuthenticatedLayout header={`Edit ${control.control_code}`}>
            <Head title={`Edit ${control.control_code}`} />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit}>
                        <ControlForm data={data} setData={setData} errors={errors} users={users} parentControls={parentControls} domains={domains} options={options} withStatus />
                        <div className="flex items-center justify-end gap-3 pt-4 mt-5 border-t border-gray-100">
                            <Link href={route('controls.show', control.id)} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Control</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
