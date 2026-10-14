import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { fmtDate, Pill } from '@/Components/Monitoring/format';

const effTone = (e) => ({ effective: 'pass', partially_effective: 'warn', ineffective: 'fail', not_assessed: 'draft' })[e] || 'draft';
const disagrees = (c) => (c.effectiveness === 'effective' && c.signal === 'fail') || (c.effectiveness === 'ineffective' && c.signal === 'pass');

export default function MonitoringControls({ controls = [], buckets = {}, filter = null }) {
    const go = (f) => router.get(route('monitoring.controls'), f ? { filter: f } : {}, { preserveState: true, replace: true });
    return (
        <AuthenticatedLayout header="Controls Monitoring">
            <Head title="Controls Monitoring" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Controls Monitoring' }]}
                title="Controls Monitoring"
                subtitle="Each control's assessed effectiveness beside the signal from the CCM tests linked to it. A control rated effective whose test is failing, or rated ineffective while its tests pass, needs its rating revisited."
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <KpiCard label="Effective" value={buckets.effective || 0} tone="green" />
                <KpiCard label="Partially effective" value={buckets.partially_effective || 0} tone="amber" />
                <KpiCard label="Ineffective" value={buckets.ineffective || 0} tone="red" />
                <KpiCard label="Not assessed" value={buckets.not_assessed || 0} tone="white" />
                <KpiCard label="Monitored by CCM" value={buckets.monitored || 0} tone="navy" />
                <KpiCard label="Rating ≠ CCM" value={buckets.disagree || 0} tone={buckets.disagree ? 'red' : 'white'} />
            </div>
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {[['All controls', null], ['Monitored by CCM', 'monitored'], ['CCM failing', 'failing'], ['Rating disagrees with CCM', 'disagree']].map(([label, f]) => (
                    <button key={label} onClick={() => go(f)} className={`rounded-full border px-3 py-1 ${filter === f ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Control</th>
                            <th className="px-3 py-2">Domain</th>
                            <th className="px-3 py-2">Assessed</th>
                            <th className="px-3 py-2">CCM signal</th>
                            <th className="px-3 py-2">Last tested</th>
                            <th className="px-3 py-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {controls.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-xs text-[#718096]">No controls match.</td></tr>}
                        {controls.map((c) => (
                            <tr key={c.id} className={disagrees(c) ? 'bg-[#FEF2F2]' : ''}>
                                <td className="px-3 py-2">
                                    <Link href={route('controls.show', c.id)} className="font-mono text-xs text-[#0A1F44] hover:underline">{c.control_code}</Link>
                                    {c.is_key_control && <span className="ml-1 rounded bg-[#C9A86A]/20 px-1 text-[10px] text-[#7A5C1E]">key</span>}
                                    <p className="text-[#2D3748]">{c.title}</p>
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.domain}<p className="capitalize">{c.type} · {c.frequency}</p></td>
                                <td className="px-3 py-2"><StatusBadge status={effTone(c.effectiveness)} label={String(c.effectiveness || 'not assessed').replace('_', ' ')} /></td>
                                <td className="px-3 py-2 text-xs">
                                    {c.signal ? <><Pill value={c.signal} /> <span className="text-[#718096]">{c.ccm_tests_count} test{c.ccm_tests_count === 1 ? '' : 's'}</span></> : <span className="text-[#A0AEC0]">no CCM test</span>}
                                    {disagrees(c) && <p className="mt-0.5 text-[11px] text-[#C53030]">Rating and monitoring disagree</p>}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{fmtDate(c.last_tested)}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{c.owner?.name || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
