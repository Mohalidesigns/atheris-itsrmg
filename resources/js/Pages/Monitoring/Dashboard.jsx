import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowPathIcon } from '@/Components/Material/Symbols';
import { fmtDate, fmtDateTime, ModeTag, Pill, severityTone } from '@/Components/Monitoring/format';

function Panel({ title, href, linkLabel, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div className="mb-3 flex items-start justify-between">
                <h3 className="text-sm font-semibold text-[#2D3748]">{title}</h3>
                {href && <Link href={href} className="text-xs text-[#0A1F44] underline">{linkLabel}</Link>}
            </div>
            {children}
        </div>
    );
}

export default function MonitoringDashboard({ kpis = {}, trend = [], recent = [], drift = [], reviews = [], disagreements = [] }) {
    const org = usePage().props.auth?.organization?.name;
    const maxTrend = Math.max(...trend.map((d) => d.pass + d.warn + d.fail), 1);
    return (
        <AuthenticatedLayout header="Continuous Monitoring">
            <Head title="Monitoring Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Dashboard' }]}
                title="Continuous Monitoring"
                subtitle={`CCM results, configuration drift and access-review progress${org ? ` for ${org}` : ''}.`}
                actions={<Link href={route('ccm.index')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Open CCM Console</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
                <KpiCard label="CCM tests" value={kpis.tests ?? 0} sublabel={`${kpis.runs_24h ?? 0} runs in 24h`} tone="navy" icon={ArrowPathIcon} />
                <KpiCard label="Pass" value={kpis.pass ?? 0} tone="green" />
                <KpiCard label="Warn" value={kpis.warn ?? 0} tone="amber" />
                <KpiCard label="Fail" value={kpis.fail ?? 0} tone="red" />
                <KpiCard label="Open drift" value={kpis.drift_open ?? 0} sublabel={`${kpis.drift_critical ?? 0} high or critical`} tone={kpis.drift_critical ? 'red' : 'white'} />
                <KpiCard label="Reviews overdue" value={kpis.reviews_overdue ?? 0} sublabel={`${kpis.revocations_pending ?? 0} revocations to confirm`} tone={kpis.reviews_overdue ? 'red' : 'white'} />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                <div className="lg:col-span-2">
                    <Panel title="CCM runs, last 14 days">
                        <div className="flex items-end gap-1 h-40">
                            {trend.map((d) => (
                                <div key={d.day} className="flex-1 flex flex-col-reverse h-full gap-[1px]" title={`${d.day}: ${d.pass} pass, ${d.warn} warn, ${d.fail} fail`}>
                                    <div className="bg-[#2D7D46]" style={{ height: `${(d.pass / maxTrend) * 100}%` }} />
                                    <div className="bg-[#E5A100]" style={{ height: `${(d.warn / maxTrend) * 100}%` }} />
                                    <div className="bg-[#B3261E]" style={{ height: `${(d.fail / maxTrend) * 100}%` }} />
                                </div>
                            ))}
                        </div>
                        <div className="flex items-center justify-between text-[10px] text-[#718096] mt-2">
                            <span>{fmtDate(trend[0]?.day)}</span><span>{fmtDate(trend[trend.length - 1]?.day)}</span>
                        </div>
                    </Panel>
                </div>
                <Panel title="Ratings that disagree with CCM" href={route('monitoring.controls', { filter: 'disagree' })} linkLabel="Controls →">
                    {disagreements.length === 0 ? <p className="text-xs text-[#718096]">Every monitored control's rating agrees with its CCM tests.</p> : (
                        <ul className="divide-y divide-gray-100 text-xs">
                            {disagreements.slice(0, 6).map((c) => (
                                <li key={c.id} className="py-2">
                                    <Link href={route('controls.show', c.id)} className="font-mono text-[#0A1F44] hover:underline">{c.control_code}</Link> <span className="text-[#2D3748]">{c.title}</span>
                                    <p className="text-[#C53030]">Rated {c.effectiveness.replace('_', ' ')}, CCM {c.signal}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <Panel title="Latest CCM runs" href={route('ccm.index')} linkLabel="CCM Console →">
                    <ul className="divide-y divide-gray-100 text-xs">
                        {recent.length === 0 && <li className="py-2 text-[#718096]">No runs yet.</li>}
                        {recent.map((r) => (
                            <li key={r.id} className="py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <Link href={route('ccm.show', r.tenant_test_id)} className="font-mono text-[#0A1F44] hover:underline">{r.code}</Link>
                                    <span className="flex items-center gap-1"><ModeTag mode={r.mode} /><Pill value={r.status} /></span>
                                </div>
                                <p className="text-[#4A5568]">{r.summary}</p>
                                <p className="text-[#A0AEC0]">{fmtDateTime(r.ran_at)}</p>
                            </li>
                        ))}
                    </ul>
                </Panel>
                <Panel title="Unresolved drift" href={route('monitoring.drift', { status: 'unresolved' })} linkLabel="Drift register →">
                    <ul className="divide-y divide-gray-100 text-xs">
                        {drift.length === 0 && <li className="py-2 text-[#718096]">No unresolved drift.</li>}
                        {drift.map((d) => (
                            <li key={d.id} className="py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <Link href={route('drift.show', d.id)} className="font-mono text-[#0A1F44] hover:underline">{d.drift_code}</Link>
                                    <Pill value={d.severity} tones={severityTone} />
                                </div>
                                <p className="text-[#2D3748]">{d.title}</p>
                                <p className="text-[#718096]">{d.asset?.name} · {fmtDateTime(d.detected_at)}</p>
                            </li>
                        ))}
                    </ul>
                </Panel>
                <Panel title="Access reviews in progress" href={route('monitoring.access-reviews')} linkLabel="Access reviews →">
                    <ul className="divide-y divide-gray-100 text-xs">
                        {reviews.length === 0 && <li className="py-2 text-[#718096]">No campaigns in progress.</li>}
                        {reviews.map((c) => {
                            const pct = c.items_count ? Math.round((c.decided_count / c.items_count) * 100) : 0;
                            const overdue = c.due_at && c.due_at.slice(0, 10) < new Date().toISOString().slice(0, 10);
                            return (
                                <li key={c.id} className="py-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <Link href={route('access-reviews.show', c.id)} className="text-[#0A1F44] hover:underline">{c.name}</Link>
                                        <span className={overdue ? 'text-[#C53030]' : 'text-[#718096]'}>due {fmtDate(c.due_at)}</span>
                                    </div>
                                    <div className="mt-1 h-1.5 overflow-hidden rounded bg-gray-100"><div className="h-1.5 bg-[#0A1F44]" style={{ width: `${pct}%` }} /></div>
                                    <p className="text-[#718096]">{c.decided_count}/{c.items_count} decided</p>
                                </li>
                            );
                        })}
                    </ul>
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
