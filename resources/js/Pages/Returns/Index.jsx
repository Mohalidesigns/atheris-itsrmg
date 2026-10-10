import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

/**
 * Returns Centre (spec §6.4.3): regulator return templates and their runs, each run auto-populated
 * from the bank's register with manual overrides, then Draft → In review → Approved → Submitted.
 *
 * Distinct from Architecture Returns (/regulatory-returns), which compiles returns from the EA graph
 * with entity-level evidence citations.
 */
export const RUN_STATUS = {
    draft: ['draft', 'Draft'],
    in_review: ['in_review', 'In review'],
    approved: ['approved', 'Approved'],
    submitted: ['pass', 'Submitted'],
};

const PERIOD_HINT = { monthly: '2026-09', quarterly: '2026-Q3', annual: '2026' };

export default function ReturnsIndex({ templates = [], runs = [] }) {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const canCreate = (auth?.user?.roles || []).includes('Super Admin') || perms.includes('create platform');
    const [periodFor, setPeriodFor] = useState(null);
    const form = useForm({ template_id: '', period: '' });

    const generate = (t, period) => {
        // transform() is not chainable: set the payload, then post.
        form.transform(() => ({ template_id: t.id, period }));
        form.post(route('returns.generate'), { preserveScroll: true });
    };
    const count = (s) => runs.filter((r) => r.status === s).length;

    return (
        <AuthenticatedLayout header="Returns Centre">
            <Head title="Returns Centre" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence' }, { label: 'Returns Centre' }]}
                title="Returns Centre"
                subtitle="Regulator returns populated from your register, with manual overrides, review and approval before submission."
                actions={
                    <Link href={route('regulatory-returns.index')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50">
                        Architecture-compiled returns
                    </Link>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Drafts" value={count('draft')} tone="navy" />
                <KpiCard label="In review" value={count('in_review')} tone="gold" />
                <KpiCard label="Approved, not submitted" value={count('approved')} tone={count('approved') ? 'amber' : 'white'} />
                <KpiCard label="Submitted" value={count('submitted')} tone="green" />
            </div>

            <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">Return templates</h3>
                {templates.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#718096]">No return templates configured.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Regulator</th>
                                    <th className="px-3 py-2">Return</th>
                                    <th className="px-3 py-2">Cadence</th>
                                    <th className="px-3 py-2">Current period</th>
                                    <th className="px-3 py-2">Last submitted</th>
                                    <th className="px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {templates.map((t) => {
                                    const [tone, label] = RUN_STATUS[t.current_status] || [];
                                    return (
                                        <tr key={t.id} className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2 text-xs font-medium text-[#0A1F44]">{t.regulator_code}</td>
                                            <td className="px-3 py-2 text-[#2D3748]">
                                                {t.name}
                                                <span className="block font-mono text-[10px] text-[#718096]">{t.return_code} · v{t.version}</span>
                                            </td>
                                            <td className="px-3 py-2 text-xs capitalize text-[#718096]">{t.cadence}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {t.current_period}
                                                {t.current_run_id ? (
                                                    <span className="ml-2"><StatusBadge status={tone} label={label} /></span>
                                                ) : (
                                                    <span className="ml-2 text-[#B3261E]">not started</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">{t.last_submitted || '—'}</td>
                                            <td className="px-3 py-2 text-right">
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {t.current_run_id ? (
                                                        <Link href={route('returns.show', t.current_run_id)}
                                                            className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50">
                                                            Open {t.current_period}
                                                        </Link>
                                                    ) : (
                                                        canCreate && (
                                                            <button type="button" disabled={form.processing} onClick={() => generate(t, t.current_period)}
                                                                className="rounded bg-[#0A1F44] px-2 py-1 text-xs font-medium text-white disabled:opacity-50">
                                                                Generate {t.current_period}
                                                            </button>
                                                        )
                                                    )}
                                                    {canCreate && (
                                                        periodFor === t.id ? (
                                                            <form onSubmit={(e) => { e.preventDefault(); generate(t, form.data.period); }} className="flex items-center gap-1">
                                                                <input autoFocus aria-label="Period" placeholder={PERIOD_HINT[t.cadence]} value={form.data.period}
                                                                    onChange={(e) => form.setData('period', e.target.value)}
                                                                    className="w-24 rounded border-gray-200 py-1 text-xs" />
                                                                <button className="rounded border border-gray-200 px-2 py-1 text-xs">Go</button>
                                                            </form>
                                                        ) : (
                                                            <button type="button" onClick={() => { setPeriodFor(t.id); form.setData('period', ''); }}
                                                                className="text-xs text-[#718096] underline">
                                                                Other period
                                                            </button>
                                                        )
                                                    )}
                                                </div>
                                                {periodFor === t.id && <InputError message={form.errors.period} className="mt-1" />}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">Runs</h3>
                {runs.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#718096]">No runs generated yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Return</th>
                                    <th className="px-3 py-2">Period</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Prepared / approved</th>
                                    <th className="px-3 py-2">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {runs.map((r) => {
                                    const [tone, label] = RUN_STATUS[r.status] || ['draft', r.status];
                                    return (
                                        <tr key={r.id} className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2">
                                                <Link href={route('returns.show', r.id)} className="text-[#0A1F44] underline">{r.template}</Link>
                                                <span className="block text-[10px] text-[#718096]">{r.regulator_code}</span>
                                            </td>
                                            <td className="px-3 py-2 text-xs">{r.period}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={tone} label={label} />
                                                {r.overrides > 0 && <span className="block text-[10px] text-[#8A6400]">{r.overrides} override{r.overrides === 1 ? '' : 's'}</span>}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">
                                                {r.prepared_by || '—'}
                                                {r.approved_by && <span className="block">✓ {r.approved_by}</span>}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">
                                                {r.submitted_at || '—'}
                                                {r.submission_reference && <span className="block font-mono text-[10px]">{r.submission_reference}</span>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
