import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';

const licenceTypes = [
    { value: 'dmb', label: 'Deposit Money Bank (DMB)' },
    { value: 'mfb', label: 'Microfinance Bank (MFB)' },
    { value: 'mortgage_bank', label: 'Mortgage Bank' },
    { value: 'psb', label: 'Payment Service Bank (PSB)' },
    { value: 'merchant_bank', label: 'Merchant Bank' },
    { value: 'development_finance', label: 'Development Finance Institution' },
];

function Field({ label, children, error }) {
    return (
        <div>
            <label className="block text-sm font-medium text-[#2D3748] mb-1">{label}</label>
            {children}
            {error && <p className="text-xs text-[#C53030] mt-1">{error}</p>}
        </div>
    );
}

function Input({ value, onChange, type = 'text', ...props }) {
    return (
        <input type={type} value={value || ''} onChange={onChange}
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
            {...props} />
    );
}

export default function InstitutionProfile({ assessment, profile, stakeholders, stakeholderRoles }) {
    const { data, setData, post, processing, errors } = useForm({
        institution_name: profile?.institution_name || '',
        cbn_licence_type: profile?.cbn_licence_type || 'dmb',
        head_office_address: profile?.head_office_address || '',
        ciso_name: profile?.ciso_name || '',
        ciso_email: profile?.ciso_email || '',
        ciso_phone: profile?.ciso_phone || '',
        ciso_grade: profile?.ciso_grade || '',
        ciso_reporting_line: profile?.ciso_reporting_line || '',
        parent_bank_name: profile?.parent_bank_name || '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('csat.institution-profile.save', assessment.id));
    };

    const saveStakeholder = (roleKey, status, comment = '', name = '') => {
        router.post(route('csat.stakeholder.save', assessment.id), {
            role_key: roleKey,
            engagement_status: status,
            comment,
            name_of_person: name,
        }, { preserveScroll: true });
    };

    const stakeholderMap = {};
    (stakeholders || []).forEach(s => { stakeholderMap[s.role_key] = s; });

    return (
        <AuthenticatedLayout header="Institution Profile">
            <Head title="Institution Profile" />

            <div className="mb-4">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
            </div>

            {/* Institution Details */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-lg font-semibold text-[#2D3748] mb-4">Institution Details (Sheet 7)</h3>
                <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Institution Name" error={errors.institution_name}>
                        <Input value={data.institution_name} onChange={e => setData('institution_name', e.target.value)} />
                    </Field>
                    <Field label="CBN Licence Type" error={errors.cbn_licence_type}>
                        <select value={data.cbn_licence_type} onChange={e => setData('cbn_licence_type', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30">
                            {licenceTypes.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>
                    </Field>
                    <div className="md:col-span-2">
                        <Field label="Head Office Address" error={errors.head_office_address}>
                            <textarea value={data.head_office_address} onChange={e => setData('head_office_address', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30" />
                        </Field>
                    </div>
                    <Field label="CISO Name" error={errors.ciso_name}>
                        <Input value={data.ciso_name} onChange={e => setData('ciso_name', e.target.value)} />
                    </Field>
                    <Field label="CISO Email" error={errors.ciso_email}>
                        <Input value={data.ciso_email} onChange={e => setData('ciso_email', e.target.value)} type="email" />
                    </Field>
                    <Field label="CISO Phone" error={errors.ciso_phone}>
                        <Input value={data.ciso_phone} onChange={e => setData('ciso_phone', e.target.value)} />
                    </Field>
                    <Field label="CISO Grade" error={errors.ciso_grade}>
                        <Input value={data.ciso_grade} onChange={e => setData('ciso_grade', e.target.value)} />
                    </Field>
                    <Field label="CISO Reporting Line" error={errors.ciso_reporting_line}>
                        <Input value={data.ciso_reporting_line} onChange={e => setData('ciso_reporting_line', e.target.value)} />
                    </Field>
                    <Field label="Parent Bank Name" error={errors.parent_bank_name}>
                        <Input value={data.parent_bank_name} onChange={e => setData('parent_bank_name', e.target.value)} />
                    </Field>
                    <div className="md:col-span-2">
                        <button type="submit" disabled={processing}
                            className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-50">
                            Save Profile
                        </button>
                    </div>
                </form>
            </div>

            {/* Stakeholder Engagement Matrix */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 className="text-lg font-semibold text-[#2D3748] mb-4">Cybersecurity Stakeholder Engagement Matrix</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-200">
                                <th className="text-left py-2 text-[#718096] font-medium">Role</th>
                                <th className="text-left py-2 text-[#718096] font-medium">Name</th>
                                <th className="text-left py-2 text-[#718096] font-medium">Engagement</th>
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(stakeholderRoles).map(([key, label]) => {
                                const s = stakeholderMap[key];
                                return (
                                    <tr key={key} className="border-b border-gray-50">
                                        <td className="py-3 font-medium text-[#2D3748]">{label}</td>
                                        <td className="py-3">
                                            <input type="text" defaultValue={s?.name_of_person || ''} placeholder="Enter name"
                                                className="px-2 py-1 border border-gray-200 rounded text-sm w-48"
                                                onBlur={e => saveStakeholder(key, s?.engagement_status || 'na', s?.comment, e.target.value)} />
                                        </td>
                                        <td className="py-3">
                                            <select defaultValue={s?.engagement_status || 'na'}
                                                className="px-2 py-1 border border-gray-200 rounded text-sm"
                                                onChange={e => saveStakeholder(key, e.target.value, '', s?.name_of_person)}>
                                                <option value="yes">Yes</option>
                                                <option value="no">No</option>
                                                <option value="na">N/A</option>
                                                <option value="yes_with_comment">Yes (with comment)</option>
                                            </select>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
