import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PermissionMatrix from '@/Components/Admin/PermissionMatrix';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CreateRole({ permissionGroups, actions }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        permissions: [],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.roles.store'));
    };

    return (
        <AuthenticatedLayout header="Add Role">
            <Head title="Add Role" />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Roles & Permissions' }, { label: 'Add Role' }]}
                title="Add Role"
                subtitle="Define a new role and select the permissions it grants"
            />

            <form onSubmit={submit} className="space-y-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="max-w-md">
                        <InputLabel htmlFor="name" value="Role Name *" />
                        <TextInput
                            id="name"
                            value={data.name}
                            className="mt-1 block w-full"
                            onChange={e => setData('name', e.target.value)}
                            required
                            placeholder="e.g. Incident Responder"
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h3 className="text-base font-semibold text-[#2D3748]">Permission Matrix</h3>
                            <p className="text-sm text-[#718096]">
                                {data.permissions.length} permission{data.permissions.length !== 1 ? 's' : ''} selected
                            </p>
                        </div>
                    </div>
                    <PermissionMatrix
                        permissionGroups={permissionGroups}
                        actions={actions}
                        selected={data.permissions}
                        onChange={perms => setData('permissions', perms)}
                    />
                    <InputError message={errors.permissions} className="mt-2" />
                </div>

                <div className="flex items-center justify-end gap-3">
                    <Link href={route('admin.roles.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                        Cancel
                    </Link>
                    <PrimaryButton disabled={processing}>Create Role</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
