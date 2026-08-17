import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import UserForm from '@/Components/Admin/UserForm';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function CreateUser({ roles, organizations }) {
    const { auth } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        job_title: '',
        department: '',
        organization_id: auth?.user?.organization_id || '',
        roles: [],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.users.store'));
    };

    return (
        <AuthenticatedLayout header="Add User">
            <Head title="Add User" />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }, { label: 'Add User' }]}
                title="Add User"
                subtitle="Create a new user account and assign roles"
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
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
