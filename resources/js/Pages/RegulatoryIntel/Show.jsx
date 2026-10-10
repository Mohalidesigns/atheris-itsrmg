import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowTopRightOnSquareIcon, LockClosedIcon, TrashIcon } from '@heroicons/react/24/outline';
import { TRIAGE } from './Index';

const IMPACT_TYPES = { obligation: 'Obligation', control: 'Control', policy: 'Policy', risk: 'Risk' };
const field = 'mt-1 w-full rounded-lg border-gray-200 text-sm focus:border-[#0A1F44] focus:ring-[#0A1F44]/30';

export default function RegIntelShow({ circular, assessment, impacts = [], impactOptions = {}, users = [], canEditorial = false }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const isSuper = (auth?.user?.roles || []).includes('Super Admin');
    const canEdit = isSuper || perms.includes('edit platform');

    const triage = useForm({
        status: assessment?.status || 'assessed',
        applicability: assessment?.applicability || '',
        severity: assessment?.severity || '',
        owner_id: assessment?.owner_id || '',
        due_date: assessment?.due_date || '',
        notes: assessment?.notes || '',
    });

    const impact = useForm({ impacted_type: 'obligation', impacted_id: '', impact_score: 3, rationale: '', action_required: '' });
    const options = impactOptions[impact.data.impacted_type] || [];
    const current = TRIAGE[assessment?.status || 'unread'];

    return (
        <AuthenticatedLayout header="Regulatory Circular">
            <Head title={circular.title} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Regulatory Intelligence' },
                    { label: 'Circular Feed', href: route('reg-intel.index') },
                    { label: circular.circular_number || circular.title },
                ]}
                title={circular.title}
                subtitle={`${circular.regulator_code} · ${circular.circular_number || 'No reference'} · Issued ${circular.issued_at || '—'}`}
                actions={<StatusBadge status={current.tone} label={current.label} />}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div className="flex flex-wrap items-center gap-2 text-xs text-[#718096]">
                            {circular.is_private ? (
                                <span className="inline-flex items-center gap-1 text-[#8A6400]">
                                    <LockClosedIcon className="h-3.5 w-3.5" /> Uploaded by your bank through Document Intelligence
                                    {circular.doc_intelligence_job_id && (
                                        <Link href={route('doc-intel.show', circular.doc_intelligence_job_id)} className="ml-1 underline">
                                            view extraction
                                        </Link>
                                    )}
                                </span>
                            ) : (
                                <span>Platform feed · {circular.status.replace(/_/g, ' ')}</span>
                            )}
                            {circular.ingested_at && <span>· Ingested {circular.ingested_at}</span>}
                            {circular.source_url && (
                                <a href={circular.source_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[#0A1F44] underline">
                                    Source <ArrowTopRightOnSquareIcon className="h-3 w-3" />
                                </a>
                            )}
                        </div>
                        <h3 className="mt-4 text-sm font-semibold text-[#2D3748]">Summary</h3>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-[#2D3748]">{circular.llm_summary || '—'}</p>
                        {circular.impact_assessment && (
                            <>
                                <h3 className="mt-5 text-sm font-semibold text-[#2D3748]">Content-Ops impact notes</h3>
                                <dl className="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                                    {Object.entries(circular.impact_assessment).map(([k, v]) => (
                                        <div key={k}>
                                            <dt className="inline font-medium capitalize text-[#2D3748]">{k.replace(/_/g, ' ')}: </dt>
                                            <dd className="inline text-[#718096]">{Array.isArray(v) ? v.join(', ') : String(v)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </>
                        )}
                        <h3 className="mt-5 text-sm font-semibold text-[#2D3748]">Full text</h3>
                        <p className="mt-2 max-h-96 overflow-y-auto whitespace-pre-wrap text-sm text-[#718096]">
                            {circular.plain_text || 'Full text not extracted yet.'}
                        </p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
                        <div className="border-b border-gray-100 px-5 py-3">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">Impact on your register</h3>
                            <p className="text-xs text-[#718096]">
                                Trace the circular to the obligations, controls, policies and risks it changes, so an examiner can follow the reasoning.
                            </p>
                        </div>
                        {impacts.length === 0 ? (
                            <p className="px-5 py-6 text-center text-xs text-[#718096]">No records linked yet.</p>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead className="bg-[#F7FAFC]">
                                    <tr className="text-left text-xs uppercase text-[#718096]">
                                        <th className="px-4 py-2">Record</th>
                                        <th className="px-4 py-2">Impact</th>
                                        <th className="px-4 py-2">Rationale</th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {impacts.map((i) => (
                                        <tr key={i.id}>
                                            <td className="px-4 py-2">
                                                <span className="block text-[10px] uppercase text-[#718096]">{IMPACT_TYPES[i.type] || i.type}</span>
                                                {i.href ? (
                                                    <Link href={i.href} className="text-[#0A1F44] underline">
                                                        {i.code ? `${i.code} — ` : ''}{i.label}
                                                    </Link>
                                                ) : (
                                                    <span className="text-[#718096]">{i.label}</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-xs">{Number(i.impact_score).toFixed(1)} / 5</td>
                                            <td className="px-4 py-2 text-xs text-[#2D3748]">
                                                {i.rationale}
                                                {i.action_required && <span className="block text-[#718096]">Action: {i.action_required}</span>}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {canEdit && (
                                                    <button
                                                        type="button"
                                                        aria-label="Remove impact"
                                                        onClick={() => {
                                                            if (confirm('Remove this linked record?')) {
                                                                router.delete(route('reg-intel.impacts.destroy', [circular.id, i.id]), { preserveScroll: true });
                                                            }
                                                        }}
                                                        className="text-[#718096] hover:text-[#B3261E]"
                                                    >
                                                        <TrashIcon className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {canEdit && (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    impact.post(route('reg-intel.impacts.store', circular.id), {
                                        preserveScroll: true,
                                        onSuccess: () => impact.reset('impacted_id', 'rationale', 'action_required'),
                                    });
                                }}
                                className="grid grid-cols-1 gap-3 border-t border-gray-100 p-5 sm:grid-cols-6"
                            >
                                <div className="sm:col-span-2">
                                    <label className="block text-xs text-[#718096]" htmlFor="impacted_type">Record type</label>
                                    <select id="impacted_type" className={field} value={impact.data.impacted_type}
                                        onChange={(e) => impact.setData({ ...impact.data, impacted_type: e.target.value, impacted_id: '' })}>
                                        {Object.entries(IMPACT_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select>
                                </div>
                                <div className="sm:col-span-3">
                                    <label className="block text-xs text-[#718096]" htmlFor="impacted_id">Record</label>
                                    <select id="impacted_id" className={field} value={impact.data.impacted_id}
                                        onChange={(e) => impact.setData('impacted_id', e.target.value)}>
                                        <option value="">{options.length ? 'Choose…' : 'None in your register'}</option>
                                        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                    </select>
                                    <InputError message={impact.errors.impacted_id} className="mt-1" />
                                </div>
                                <div>
                                    <label className="block text-xs text-[#718096]" htmlFor="impact_score">Impact (0–5)</label>
                                    <input id="impact_score" type="number" min="0" max="5" step="0.5" className={field}
                                        value={impact.data.impact_score} onChange={(e) => impact.setData('impact_score', e.target.value)} />
                                </div>
                                <div className="sm:col-span-3">
                                    <label className="block text-xs text-[#718096]" htmlFor="rationale">Rationale</label>
                                    <input id="rationale" className={field} value={impact.data.rationale}
                                        onChange={(e) => impact.setData('rationale', e.target.value)} placeholder="Why the circular affects this record" />
                                    <InputError message={impact.errors.rationale} className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <label className="block text-xs text-[#718096]" htmlFor="action_required">Action required</label>
                                    <input id="action_required" className={field} value={impact.data.action_required}
                                        onChange={(e) => impact.setData('action_required', e.target.value)} />
                                </div>
                                <div className="flex items-end">
                                    <button disabled={impact.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                                        Link
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>

                <aside className="h-fit space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Your bank's triage</h3>
                        {assessment?.assessed_by && (
                            <p className="mt-1 text-[11px] text-[#718096]">
                                Last updated by {assessment.assessed_by}, {assessment.assessed_at}
                                {assessment.actioned_at && <> · actioned {assessment.actioned_at}</>}
                            </p>
                        )}
                        {canEdit ? (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    triage.post(route('reg-intel.assess', circular.id), { preserveScroll: true });
                                }}
                                className="mt-3 space-y-3"
                            >
                                <div>
                                    <label className="block text-xs text-[#718096]" htmlFor="status">Status</label>
                                    <select id="status" className={field} value={triage.data.status} onChange={(e) => triage.setData('status', e.target.value)}>
                                        {Object.entries(TRIAGE).map(([k, t]) => <option key={k} value={k}>{t.label}</option>)}
                                    </select>
                                    <InputError message={triage.errors.status} className="mt-1" />
                                </div>
                                <div>
                                    <label className="block text-xs text-[#718096]" htmlFor="applicability">Applicability</label>
                                    <select id="applicability" className={field} value={triage.data.applicability} onChange={(e) => triage.setData('applicability', e.target.value)}>
                                        <option value="">Not yet decided</option>
                                        <option value="applicable">Applicable</option>
                                        <option value="partially_applicable">Partially applicable</option>
                                        <option value="not_applicable">Not applicable</option>
                                    </select>
                                    <InputError message={triage.errors.applicability} className="mt-1" />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs text-[#718096]" htmlFor="severity">Severity</label>
                                        <select id="severity" className={field} value={triage.data.severity} onChange={(e) => triage.setData('severity', e.target.value)}>
                                            <option value="">—</option>
                                            {['critical', 'high', 'medium', 'low'].map((s) => <option key={s} value={s}>{s}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs text-[#718096]" htmlFor="due_date">Action due</label>
                                        <input id="due_date" type="date" className={field} value={triage.data.due_date} onChange={(e) => triage.setData('due_date', e.target.value)} />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-xs text-[#718096]" htmlFor="owner_id">Owner</label>
                                    <select id="owner_id" className={field} value={triage.data.owner_id} onChange={(e) => triage.setData('owner_id', e.target.value)}>
                                        <option value="">Unassigned</option>
                                        {users.map((u) => <option key={u.value} value={u.value}>{u.label}</option>)}
                                    </select>
                                    <InputError message={triage.errors.owner_id} className="mt-1" />
                                </div>
                                <div>
                                    <label className="block text-xs text-[#718096]" htmlFor="notes">Assessment notes</label>
                                    <textarea id="notes" rows={4} className={field} value={triage.data.notes} onChange={(e) => triage.setData('notes', e.target.value)} />
                                    <InputError message={triage.errors.notes} className="mt-1" />
                                </div>
                                <button disabled={triage.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                                    Save triage
                                </button>
                            </form>
                        ) : (
                            <dl className="mt-3 space-y-1 text-xs">
                                <div><dt className="inline text-[#718096]">Applicability: </dt><dd className="inline">{assessment?.applicability?.replace(/_/g, ' ') || '—'}</dd></div>
                                <div><dt className="inline text-[#718096]">Owner: </dt><dd className="inline">{assessment?.owner || '—'}</dd></div>
                                <div><dt className="inline text-[#718096]">Due: </dt><dd className="inline">{assessment?.due_date || '—'}</dd></div>
                                <div><dt className="inline text-[#718096]">Notes: </dt><dd className="inline">{assessment?.notes || '—'}</dd></div>
                            </dl>
                        )}
                    </div>

                    {canEditorial && (
                        <div className="rounded-xl border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-5">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">Content-Ops editorial</h3>
                            <p className="mt-1 text-xs text-[#718096]">Tenants see a platform circular only once it is published.</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {[['editorial_review', 'Back to review'], ['published', 'Publish'], ['superseded', 'Mark superseded']]
                                    .filter(([s]) => s !== circular.status)
                                    .map(([s, label]) => (
                                        <button key={s} type="button"
                                            onClick={() => router.post(route('reg-intel.editorial', circular.id), { status: s }, { preserveScroll: true })}
                                            className="rounded border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50">
                                            {label}
                                        </button>
                                    ))}
                            </div>
                        </div>
                    )}

                    <Link href={route('reg-intel.index')} className="block text-sm text-[#0A1F44] underline">← Back to feed</Link>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
