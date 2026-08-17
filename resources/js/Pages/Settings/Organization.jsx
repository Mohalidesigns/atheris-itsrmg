import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import {
    BuildingOffice2Icon,
    GlobeAltIcon,
    PhoneIcon,
    EnvelopeIcon,
    MapPinIcon,
    CurrencyDollarIcon,
} from '@heroicons/react/24/outline';

export default function Organization({ organization }) {
    const { data, setData, put, processing, errors } = useForm({
        name: organization?.name || '',
        industry: organization?.industry || '',
        size: organization?.size || '',
        country: organization?.country || 'NG',
        currency: organization?.currency || 'NGN',
        website: organization?.website || '',
        phone: organization?.phone || '',
        email: organization?.email || '',
        address: organization?.address || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('settings.organization.update'));
    };

    const inputClasses = (field) =>
        `w-full px-3 py-2 bg-gray-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D] transition-colors ${
            errors[field] ? 'border-[#C53030]' : 'border-gray-200'
        }`;

    return (
        <AuthenticatedLayout header="Organization Settings">
            <Head title="Organization Settings" />

            <div className="max-w-3xl">
                {/* Header */}
                <div className="mb-6">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-[#1A365D]/10 rounded-lg">
                            <BuildingOffice2Icon className="w-6 h-6 text-[#1A365D]" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-[#2D3748]">Organization Settings</h1>
                            <p className="text-sm text-[#718096]">Manage your organization profile and preferences</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    {/* Basic Information */}
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
                        <h2 className="text-base font-semibold text-[#2D3748] mb-4">Basic Information</h2>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {/* Organization Name */}
                            <div className="sm:col-span-2">
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    Organization Name <span className="text-[#C53030]">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className={inputClasses('name')}
                                    placeholder="Enter organization name"
                                />
                                {errors.name && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.name}</p>
                                )}
                            </div>

                            {/* Industry */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Industry</label>
                                <input
                                    type="text"
                                    value={data.industry}
                                    onChange={(e) => setData('industry', e.target.value)}
                                    className={inputClasses('industry')}
                                    placeholder="e.g. Financial Services"
                                />
                                {errors.industry && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.industry}</p>
                                )}
                            </div>

                            {/* Organization Size */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Organization Size</label>
                                <select
                                    value={data.size}
                                    onChange={(e) => setData('size', e.target.value)}
                                    className={inputClasses('size')}
                                >
                                    <option value="">Select size</option>
                                    <option value="small">Small (1-50 employees)</option>
                                    <option value="medium">Medium (51-250 employees)</option>
                                    <option value="large">Large (251-1000 employees)</option>
                                    <option value="enterprise">Enterprise (1000+ employees)</option>
                                </select>
                                {errors.size && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.size}</p>
                                )}
                            </div>

                            {/* Country */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Country</label>
                                <input
                                    type="text"
                                    value={data.country}
                                    onChange={(e) => setData('country', e.target.value)}
                                    className={inputClasses('country')}
                                    placeholder="e.g. NG"
                                />
                                {errors.country && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.country}</p>
                                )}
                            </div>

                            {/* Currency */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    <CurrencyDollarIcon className="w-4 h-4 inline mr-1" />
                                    Currency
                                </label>
                                <select
                                    value={data.currency}
                                    onChange={(e) => setData('currency', e.target.value)}
                                    className={inputClasses('currency')}
                                >
                                    <option value="NGN">NGN - Nigerian Naira</option>
                                    <option value="USD">USD - US Dollar</option>
                                    <option value="GBP">GBP - British Pound</option>
                                    <option value="EUR">EUR - Euro</option>
                                    <option value="GHS">GHS - Ghanaian Cedi</option>
                                    <option value="KES">KES - Kenyan Shilling</option>
                                    <option value="ZAR">ZAR - South African Rand</option>
                                </select>
                                {errors.currency && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.currency}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Contact Information */}
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
                        <h2 className="text-base font-semibold text-[#2D3748] mb-4">Contact Information</h2>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {/* Website */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    <GlobeAltIcon className="w-4 h-4 inline mr-1" />
                                    Website
                                </label>
                                <input
                                    type="url"
                                    value={data.website}
                                    onChange={(e) => setData('website', e.target.value)}
                                    className={inputClasses('website')}
                                    placeholder="https://example.com"
                                />
                                {errors.website && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.website}</p>
                                )}
                            </div>

                            {/* Phone */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    <PhoneIcon className="w-4 h-4 inline mr-1" />
                                    Phone
                                </label>
                                <input
                                    type="tel"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    className={inputClasses('phone')}
                                    placeholder="+234 xxx xxx xxxx"
                                />
                                {errors.phone && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.phone}</p>
                                )}
                            </div>

                            {/* Email */}
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    <EnvelopeIcon className="w-4 h-4 inline mr-1" />
                                    Email
                                </label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className={inputClasses('email')}
                                    placeholder="info@example.com"
                                />
                                {errors.email && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.email}</p>
                                )}
                            </div>

                            {/* Address */}
                            <div className="sm:col-span-2">
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">
                                    <MapPinIcon className="w-4 h-4 inline mr-1" />
                                    Address
                                </label>
                                <textarea
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    className={inputClasses('address')}
                                    rows={3}
                                    placeholder="Enter full address"
                                />
                                {errors.address && (
                                    <p className="text-xs text-[#C53030] mt-1">{errors.address}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-6 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : 'Save Changes'}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
