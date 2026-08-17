import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import DangerButton from '@/Components/DangerButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    PlusIcon,
    ShieldCheckIcon,
    PencilSquareIcon,
    TrashIcon,
    EyeIcon,
    LockClosedIcon,
} from '@heroicons/react/24/outline';

export default function RolesIndex({ roles, totalPermissions }) {
    const { auth } = usePage().props;
    const [deleting, setDeleting] = useState(null);

    const permissions = auth?.user?.permissions || [];
    const isSuperAdmin = (auth?.user?.roles || []).includes('Super Admin');
    const can = (p) => isSuperAdmin || permissions.includes(p);

    const confirmDelete = () => {
        router.delete(route('admin.roles.destroy', deleting.id), {
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <AuthenticatedLayout header="Roles & Permissions">
            <Head title="Roles & Permissions" />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Roles & Permissions' }]}
                title="Roles & Permissions"
                subtitle={`${roles.length} role${roles.length !== 1 ? 's' : ''} · ${totalPermissions} permissions available`}
                actions={can('create roles') && (
                    <Link
                        href={route('admin.roles.create')}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]"
                    >
                        <PlusIcon className="w-4 h-4" /> Add Role
                    </Link>
                )}
            />

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50/50 border-b border-gray-100">
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Role</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-[#718096] uppercase tracking-wider">Permissions</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-[#718096] uppercase tracking-wider">Users</th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-[#718096] uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {roles.map(role => {
                                const isSuperAdminRole = role.name === 'Super Admin';
                                return (
                                    <tr key={role.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2.5">
                                                <div className="p-1.5 bg-[#1A365D]/10 rounded-lg">
                                                    <ShieldCheckIcon className="w-4 h-4 text-[#1A365D]" />
                                                </div>
                                                <Link
                                                    href={route('admin.roles.show', role.id)}
                                                    className="font-medium text-[#2D3748] hover:text-[#1A365D]"
                                                >
                                                    {role.name}
                                                </Link>
                                                {isSuperAdminRole && (
                                                    <span
                                                        className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-[#D4AF37]/15 text-[#8B6F1F]"
                                                        title="System role — cannot be modified or deleted"
                                                    >
                                                        <LockClosedIcon className="w-3 h-3" /> System
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#1A365D]/10 text-[#1A365D]">
                                                {role.permissions_count} of {totalPermissions}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-[#2D3748]">
                                                {role.users_count} user{role.users_count !== 1 ? 's' : ''}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <Link
                                                    href={route('admin.roles.show', role.id)}
                                                    className="p-1.5 rounded-lg text-[#718096] hover:text-[#1A365D] hover:bg-[#1A365D]/5"
                                                    title="View role"
                                                >
                                                    <EyeIcon className="w-4 h-4" />
                                                </Link>
                                                {can('edit roles') && !isSuperAdminRole && (
                                                    <Link
                                                        href={route('admin.roles.edit', role.id)}
                                                        className="p-1.5 rounded-lg text-[#718096] hover:text-[#1A365D] hover:bg-[#1A365D]/5"
                                                        title="Edit role"
                                                    >
                                                        <PencilSquareIcon className="w-4 h-4" />
                                                    </Link>
                                                )}
                                                {can('delete roles') && !isSuperAdminRole && (
                                                    <button
                                                        onClick={() => setDeleting(role)}
                                                        disabled={role.users_count > 0}
                                                        className={`p-1.5 rounded-lg ${role.users_count > 0
                                                            ? 'text-gray-300 cursor-not-allowed'
                                                            : 'text-[#718096] hover:text-[#B3261E] hover:bg-[#B3261E]/5'}`}
                                                        title={role.users_count > 0
                                                            ? 'Reassign users to another role before deleting'
                                                            : 'Delete role'}
                                                    >
                                                        <TrashIcon className="w-4 h-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Delete Confirmation */}
            <Modal show={!!deleting} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-[#2D3748]">Delete role?</h2>
                    <p className="mt-2 text-sm text-[#718096]">
                        You are about to permanently delete the role{' '}
                        <span className="font-semibold text-[#2D3748]">{deleting?.name}</span>.
                        This action cannot be undone.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setDeleting(null)}>Cancel</SecondaryButton>
                        <DangerButton onClick={confirmDelete}>Delete Role</DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
