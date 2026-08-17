import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import Modal from '@/Components/Modal';
import DangerButton from '@/Components/DangerButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    TrashIcon,
    UserIcon,
    PowerIcon,
} from '@heroicons/react/24/outline';

function StatusBadge({ isActive }) {
    return isActive ? (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#2D7D46]/10 text-[#2D7D46]">
            Active
        </span>
    ) : (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
            Inactive
        </span>
    );
}

function RoleBadge({ role }) {
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#1A365D]/10 text-[#1A365D]">
            {role}
        </span>
    );
}

function formatLastLogin(dateString) {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
        + ' ' + date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export default function UsersIndex({ users, roles, filters }) {
    const { auth } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [deleting, setDeleting] = useState(null);

    const permissions = auth?.user?.permissions || [];
    const isSuperAdmin = (auth?.user?.roles || []).includes('Super Admin');
    const can = (p) => isSuperAdmin || permissions.includes(p);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), { ...filters, search: search || undefined }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('admin.users.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    const confirmDelete = () => {
        router.delete(route('admin.users.destroy', deleting.id), {
            onFinish: () => setDeleting(null),
        });
    };

    const toggleActive = (user) => {
        router.patch(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="User Management">
            <Head title="User Management" />

            <PageHeader
                breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }]}
                title="User Management"
                subtitle={`${users.total} user${users.total !== 1 ? 's' : ''} in your organization`}
                actions={can('create users') && (
                    <Link
                        href={route('admin.users.create')}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]"
                    >
                        <PlusIcon className="w-4 h-4" /> Add User
                    </Link>
                )}
            />

            {/* Search & Filters */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4">
                    <form onSubmit={handleSearch} className="flex flex-col sm:flex-row gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Search users by name, email, job title, or department..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>
                        <select
                            value={filters.role || ''}
                            onChange={e => applyFilter('role', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                        >
                            <option value="">All Roles</option>
                            {roles.map(r => (
                                <option key={r} value={r}>{r}</option>
                            ))}
                        </select>
                        <select
                            value={filters.status || ''}
                            onChange={e => applyFilter('status', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">
                            Search
                        </button>
                    </form>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Name</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Email</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Job Title</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Department</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Roles</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Status</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-[#718096] uppercase tracking-wider">Last Login</th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-[#718096] uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {users.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center">
                                        <UserIcon className="w-10 h-10 text-gray-300 mx-auto" />
                                        <p className="text-sm text-[#718096] mt-2">No users found</p>
                                    </td>
                                </tr>
                            ) : (
                                users.data.map(user => (
                                    <tr key={user.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2.5">
                                                <div className="w-8 h-8 bg-[#1A365D] rounded-full flex items-center justify-center flex-shrink-0">
                                                    <span className="text-xs font-bold text-white">
                                                        {user.name
                                                            ? user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
                                                            : '??'}
                                                    </span>
                                                </div>
                                                <span className="text-sm font-medium text-[#2D3748]">
                                                    {user.name}
                                                    {user.id === auth.user.id && (
                                                        <span className="ml-1.5 text-xs text-[#718096]">(you)</span>
                                                    )}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{user.email}</td>
                                        <td className="px-4 py-3 text-[#2D3748]">{user.job_title || '--'}</td>
                                        <td className="px-4 py-3 text-[#2D3748]">{user.department || '--'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles?.length > 0 ? (
                                                    user.roles.map(role => <RoleBadge key={role.id} role={role.name} />)
                                                ) : (
                                                    <span className="text-xs text-[#718096]">No roles</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge isActive={user.is_active} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-xs font-mono text-[#718096]">
                                                {formatLastLogin(user.last_login_at)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                {can('edit users') && (
                                                    <Link
                                                        href={route('admin.users.edit', user.id)}
                                                        className="p-1.5 rounded-lg text-[#718096] hover:text-[#1A365D] hover:bg-[#1A365D]/5"
                                                        title="Edit user"
                                                    >
                                                        <PencilSquareIcon className="w-4 h-4" />
                                                    </Link>
                                                )}
                                                {can('edit users') && user.id !== auth.user.id && (
                                                    <button
                                                        onClick={() => toggleActive(user)}
                                                        className={`p-1.5 rounded-lg hover:bg-gray-100 ${user.is_active ? 'text-[#2D7D46]' : 'text-gray-400'}`}
                                                        title={user.is_active ? 'Deactivate user' : 'Activate user'}
                                                    >
                                                        <PowerIcon className="w-4 h-4" />
                                                    </button>
                                                )}
                                                {can('delete users') && user.id !== auth.user.id && (
                                                    <button
                                                        onClick={() => setDeleting(user)}
                                                        className="p-1.5 rounded-lg text-[#718096] hover:text-[#B3261E] hover:bg-[#B3261E]/5"
                                                        title="Delete user"
                                                    >
                                                        <TrashIcon className="w-4 h-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={users.links} />
                </div>
            </div>

            {/* Delete Confirmation */}
            <Modal show={!!deleting} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-[#2D3748]">Delete user?</h2>
                    <p className="mt-2 text-sm text-[#718096]">
                        You are about to permanently delete{' '}
                        <span className="font-semibold text-[#2D3748]">{deleting?.name}</span>{' '}
                        ({deleting?.email}). This action cannot be undone.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setDeleting(null)}>Cancel</SecondaryButton>
                        <DangerButton onClick={confirmDelete}>Delete User</DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
