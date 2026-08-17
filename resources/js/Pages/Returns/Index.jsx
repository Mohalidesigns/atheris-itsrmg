import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Platform return runs — the template-and-run surface served by
 * PlatformController@returnsIndex.
 *
 * Distinct from the Regulatory Returns module added in ATH-EAR-002 Phase 3
 * (A1), which lives at /regulatory-returns and compiles returns from the
 * architecture graph with entity-level citations. This page remains the
 * generic template runner over `return_templates` / `return_runs`.
 */

const STATE_TONE = {
    draft: 'draft',
    in_review: 'in_review',
    approved: 'approved',
    submitted: 'pass',
};

export default function ReturnsIndex({ templates = [], runs = [] }) {
    const generate = (templateId) =>
        router.post(route('returns.generate'), { template_id: templateId }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header="Return Runs">
            <Head title="Return Runs" />
            <PageHeader
                breadcrumbs={[{ label: 'Platform' }, { label: 'Return Runs' }]}
                title="Regulatory Return Runs"
                subtitle="Template-driven return generation across regulators."
                actions={
                    <Link
                        href={route('regulatory-returns.index')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Architecture-compiled returns
                    </Link>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Templates" value={templates.length} tone="navy" />
                <KpiCard label="Runs" value={runs.length} tone="white" />
                <KpiCard
                    label="Submitted"
                    value={runs.filter((r) => r.status === 'submitted').length}
                    tone="green"
                />
                <KpiCard
                    label="In review"
                    value={runs.filter((r) => r.status === 'in_review').length}
                    tone="gold"
                />
            </div>

            <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                    Return templates
                </h3>
                {templates.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#718096]">No return templates configured.</p>
                ) : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Regulator</th>
                                <th className="px-3 py-2">Return code</th>
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Version</th>
                                <th className="px-3 py-2">Effective from</th>
                                <th className="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {templates.map((t) => (
                                <tr key={t.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-xs font-medium text-[#0A1F44]">
                                        {t.regulator_code}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">{t.return_code}</td>
                                    <td className="px-3 py-2 text-[#2D3748]">{t.name}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{t.version}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{t.effective_from}</td>
                                    <td className="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            onClick={() => generate(t.id)}
                                            className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                        >
                                            Generate run
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                    Recent runs
                </h3>
                {runs.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#718096]">No runs generated yet.</p>
                ) : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Template</th>
                                <th className="px-3 py-2">Period</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Submitted</th>
                                <th className="px-3 py-2">Outputs</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {runs.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {r.template?.name || `Template #${r.template_id}`}
                                        {r.template?.regulator_code && (
                                            <span className="block text-[10px] text-[#718096]">
                                                {r.template.regulator_code}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{r.period}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={STATE_TONE[r.status] || 'draft'}
                                            label={String(r.status || '').replace(/_/g, ' ')}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {r.submitted_at ? String(r.submitted_at).slice(0, 10) : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {[r.pdf_path && 'PDF', r.xlsx_path && 'XLSX', r.csv_path && 'CSV']
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
