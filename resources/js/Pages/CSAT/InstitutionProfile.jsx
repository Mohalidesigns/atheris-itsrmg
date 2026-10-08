import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { lockMessage } from '@/Utils/csat';

const ENGAGEMENT = [['yes', 'Yes'], ['no', 'No'], ['na', 'N/A'], ['yes_with_comment', 'Yes (with comment)']];
const NEEDS_COMMENT = ['no', 'yes_with_comment'];

function StakeholderRow({ assessment, roleKey, label, existing, editable }) {
    const { data, setData, post, processing, errors, isDirty, recentlySuccessful } = useForm({
        role_key: roleKey,
        name_of_person: existing?.name_of_person || '',
        engagement_status: existing?.engagement_status || '',
        comment: existing?.comment || '',
    });
    const needsComment = NEEDS_COMMENT.includes(data.engagement_status);
    const save = (e) => {
        e.preventDefault();
        post(route('csat.stakeholder.save', assessment.id), { preserveScroll: true });
    };
    const disabled = !editable || processing;

    return (
        <tr className="border-b border-gray-50 align-top">
            <td className="py-3 pr-3 font-medium text-[#2D3748] whitespace-nowrap">{label}</td>
            <td className="py-3 pr-3">
                <input type="text" value={data.name_of_person} disabled={disabled} placeholder="Name"
                    onChange={e => setData('name_of_person', e.target.value)}
                    className="px-2 py-1 border border-gray-200 rounded text-sm w-44 disabled:bg-gray-50" />
            </td>
            <td className="py-3 pr-3">
                <select value={data.engagement_status} disabled={disabled} onChange={e => setData('engagement_status', e.target.value)}
                    className={`px-2 py-1 border rounded text-sm disabled:bg-gray-50 ${data.engagement_status ? 'border-gray-200' : 'border-amber-300 text-amber-700'}`}>
                    <option value="">— Not attested —</option>
                    {ENGAGEMENT.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                {errors.engagement_status && <p className="text-xs text-[#C53030] mt-1">{errors.engagement_status}</p>}
            </td>
            <td className="py-3 pr-3 w-full">
                <input type="text" value={data.comment} disabled={disabled}
                    placeholder={needsComment ? 'Comment required' : 'Comment (optional)'}
                    onChange={e => setData('comment', e.target.value)}
                    className={`px-2 py-1 border rounded text-sm w-full disabled:bg-gray-50 ${needsComment && !data.comment ? 'border-amber-300' : 'border-gray-200'}`} />
                {errors.comment && <p className="text-xs text-[#C53030] mt-1">{errors.comment}</p>}
            </td>
            <td className="py-3 whitespace-nowrap">
                {editable && (
                    <button onClick={save} disabled={processing || !isDirty || !data.engagement_status || (needsComment && !data.comment.trim())}
                        className="px-3 py-1 text-xs rounded bg-[#1A365D] text-white disabled:opacity-40">
                        {processing ? 'Saving…' : 'Save'}
                    </button>
                )}
                {recentlySuccessful && <span className="ml-2 text-xs text-[#2D7D46]">Saved</span>}
            </td>
        </tr>
    );
}

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

export default function InstitutionProfile({ assessment, profile, stakeholders, stakeholderRoles, editable = true }) {
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

    const stakeholderMap = {};
    (stakeholders || []).forEach(s => { stakeholderMap[s.role_key] = s; });
    const attested = (stakeholders || []).filter(s => s.engagement_status).length;

    return (
        <AuthenticatedLayout header="Institution Profile">
            <Head title="Institution Profile" />

            <div className="mb-4">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            {/* Institution Details */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-lg font-semibold text-[#2D3748] mb-4">Institution Details (Sheet 7)</h3>
                <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <fieldset disabled={!editable} className="contents">
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
                    {editable && (
                        <div className="md:col-span-2">
                            <button type="submit" disabled={processing}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-50">
                                {processing ? 'Saving…' : 'Save Profile'}
                            </button>
                        </div>
                    )}
                    </fieldset>
                </form>
            </div>

            {/* Stakeholder Engagement Matrix */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-lg font-semibold text-[#2D3748]">Cybersecurity Stakeholder Engagement Matrix</h3>
                    <span className={`text-xs font-medium ${attested === Object.keys(stakeholderRoles).length ? 'text-[#2D7D46]' : 'text-[#DD6B20]'}`}>{attested} / {Object.keys(stakeholderRoles).length} roles attested</span>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-200">
                                <th className="text-left py-2 text-[#718096] font-medium">Role</th>
                                <th className="text-left py-2 text-[#718096] font-medium">Name</th>
                                <th className="text-left py-2 text-[#718096] font-medium">Engagement</th>
                                <th className="text-left py-2 text-[#718096] font-medium">Comment</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(stakeholderRoles).map(([key, label]) => (
                                <StakeholderRow key={key} assessment={assessment} roleKey={key} label={label} existing={stakeholderMap[key]} editable={editable} />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
