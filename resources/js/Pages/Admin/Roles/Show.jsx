import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PermissionMatrix from '@/Components/Admin/PermissionMatrix';
import { Head, Link, usePage } from '@inertiajs/react';
import { PencilSquareIcon, UserIcon } from '@heroicons/react/24/outline';

export default function ShowRole({ role, permissionGroups, actions }) {
    const { auth } = usePage().props;
    const permissions = auth?.user?.permissions || [];
    const isSuperAdmin = (auth?.user?.roles || []).includes('Super Admin');
    const can = (p) => isSuperAdmin || permissions.includes(p);
    const isSuperAdminRole = role.name === 'Super Admin';

    return (
        <AuthenticatedLayout header={role.name}>
            <Head title={`Role — ${role.name}`} />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Roles & Permissions' }, { label: role.name }]}
                title={role.name}
                subtitle={`${role.permissions.length} permission${role.permissions.length !== 1 ? 's' : ''} · ${role.users.length} user${role.users.length !== 1 ? 's' : ''} assigned`}
                actions={can('edit roles') && !isSuperAdminRole && (
                    <Link
                        href={route('admin.roles.edit', role.id)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]"
                    >
                        <PencilSquareIcon className="w-4 h-4" /> Edit Role
                    </Link>
                )}
            />

            {isSuperAdminRole && (
                <div className="mb-6 px-4 py-3 rounded-xl bg-[#D4AF37]/10 border border-[#D4AF37]/30 text-sm text-[#8B6F1F]">
                    Super Admin is a system role. It bypasses all permission checks and cannot be modified or deleted.
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-base font-semibold text-[#2D3748] mb-4">Permissions</h3>
                        <PermissionMatrix
                            permissionGroups={permissionGroups}
                            actions={actions}
                            selected={role.permissions}
                            readOnly
                        />
                    </div>
                </div>

                <div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                        <div className="px-4 py-3 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Assigned Users</h3>
                        </div>
                        {role.users.length === 0 ? (
                            <div className="px-4 py-10 text-center">
                                <UserIcon className="w-8 h-8 text-gray-300 mx-auto" />
                                <p className="text-sm text-[#718096] mt-2">No users have this role</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {role.users.map(user => (
                                    <li key={user.id} className="px-4 py-3 flex items-center gap-2.5">
                                        <div className="w-8 h-8 bg-[#1A365D] rounded-full flex items-center justify-center flex-shrink-0">
                                            <span className="text-xs font-bold text-white">
                                                {user.name
                                                    ? user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
                                                    : '??'}
                                            </span>
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-[#2D3748] truncate">{user.name}</p>
                                            <p className="text-xs text-[#718096] truncate">
                                                {user.job_title || user.email}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
