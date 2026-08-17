import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import { Head } from '@inertiajs/react';
import {
    UsersIcon,
    UserIcon,
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
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }) + ' ' + date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function Users({ users }) {
    return (
        <AuthenticatedLayout header="User Management">
            <Head title="User Management" />

            {/* Header */}
            <div className="mb-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-[#1A365D]/10 rounded-lg">
                            <UsersIcon className="w-6 h-6 text-[#1A365D]" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-[#2D3748]">User Management</h1>
                            <p className="text-sm text-[#718096]">
                                {users?.total || 0} user{(users?.total || 0) !== 1 ? 's' : ''} in your organization
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Users Table */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead>
                            <tr className="bg-gray-50 border-b border-gray-100">
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Name
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Email
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Job Title
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Department
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Roles
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Last Login
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {users?.data?.length > 0 ? (
                                users.data.map((user) => (
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
                                                <span className="text-sm font-medium text-[#2D3748]">{user.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-sm text-[#718096]">{user.email}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-sm text-[#2D3748]">{user.job_title || '--'}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-sm text-[#2D3748]">{user.department || '--'}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles?.length > 0 ? (
                                                    user.roles.map((role) => (
                                                        <RoleBadge key={role.id} role={role.name} />
                                                    ))
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
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center">
                                        <UserIcon className="w-10 h-10 text-gray-300 mx-auto" />
                                        <p className="text-sm text-[#718096] mt-2">No users found</p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {users?.links && (
                    <div className="px-4 py-3 border-t border-gray-100">
                        <Pagination links={users.links} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
