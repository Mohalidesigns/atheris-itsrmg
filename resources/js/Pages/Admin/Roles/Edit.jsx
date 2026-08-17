import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PermissionMatrix from '@/Components/Admin/PermissionMatrix';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm } from '@inertiajs/react';

export default function EditRole({ role, permissionGroups, actions }) {
    const { data, setData, put, processing, errors } = useForm({
        name: role.name || '',
        permissions: role.permissions || [],
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.roles.update', role.id));
    };

    return (
        <AuthenticatedLayout header="Edit Role">
            <Head title={`Edit ${role.name}`} />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Roles & Permissions' }, { label: role.name }]}
                title={`Edit ${role.name}`}
                subtitle={`Assigned to ${role.users_count} user${role.users_count !== 1 ? 's' : ''} · changes take effect immediately`}
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
                    <PrimaryButton disabled={processing}>Save Changes</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
