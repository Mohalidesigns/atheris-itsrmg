import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import Checkbox from '@/Components/Checkbox';
import { Link } from '@inertiajs/react';

export default function UserForm({ data, setData, errors, processing, onSubmit, roles, organizations, isEdit = false }) {
    const toggleRole = (role, on) => {
        setData('roles', on ? [...data.roles, role] : data.roles.filter(r => r !== role));
    };

    return (
        <form onSubmit={onSubmit} className="space-y-5">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="name" value="Full Name *" />
                    <TextInput
                        id="name"
                        value={data.name}
                        className="mt-1 block w-full"
                        onChange={e => setData('name', e.target.value)}
                        required
                        placeholder="e.g. Amina Yusuf"
                    />
                    <InputError message={errors.name} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="email" value="Email Address *" />
                    <TextInput
                        id="email"
                        type="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        onChange={e => setData('email', e.target.value)}
                        required
                        placeholder="e.g. amina.yusuf@acme.ng"
                    />
                    <InputError message={errors.email} className="mt-1" />
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="job_title" value="Job Title" />
                    <TextInput
                        id="job_title"
                        value={data.job_title}
                        className="mt-1 block w-full"
                        onChange={e => setData('job_title', e.target.value)}
                        placeholder="e.g. Security Analyst"
                    />
                    <InputError message={errors.job_title} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="department" value="Department" />
                    <TextInput
                        id="department"
                        value={data.department}
                        className="mt-1 block w-full"
                        onChange={e => setData('department', e.target.value)}
                        placeholder="e.g. Information Security"
                    />
                    <InputError message={errors.department} className="mt-1" />
                </div>
            </div>

            <div>
                <InputLabel htmlFor="organization_id" value="Organization *" />
                <select
                    id="organization_id"
                    value={data.organization_id}
                    onChange={e => setData('organization_id', e.target.value)}
                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                    required
                >
                    <option value="">Select organization...</option>
                    {organizations.map(org => (
                        <option key={org.id} value={org.id}>{org.name}</option>
                    ))}
                </select>
                <InputError message={errors.organization_id} className="mt-1" />
            </div>

            {/* Password */}
            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                <h4 className="text-sm font-semibold text-[#2D3748] mb-1">
                    {isEdit ? 'Change Password' : 'Password'}
                </h4>
                {isEdit && (
                    <p className="text-xs text-[#718096] mb-3">
                        Leave blank to keep the current password.
                    </p>
                )}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <div>
                        <InputLabel htmlFor="password" value={isEdit ? 'New Password' : 'Password *'} />
                        <TextInput
                            id="password"
                            type="password"
                            value={data.password}
                            className="mt-1 block w-full"
                            onChange={e => setData('password', e.target.value)}
                            required={!isEdit}
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="password_confirmation" value={isEdit ? 'Confirm New Password' : 'Confirm Password *'} />
                        <TextInput
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            className="mt-1 block w-full"
                            onChange={e => setData('password_confirmation', e.target.value)}
                            required={!isEdit}
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password_confirmation} className="mt-1" />
                    </div>
                </div>
            </div>

            {/* Roles */}
            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                <h4 className="text-sm font-semibold text-[#2D3748] mb-1">Roles *</h4>
                <p className="text-xs text-[#718096] mb-3">
                    Roles determine which modules and actions this user can access.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {roles.map(role => (
                        <label
                            key={role}
                            className={`flex items-center gap-2.5 px-3 py-2 rounded-lg border cursor-pointer transition-colors
                                ${data.roles.includes(role)
                                    ? 'border-[#1A365D]/40 bg-[#1A365D]/5'
                                    : 'border-gray-200 bg-white hover:bg-gray-50'
                                }`}
                        >
                            <Checkbox
                                checked={data.roles.includes(role)}
                                onChange={e => toggleRole(role, e.target.checked)}
                            />
                            <span className="text-sm text-[#2D3748]">{role}</span>
                        </label>
                    ))}
                </div>
                <InputError message={errors.roles} className="mt-2" />
            </div>

            <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <Link href={route('admin.users.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                    Cancel
                </Link>
                <PrimaryButton disabled={processing}>
                    {isEdit ? 'Save Changes' : 'Create User'}
                </PrimaryButton>
            </div>
        </form>
    );
}
