import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import ImpactTab from '@/Components/Ea/ImpactTab';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const TABS = ['overview', 'capabilities', 'technology', 'interfaces', 'zone', 'controls', 'threats', 'impact'];

export default function ApplicationShow({ application, capabilities = [], tech = [], interfaces = [], zone = null, controls = [], threats = [], impact = null }) {
    const [tab, setTab] = useState('overview');
    return (
        <AuthenticatedLayout header={application.name}>
            <Head title={application.name} />
            <PageHeader
                breadcrumbs={[
                    { label: 'EA' },
                    { label: 'Application Portfolio', href: route('ea.applications') },
                    { label: application.code },
                ]}
                title={`${application.code} — ${application.name}`}
                subtitle={`Lifecycle: ${application.lifecycle} · Criticality: ${application.criticality}`}
                actions={<Link href={route('ea.blast-radius') + '?app_id=' + application.id} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Blast radius →</Link>}
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <KpiCard label="TIME" value={application.time_score || '—'} tone="navy" />
                <KpiCard label="6R" value={application.six_r_score || '—'} tone="gold" />
                <KpiCard label="BFTF" value={`${application.business_fit || 0}×${application.technical_fit || 0}`} tone="white" />
                <KpiCard label="Annual cost" value={application.annual_cost_ngn ? '₦' + Number(application.annual_cost_ngn).toLocaleString() : '—'} tone="white" />
                <KpiCard label="TCO/yr" value={application.tco_annual_ngn ? '₦' + Number(application.tco_annual_ngn).toLocaleString() : '—'} tone="white" />
                <KpiCard label="Users" value={application.user_count || 0} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-2 flex gap-1 overflow-x-auto">
                    {TABS.map((t) => (
                        <button key={t} onClick={() => setTab(t)}
                            className={`px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap ${tab === t ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>
                            {t.charAt(0).toUpperCase() + t.slice(1)}
                        </button>
                    ))}
                </div>
                <div className="p-5 text-sm">
                    {tab === 'overview' && (
                        <div className="space-y-3">
                            <p className="text-[#2D3748]">{application.description || '—'}</p>
                            <p className="text-xs text-[#718096]">Owner role: {application.owner_role || '—'} · Plateau: {application.plateau_id || '—'} · Version: v{application.version_no || 1}</p>
                        </div>
                    )}
                    {tab === 'capabilities' && (
                        <ul className="divide-y divide-gray-100">
                            {capabilities.length === 0 && <li className="py-3 text-xs text-[#718096]">No capabilities linked.</li>}
                            {capabilities.map((c) => (
                                <li key={c.id} className="py-2 flex items-center justify-between">
                                    <Link href={route('ea.capabilities.show', c.id)} className="text-[#0A1F44] hover:underline">{c.code} — {c.name}</Link>
                                    <span className="text-xs text-[#718096]">L{c.level}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {tab === 'technology' && (
                        <ul className="divide-y divide-gray-100">
                            {tech.length === 0 && <li className="py-3 text-xs text-[#718096]">No technology components linked.</li>}
                            {tech.map((t) => (
                                <li key={t.id} className="py-2 flex items-center justify-between">
                                    <span className="text-[#2D3748]">{t.name} <span className="text-xs text-gray-500">{t.version}</span></span>
                                    <span className="flex items-center gap-2 text-xs">
                                        {t.eol_date && <span className="text-amber-700">EOL {t.eol_date}</span>}
                                        <StatusBadge status={t.radar_status === 'adopt' ? 'compliant' : t.radar_status === 'hold' ? 'critical' : 'moderate'} label={t.radar_status} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {tab === 'interfaces' && (
                        <ul className="divide-y divide-gray-100">
                            {interfaces.length === 0 && <li className="py-3 text-xs text-[#718096]">No interfaces.</li>}
                            {interfaces.map((i) => (
                                <li key={i.id} className="py-2 flex items-center justify-between">
                                    <div>
                                        <p className="text-[#2D3748]">{i.name}</p>
                                        <p className="text-xs text-[#718096]">{i.protocol} · {i.pattern}</p>
                                    </div>
                                    <StatusBadge status={i.pii_carrying ? 'critical' : 'moderate'} label={i.pii_carrying ? 'PII' : i.classification} />
                                </li>
                            ))}
                        </ul>
                    )}
                    {tab === 'zone' && (
                        zone ? (
                            <div>
                                <h4 className="text-sm font-semibold text-[#0A1F44]">{zone.zone?.name}</h4>
                                <p className="text-xs text-[#718096] mt-1">Trust level: {zone.zone?.trust_level} · Assignment: {zone.assignment_type}</p>
                            </div>
                        ) : <p className="text-xs text-[#718096]">No security zone assigned yet.</p>
                    )}
                    {tab === 'controls' && (
                        <div>
                            <p className="text-xs text-gray-500 mb-2">Resolved via the ControlInheritanceService — direct + zone + tech inheritance.</p>
                            <table className="w-full text-xs">
                                <thead className="text-[10px] text-gray-500 border-b border-gray-100">
                                    <tr><th className="text-left py-2">Control ID</th><th className="text-left">Framework</th><th className="text-left">Coverage</th><th className="text-left">Source</th><th className="text-left">Notes</th></tr>
                                </thead>
                                <tbody>
                                    {controls.map((c, i) => (
                                        <tr key={i} className="border-b border-gray-50">
                                            <td className="py-1.5">{c.control_id}</td>
                                            <td>{c.framework}</td>
                                            <td><StatusBadge status={c.coverage === 'full' ? 'compliant' : c.coverage === 'gap' ? 'critical' : 'in_progress'} label={c.coverage} /></td>
                                            <td className="text-xs">{c.source_label}</td>
                                            <td className="text-xs text-gray-500">{c.notes}</td>
                                        </tr>
                                    ))}
                                    {controls.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-gray-400">No controls mapped.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {tab === 'threats' && (
                        <div>
                            {threats.length === 0 && <p className="text-xs text-gray-500">No threat models on this application yet.</p>}
                            {threats.map((t) => (
                                <div key={t.id} className="border-b border-gray-100 py-3">
                                    <div className="flex items-center gap-2"><span className="font-mono text-xs">{t.code}</span><span className="font-medium">{t.name}</span><StatusBadge status="moderate" label={t.methodology} /></div>
                                    <div className="text-xs text-gray-500">Techniques: {t.techniques?.length || 0}</div>
                                </div>
                            ))}
                        </div>
                    )}
                    {/* WS 4.2 (B14) — the change-impact tab. Replaces the
                        fixed 2-hop application-only snapshot with a live
                        traversal over every edge type, with depth and direction
                        controls, loaded on demand. */}
                    {tab === 'impact' && (
                        <ImpactTab
                            entityType="EaApplication"
                            entityId={application.id}
                            title={`Change impact — ${application.name}`}
                        />
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
