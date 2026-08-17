import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, ChevronUpIcon, ChevronDownIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import Pagination from '@/Components/Pagination';

const criticalityColors = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
};

function CriticalityBadge({ criticality }) {
    const color = criticalityColors[criticality] || '#718096';
    return (
        <span
            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full"
            style={{ backgroundColor: color + '15', color }}
        >
            {criticality?.charAt(0).toUpperCase() + criticality?.slice(1)}
        </span>
    );
}

export default function BiaIndex({ records, filters, criticalities, users }) {
    const [showForm, setShowForm] = useState(false);
    const [search, setSearch] = useState(filters.search || '');

    const { data, setData, post, processing, errors, reset } = useForm({
        process_name: '',
        department: '',
        description: '',
        criticality: 'medium',
        rto_hours: '',
        rpo_hours: '',
        mtpd_hours: '',
        financial_impact_per_hour: '',
        dependencies: '',
        recovery_strategy: '',
        owner_id: '',
    });

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('bcp.bia'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('bcp.bia'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('bcp.bia.store'), {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const formatCurrency = (val) => {
        if (val == null) return '-';
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val);
    };

    return (
        <AuthenticatedLayout header="Business Impact Analysis">
            <Head title="Business Impact Analysis" />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <p className="text-sm text-[#718096]">
                    {records.total} BIA record{records.total !== 1 ? 's' : ''}
                </p>
                <button
                    onClick={() => setShowForm(!showForm)}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                >
                    {showForm ? (
                        <><ChevronUpIcon className="w-4 h-4" /> Hide Form</>
                    ) : (
                        <><PlusIcon className="w-4 h-4" /> Add BIA Record</>
                    )}
                </button>
            </div>

            {/* Inline Form */}
            {showForm && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
                    <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-4">New BIA Record</h3>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="process_name" value="Process Name *" />
                                <TextInput
                                    id="process_name"
                                    value={data.process_name}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('process_name', e.target.value)}
                                    required
                                    placeholder="e.g. Payment Processing"
                                />
                                <InputError message={errors.process_name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="department" value="Department" />
                                <TextInput
                                    id="department"
                                    value={data.department}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('department', e.target.value)}
                                    placeholder="e.g. Finance"
                                />
                                <InputError message={errors.department} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="bia_description" value="Description" />
                            <textarea
                                id="bia_description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the business process..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <InputLabel htmlFor="criticality" value="Criticality *" />
                                <select
                                    id="criticality"
                                    value={data.criticality}
                                    onChange={e => setData('criticality', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    {criticalities.map(c => (
                                        <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>
                                    ))}
                                </select>
                                <InputError message={errors.criticality} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="bia_rto" value="RTO (hours)" />
                                <TextInput
                                    id="bia_rto"
                                    type="number"
                                    min="0"
                                    value={data.rto_hours}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('rto_hours', e.target.value)}
                                />
                                <InputError message={errors.rto_hours} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="bia_rpo" value="RPO (hours)" />
                                <TextInput
                                    id="bia_rpo"
                                    type="number"
                                    min="0"
                                    value={data.rpo_hours}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('rpo_hours', e.target.value)}
                                />
                                <InputError message={errors.rpo_hours} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="mtpd" value="MTPD (hours)" />
                                <TextInput
                                    id="mtpd"
                                    type="number"
                                    min="0"
                                    value={data.mtpd_hours}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('mtpd_hours', e.target.value)}
                                />
                                <InputError message={errors.mtpd_hours} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="financial_impact" value="Financial Impact / Hour ($)" />
                                <TextInput
                                    id="financial_impact"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={data.financial_impact_per_hour}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('financial_impact_per_hour', e.target.value)}
                                    placeholder="0.00"
                                />
                                <InputError message={errors.financial_impact_per_hour} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="bia_owner" value="Owner" />
                                <select
                                    id="bia_owner"
                                    value={data.owner_id}
                                    onChange={e => setData('owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select owner...</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.owner_id} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="dependencies" value="Dependencies" />
                                <textarea
                                    id="dependencies"
                                    value={data.dependencies}
                                    onChange={e => setData('dependencies', e.target.value)}
                                    rows={2}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    placeholder="Systems, services, or processes this depends on..."
                                />
                                <InputError message={errors.dependencies} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="recovery_strategy" value="Recovery Strategy" />
                                <textarea
                                    id="recovery_strategy"
                                    value={data.recovery_strategy}
                                    onChange={e => setData('recovery_strategy', e.target.value)}
                                    rows={2}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    placeholder="How to recover this process..."
                                />
                                <InputError message={errors.recovery_strategy} className="mt-1" />
                            </div>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <PrimaryButton disabled={processing}>
                                {processing ? 'Saving...' : 'Save BIA Record'}
                            </PrimaryButton>
                            <button
                                type="button"
                                onClick={() => { reset(); setShowForm(false); }}
                                className="text-sm text-[#718096] hover:text-[#2D3748]"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Search & Filter */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4 flex flex-wrap gap-3 items-center">
                    <form onSubmit={handleSearch} className="flex gap-2 flex-1 min-w-[200px]">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Search by process or department..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">
                            Search
                        </button>
                    </form>
                    <select
                        value={filters.criticality || ''}
                        onChange={e => applyFilter('criticality', e.target.value)}
                        className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                    >
                        <option value="">All Criticalities</option>
                        {criticalities.map(c => (
                            <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>
                        ))}
                    </select>
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-100 bg-gray-50/50">
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Process Name</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Department</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Criticality</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">RTO / RPO</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">MTPD</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Financial Impact/hr</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Owner</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {records.data.map(record => (
                                <tr key={record.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3 font-medium text-[#2D3748]">{record.process_name}</td>
                                    <td className="px-4 py-3 text-[#718096]">{record.department || '-'}</td>
                                    <td className="px-4 py-3"><CriticalityBadge criticality={record.criticality} /></td>
                                    <td className="px-4 py-3 font-mono text-xs text-[#718096]">
                                        {record.rto_hours != null ? `${record.rto_hours}h` : '-'} / {record.rpo_hours != null ? `${record.rpo_hours}h` : '-'}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-[#718096]">
                                        {record.mtpd_hours != null ? `${record.mtpd_hours}h` : '-'}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-[#718096]">
                                        {formatCurrency(record.financial_impact_per_hour)}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">{record.owner?.name || '-'}</td>
                                </tr>
                            ))}
                            {records.data.length === 0 && (
                                <tr>
                                    <td colSpan="7" className="px-4 py-12 text-center text-[#718096]">
                                        No BIA records found. Add your first business impact analysis record.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={records.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
