import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { BuildingLibraryIcon, CubeIcon, ChartBarIcon, ShieldCheckIcon, CircleStackIcon } from '@heroicons/react/24/outline';

export default function CommandCentre({ kpis = {}, lifecycle = {}, time = {}, radar = {}, eolNext12 = [], assessment = null }) {
    const lcBar = ['plan','build','live','sunset','retired'];
    const maxLc = Math.max(...lcBar.map((k) => Number(lifecycle[k] || 0)), 1);
    const radarKeys = ['adopt','trial','assess','hold'];
    const maxR = Math.max(...radarKeys.map((k) => Number(radar[k] || 0)), 1);
    return (
        <AuthenticatedLayout header="EA Command Centre">
            <Head title="EA Command Centre" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Command Centre' }]}
                title="EA Command Centre"
                subtitle="Current-state enterprise view: capability map, application portfolio, technology radar, data/privacy posture, CBN EA maturity."
                actions={<Link href={route('ea.cbn-maturity')} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">CBN Maturity</Link>}
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.command-centre')} current="ea.command-centre" />
            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
                <KpiCard label="Capabilities" value={kpis.capabilities || 0} tone="navy" icon={BuildingLibraryIcon} />
                <KpiCard label="Applications" value={kpis.applications || 0} tone="gold" icon={CubeIcon} />
                <KpiCard label="Tech components" value={kpis.tech_components || 0} tone="white" />
                <KpiCard label="Obsolete tech" value={kpis.obsolete_tech || 0} tone="red" />
                <KpiCard label="Info domains" value={kpis.info_domains || 0} tone="white" icon={CircleStackIcon} />
                <KpiCard label="Logical entities" value={kpis.logical_entities || 0} tone="white" />
                <KpiCard label="PII entities" value={kpis.pii_entities || 0} tone="amber" icon={ShieldCheckIcon} />
                <KpiCard label="Value streams" value={kpis.value_streams || 0} tone="white" icon={ChartBarIcon} />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Application lifecycle</h3>
                    <ul className="space-y-2 text-xs">
                        {lcBar.map((k) => (
                            <li key={k}>
                                <div className="flex justify-between"><span className="capitalize text-[#2D3748]">{k}</span><span className="font-semibold text-[#0A1F44]">{lifecycle[k] || 0}</span></div>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-1">
                                    <div className="h-2 rounded bg-[#0A1F44]" style={{ width: `${((lifecycle[k] || 0) / maxLc) * 100}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">TIME scoring</h3>
                    <div className="grid grid-cols-2 gap-3 text-xs">
                        {['Tolerate','Invest','Migrate','Eliminate'].map((k, i) => (
                            <div key={k} className="border border-gray-100 rounded-lg p-3">
                                <p className="text-[#718096] uppercase">{k}</p>
                                <p className="text-2xl font-bold text-[#0A1F44] mt-1">{time[k] || 0}</p>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Technology radar</h3>
                    <ul className="space-y-2 text-xs">
                        {radarKeys.map((k) => (
                            <li key={k}>
                                <div className="flex justify-between"><span className="capitalize text-[#2D3748]">{k}</span><span className="font-semibold text-[#0A1F44]">{radar[k] || 0}</span></div>
                                <div className="h-2 bg-gray-100 rounded overflow-hidden mt-1">
                                    <div className="h-2 rounded" style={{ width: `${((radar[k] || 0) / maxR) * 100}%`, background: k === 'adopt' ? '#2D7D46' : k === 'trial' ? '#1D4ED8' : k === 'assess' ? '#E5A100' : '#B3261E' }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">EOL / EOS within 12 months</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Component</th>
                                <th className="px-3 py-2">Vendor</th>
                                <th className="px-3 py-2">EOL</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {eolNext12.map((t) => (
                                <tr key={t.id}>
                                    <td className="px-3 py-2 text-[#2D3748]">{t.name}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{t.vendor}</td>
                                    <td className="px-3 py-2 text-xs text-[#B3261E]">{t.eol_date}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Latest CBN EA maturity assessment</h3>
                    {assessment ? (
                        <div className="mt-3 space-y-2 text-sm">
                            <p className="text-[#2D3748]">{assessment.title}</p>
                            <p className="text-xs text-[#718096]">Year {assessment.year} · Status: <StatusBadge status={assessment.status === 'completed' ? 'pass' : 'in_progress'} label={assessment.status} /></p>
                            <p className="text-4xl font-bold text-[#0A1F44] mt-2">{Number(assessment.overall_score ?? 0).toFixed(2)}<span className="text-base font-normal text-[#718096]"> / 5.00</span></p>
                            <p className="text-xs text-[#718096]">Target ≥ 3.0 · pilot-bank exit criterion</p>
                        </div>
                    ) : <p className="text-xs text-[#718096] mt-3">No assessment yet.</p>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
