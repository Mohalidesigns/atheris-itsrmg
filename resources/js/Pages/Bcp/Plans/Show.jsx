import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ClockIcon, ShieldCheckIcon, DocumentTextIcon } from '@/Components/Material/Symbols';

const typeLabels = { bcp: 'BCP', dr: 'DR', crisis: 'Crisis' };
const typeColors = { bcp: '#1A365D', dr: '#2D7D46', crisis: '#C53030' };
const statusColors = {
    draft: '#718096',
    active: '#2D7D46',
    under_review: '#D4AF37',
    tested: '#1A365D',
    expired: '#C53030',
};
const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null);
const passFailColors = { pass: '#2D7D46', partial: '#D4AF37', fail: '#C53030' };

function TabButton({ active, children, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                active
                    ? 'border-[#D4AF37] text-[#1A365D]'
                    : 'border-transparent text-[#718096] hover:text-[#2D3748] hover:border-gray-300'
            }`}
        >
            {children}
        </button>
    );
}

function RecordTest({ plan, testTypes }) {
    const form = useForm({ title: `${plan.plan_code} exercise`, test_type: 'tabletop', completed_date: new Date().toISOString().slice(0, 10), pass_fail: 'pass', rto_achieved_hours: '', results: '', findings: '', recommendations: '' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('bcp.plans.tests.store', plan.id), { preserveScroll: true, onSuccess: () => form.reset('results', 'findings', 'recommendations', 'rto_achieved_hours') }); }}
            className="mb-4 grid grid-cols-1 gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm sm:grid-cols-4">
            <h4 className="sm:col-span-4 text-sm font-semibold text-[#2D3748]">Record a test or exercise</h4>
            <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className="sm:col-span-2 rounded-lg border-gray-300 text-sm" />
            <select value={form.data.test_type} onChange={(e) => form.setData('test_type', e.target.value)} className="rounded-lg border-gray-300 text-sm">{testTypes.map((t) => <option key={t} value={t}>{t}</option>)}</select>
            <input type="date" value={form.data.completed_date} onChange={(e) => form.setData('completed_date', e.target.value)} className="rounded-lg border-gray-300 text-sm" />
            <select value={form.data.pass_fail} onChange={(e) => form.setData('pass_fail', e.target.value)} className="rounded-lg border-gray-300 text-sm">
                <option value="pass">Pass</option><option value="partial">Passed with issues</option><option value="fail">Fail</option>
            </select>
            <input type="number" min="0" step="0.5" value={form.data.rto_achieved_hours} onChange={(e) => form.setData('rto_achieved_hours', e.target.value)} placeholder={`Recovered in (h); RTO ${plan.rto_hours ?? '—'}h`} className="rounded-lg border-gray-300 text-sm" />
            <textarea rows={2} value={form.data.results} onChange={(e) => form.setData('results', e.target.value)} placeholder="What was tested and what happened *" className="sm:col-span-2 rounded-lg border-gray-300 text-sm" />
            <textarea rows={2} value={form.data.findings} onChange={(e) => form.setData('findings', e.target.value)} placeholder={form.data.pass_fail === 'pass' ? 'Findings (optional)' : 'Findings *'} className="sm:col-span-2 rounded-lg border-gray-300 text-sm" />
            <textarea rows={2} value={form.data.recommendations} onChange={(e) => form.setData('recommendations', e.target.value)} placeholder="Recommendations" className="sm:col-span-2 rounded-lg border-gray-300 text-sm" />
            <div className="sm:col-span-4">{err('results')}{err('findings')}{err('completed_date')}</div>
            <div className="sm:col-span-4 flex justify-end"><button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Record test</button></div>
        </form>
    );
}

export default function ShowPlan({ plan, testOverdue = false, testTypes = [], can = {} }) {
    const [approveError, setApproveError] = useState(null);
    const approve = () => router.post(route('bcp.plans.approve', plan.id), {}, { preserveScroll: true, onError: (e) => setApproveError(Object.values(e).join(' ')) });
    const [activeTab, setActiveTab] = useState('details');

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {plan.plan_code}
                </span>
                <span>{plan.title}</span>
            </div>
        }>
            <Head title={`${plan.plan_code} - ${plan.title}`} />

            {/* Header Cards */}
            {['draft', 'under_review'].includes(plan.status) && <div className="mb-4"><ApprovalTrail process="bcp.plan" id={plan.id} /></div>}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <div className="mt-1">
                        <span
                            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-lg text-white whitespace-nowrap"
                            style={{ backgroundColor: typeColors[plan.plan_type] || '#718096' }}
                        >
                            {typeLabels[plan.plan_type] || plan.plan_type}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1">
                        <span
                            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-lg whitespace-nowrap"
                            style={{
                                backgroundColor: (statusColors[plan.status] || '#718096') + '15',
                                color: statusColors[plan.status] || '#718096',
                            }}
                        >
                            {plan.status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
                        </span>
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RTO</p>
                    <p className="mt-1 text-lg font-semibold text-[#2D3748] font-mono">
                        {plan.rto_hours != null ? `${plan.rto_hours}h` : '-'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">RPO</p>
                    <p className="mt-1 text-lg font-semibold text-[#2D3748] font-mono">
                        {plan.rpo_hours != null ? `${plan.rpo_hours}h` : '-'}
                    </p>
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                {can.edit && <Link href={route('bcp.plans.edit', plan.id)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-[#718096] hover:bg-gray-50">Edit</Link>}
                {can.approve && ['draft', 'under_review'].includes(plan.status) && (
                    <button type="button" onClick={approve} className="rounded-lg bg-[#2D7D46] px-3 py-1.5 text-sm font-semibold text-white">Approve plan</button>
                )}
                {approveError && <span className="text-xs text-[#C53030]">{approveError}</span>}
                {testOverdue && <span className="rounded-lg bg-[#FFF8E6] px-3 py-1.5 text-xs text-[#8A6400]">Not tested in the last 12 months{plan.last_tested ? ` (last ${new Date(plan.last_tested).toLocaleDateString('en-GB')})` : ''}.</span>}
                {plan.approver && <span className="text-xs text-[#718096]">{plan.status === 'active' ? 'Approved' : 'Last approved'} by {plan.approver.name}{plan.approved_at ? ` on ${new Date(plan.approved_at).toLocaleDateString('en-GB')}` : ''}</span>}
            </div>

            <div className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <h3 className="text-sm font-semibold text-[#2D3748]">Business services recovered by this plan</h3>
                {(plan.services || []).length === 0 ? <p className="mt-1 text-xs text-[#8A6400]">No business service linked. Link services from Edit so the plan's RTO can be checked against them.</p> : (
                    <ul className="mt-2 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
                        {plan.services.map((sv) => {
                            const conflict = plan.rto_hours != null && sv.rto_minutes != null && plan.rto_hours * 60 > sv.rto_minutes;
                            return (
                                <li key={sv.id} className="flex justify-between">
                                    <Link href={route('business-assets.show', sv.id)} className="text-[#2D3748] hover:underline">{sv.name}</Link>
                                    <span className={`text-xs ${conflict ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>service RTO {sv.rto_minutes != null ? `${sv.rto_minutes} min` : '—'}{conflict ? ' · plan RTO longer' : ''}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {/* Tabs */}
            <div className="border-b border-gray-200 mb-6">
                <div className="flex gap-0">
                    <TabButton active={activeTab === 'details'} onClick={() => setActiveTab('details')}>
                        <span className="flex items-center gap-1.5"><DocumentTextIcon className="w-4 h-4" /> Details</span>
                    </TabButton>
                    <TabButton active={activeTab === 'tests'} onClick={() => setActiveTab('tests')}>
                        <span className="flex items-center gap-1.5"><ShieldCheckIcon className="w-4 h-4" /> Tests ({plan.tests?.length || 0})</span>
                    </TabButton>
                </div>
            </div>

            {/* Details Tab */}
            {activeTab === 'details' && (
                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-4">Plan Information</h3>
                        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Owner</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{plan.owner?.name || 'Unassigned'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Version</dt>
                                <dd className="mt-1 text-sm text-[#2D3748] font-mono">{plan.version}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Last Tested</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{fmtDate(plan.last_tested) || 'Never'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Next Test Date</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{fmtDate(plan.next_test_date) || 'Not scheduled'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Next Review Date</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">{fmtDate(plan.next_review_date) || 'Not scheduled'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-[#718096] font-medium">Approved By</dt>
                                <dd className="mt-1 text-sm text-[#2D3748]">
                                    {plan.approver?.name || 'Not approved'}
                                    {plan.approved_at && <span className="text-[#718096] ml-1">({fmtDate(plan.approved_at)})</span>}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {plan.description && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Description</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.description}</p>
                        </div>
                    )}

                    {plan.scope && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Scope</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.scope}</p>
                        </div>
                    )}

                    {plan.objectives && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                            <h3 className="text-sm font-semibold text-[#2D3748] uppercase tracking-wide mb-3">Objectives</h3>
                            <p className="text-sm text-[#4A5568] whitespace-pre-wrap">{plan.objectives}</p>
                        </div>
                    )}
                </div>
            )}

            {/* Tests Tab */}
            {activeTab === 'tests' && can.edit && <RecordTest plan={plan} testTypes={testTypes} />}
            {activeTab === 'tests' && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    {plan.tests && plan.tests.length > 0 ? (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50/50">
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Title</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Type</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Status</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Scheduled</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Result</th>
                                    <th className="text-left px-4 py-3 font-medium text-[#718096]">Conductor</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {plan.tests.map(test => (
                                    <tr key={test.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3 text-[#2D3748] font-medium">{test.title}</td>
                                        <td className="px-4 py-3 text-[#718096] capitalize">{test.test_type}</td>
                                        <td className="px-4 py-3">
                                            <span className="capitalize text-[#718096]">{test.status?.replace('_', ' ')}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{test.scheduled_date}</td>
                                        <td className="px-4 py-3">
                                            {test.pass_fail ? (
                                                <span
                                                    className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-lg text-white whitespace-nowrap"
                                                    style={{ backgroundColor: passFailColors[test.pass_fail] || '#718096' }}
                                                >
                                                    {test.pass_fail.charAt(0).toUpperCase() + test.pass_fail.slice(1)}
                                                </span>
                                            ) : (
                                                <span className="text-[#718096]">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{test.conductor?.name || '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <div className="px-4 py-12 text-center text-[#718096]">
                            <ShieldCheckIcon className="w-8 h-8 mx-auto mb-2 text-gray-300" />
                            <p>No tests recorded yet.</p>
                        </div>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
