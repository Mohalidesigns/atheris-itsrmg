import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import UserForm from '@/Components/Admin/UserForm';
import { Head, useForm } from '@inertiajs/react';

export default function EditUser({ user, roles, organizations }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name || '',
        email: user.email || '',
        password: '',
        password_confirmation: '',
        job_title: user.job_title || '',
        department: user.department || '',
        organization_id: user.organization_id || '',
        roles: (user.roles || []).map(r => r.name),
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.users.update', user.id));
    };

    return (
        <AuthenticatedLayout header="Edit User">
            <Head title={`Edit ${user.name}`} />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }, { label: user.name }]}
                title={`Edit ${user.name}`}
                subtitle="Update user details, password, and role assignments"
            />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <UserForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        processing={processing}
                        onSubmit={submit}
                        roles={roles}
                        organizations={organizations}
                        isEdit
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
