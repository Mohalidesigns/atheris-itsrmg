import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import ControlForm from '@/Components/Compliance/ControlForm';

export default function CreateControl({ users, parentControls, domains, options }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '', description: '', domain: '', category: '',
        type: '', nature: '', frequency: '', owner_id: '',
        parent_id: '', is_key_control: false, implementation_notes: '',
    });

    const submit = (e) => { e.preventDefault(); post(route('controls.store')); };
    const prefix = data.domain ? data.domain.slice(0, 3).toUpperCase() : 'CTL';

    return (
        <AuthenticatedLayout header="Add Control">
            <Head title="New Control" />
            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">{prefix}-###</span>
                        <div>
                            <h3 className="text-lg font-semibold text-[#2D3748]">Control details</h3>
                            <p className="text-xs text-[#718096]">The code is assigned on save from the domain prefix. Map it to framework requirements from the control page.</p>
                        </div>
                    </div>
                    <form onSubmit={submit}>
                        <ControlForm data={data} setData={setData} errors={errors} users={users} parentControls={parentControls} domains={domains} options={options} />
                        <div className="flex items-center justify-end gap-3 pt-4 mt-5 border-t border-gray-100">
                            <Link href={route('controls.index')} className="px-4 py-2 text-sm text-[#718096]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Control</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
