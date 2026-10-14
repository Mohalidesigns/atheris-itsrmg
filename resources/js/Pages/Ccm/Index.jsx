import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, router } from '@inertiajs/react';
import { PlayIcon } from '@heroicons/react/24/outline';
import { fmtDateTime, ModeTag, Pill } from '@/Components/Monitoring/format';

export default function CcmIndex({ tests = [], summary = {}, filters = {}, canRun = false }) {
    const run = (id) => router.post(route('ccm.run', id), {}, { preserveScroll: true });
    const filter = (f) => router.get(route('ccm.index'), f, { preserveState: true, replace: true });
    const sandbox = tests.filter((t) => t.mode === 'sandbox').length;
    return (
        <AuthenticatedLayout header="Continuous Controls Monitoring">
            <Head title="CCM Console" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'CCM Console' }]}
                title="Continuous Controls Monitoring (CCM)"
                subtitle="Automated tests against CBN RBCSF, NDPA and PCI DSS. Each run measures the check and records the value against its threshold; failures raise an issue."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Tests enabled" value={summary.tests ?? 0} tone="navy" />
                <KpiCard label="Pass" value={summary.pass ?? 0} tone="green" />
                <KpiCard label="Warn" value={summary.warn ?? 0} tone="amber" />
                <KpiCard label="Fail" value={summary.fail ?? 0} tone="red" />
                <KpiCard label="Not linked to a control" value={summary.unlinked ?? 0} tone="white" />
            </div>
            {sandbox > 0 && (
                <p className="mb-3 rounded-lg border border-[#C9A86A]/40 bg-[#C9A86A]/10 px-3 py-2 text-xs text-[#7A5C1E]">
                    {sandbox} of {tests.length} tests need a tool this bank has not connected (AD, AWS, Defender, Tenable, SIEM, DLP…) and run in sandbox mode against a deterministic catalogue. The rest are measured from the bank's own records in Atheris.
                </p>
            )}
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {[['All', {}], ['Failing', { status: 'fail' }], ['Warning', { status: 'warn' }], ['Passing', { status: 'pass' }], ['Not linked to a control', { unlinked: 1 }]].map(([label, f]) => {
                    const active = JSON.stringify(f) === JSON.stringify(Object.fromEntries(Object.entries(filters).filter(([, v]) => v)));
                    return <button key={label} onClick={() => filter(f)} className={`rounded-full border px-3 py-1 ${active ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>;
                })}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Test</th>
                            <th className="px-3 py-2">Control</th>
                            <th className="px-3 py-2">Last result</th>
                            <th className="px-3 py-2">Last run</th>
                            <th className="px-3 py-2">Issue</th>
                            <th className="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tests.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-xs text-[#718096]">No tests match.</td></tr>}
                        {tests.map((t) => (
                            <tr key={t.id} className={t.status !== 'enabled' ? 'opacity-60' : ''}>
                                <td className="px-3 py-2">
                                    <Link href={route('ccm.show', t.id)} className="font-mono text-xs text-[#0A1F44] hover:underline">{t.code}</Link>
                                    <p className="text-[#2D3748]">{t.title}</p>
                                    <div className="mt-0.5 flex items-center gap-1"><ModeTag mode={t.mode} />{t.status !== 'enabled' && <Pill value={t.status} />}</div>
                                </td>
                                <td className="px-3 py-2 text-xs">{t.control ? <Link href={route('controls.show', t.control.id)} className="text-[#0A1F44] hover:underline">{t.control.control_code}</Link> : <span className="text-[#A0AEC0]">not linked</span>}</td>
                                <td className="px-3 py-2"><Pill value={t.last_status} /><p className="text-xs text-[#718096]">{t.last_value}</p></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{fmtDateTime(t.last_run_at)}<p>next {fmtDateTime(t.next_run_at)}</p></td>
                                <td className="px-3 py-2 text-xs">{t.issue ? <Link href={route('issues.show', t.issue.id)} className="text-[#0A1F44] hover:underline">{t.issue.issue_code}</Link> : '—'}</td>
                                <td className="px-3 py-2">
                                    {canRun && t.status !== 'disabled' && (
                                        <button onClick={() => run(t.id)} className="inline-flex items-center gap-1 text-xs text-[#0A1F44] hover:underline">
                                            <PlayIcon className="w-3 h-3" /> Run now
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
