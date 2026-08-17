import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head } from '@inertiajs/react';

/**
 * Power & Site Resilience — A5.
 *
 * ATH-EAR-002 §6.3: "Nigeria has 28 data centres, 21 of them in Lagos — severe
 * geographic concentration for DR purposes. Power is an under-acknowledged
 * cause of payment failure and was **entirely absent from stakeholder
 * checklists** for achieving PSV 2028 targets. The ITSB adopts TIA-942 for data
 * centre tiering. **A DR architecture that ignores diesel is fiction in this
 * market.**"
 */

const BAND_TONE = { strong: 'pass', adequate: 'in_progress', weak: 'warn', inadequate: 'fail' };
const SEV_TONE = { critical: 'critical', high: 'high', medium: 'moderate' };

const SITE_CSV = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Site' },
    { key: 'operator', label: 'Operator' },
    { key: 'city', label: 'City' },
    { key: 'country', label: 'Country' },
    { key: 'tia942_tier', label: 'TIA-942 tier' },
    { key: 'generator_autonomy_hours', label: 'Generator hours' },
    { key: 'autonomy_hours', label: 'Total autonomy (h)' },
    { key: 'grid_reliability_band', label: 'Grid reliability' },
    { key: 'flood_risk', label: 'Flood risk' },
    { key: 'resilience_score', label: 'Resilience score' },
];

export default function Sites({
    overview = {},
    sites = [],
    alerts = [],
    postureAgainstBia = [],
    singleSiteDependencies = [],
}) {
    const perms = useEaPermissions();

    const f = useEaFilter(sites, {
        searchKeys: ['code', 'name', 'operator', 'city'],
        filters: {
            country: {},
            resilience_band: {},
        },
    });

    const shortfalls = postureAgainstBia.filter((p) => !p.meets_rto);

    return (
        <AuthenticatedLayout header="Sites & Resilience">
            <Head title="Sites & Power Resilience" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Portfolio' }, { label: 'Sites' }]}
                title="Sites & Power Resilience"
                subtitle="Where the estate physically runs, how long it survives a grid outage, and whether that meets the recovery objectives the business signed off."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Sites" value={overview.total_sites || 0} tone="navy" sublabel={`${overview.nigerian_sites || 0} in Nigeria`} />
                <KpiCard
                    label="Lagos concentration"
                    value={`${overview.lagos_share || 0}%`}
                    tone={overview.lagos_share > 60 ? 'red' : overview.lagos_share > 40 ? 'amber' : 'green'}
                />
                <KpiCard label="Tier III or above" value={overview.tier_iii_plus || 0} tone="white" />
                <KpiCard
                    label="Median generator autonomy"
                    value={`${overview.median_generator_hours || 0}h`}
                    tone="gold"
                />
                <KpiCard
                    label="RTO shortfalls"
                    value={shortfalls.length}
                    tone={shortfalls.length ? 'red' : 'green'}
                    sublabel="Site cannot outlast the RTO"
                />
            </div>

            {overview.lagos_share > 50 && (
                <p className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4 text-xs text-[#2D3748]">
                    <span className="font-semibold text-[#8A6400]">
                        {overview.lagos_share}% of recorded sites are in Lagos.
                    </span>{' '}
                    A DR pair inside one metropolitan area shares its grid, its flood exposure and its civil-unrest
                    risk. Repatriating workloads to satisfy the localisation directive can make this worse — both
                    facts belong in front of the board together.
                </p>
            )}

            {alerts.length > 0 && (
                <div className="mb-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Geographic concentration alerts ({alerts.length})
                    </h3>
                    <ul className="max-h-80 divide-y divide-gray-100 overflow-y-auto">
                        {alerts.slice(0, 40).map((a, i) => (
                            <li key={i} className="flex items-start justify-between gap-3 px-4 py-2.5">
                                <div className="min-w-0">
                                    <p className="text-sm text-[#2D3748]">{a.label}</p>
                                    <p className="text-[11px] text-[#718096]">{a.message}</p>
                                </div>
                                <StatusBadge status={SEV_TONE[a.severity] || 'moderate'} label={a.severity} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {shortfalls.length > 0 && (
                <div className="mb-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Resilience posture against the BIA</h3>
                        <p className="mt-0.5 text-xs text-[#718096]">
                            Recovery objectives come from the BIA — what the business actually signed off — compared
                            with how long the supporting site survives without grid power.
                        </p>
                    </div>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Process</th>
                                <th className="px-3 py-2">Application</th>
                                <th className="px-3 py-2">Site</th>
                                <th className="px-3 py-2">RTO</th>
                                <th className="px-3 py-2">Autonomy</th>
                                <th className="px-3 py-2">Shortfall</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {shortfalls.slice(0, 20).map((p, i) => (
                                <tr key={i}>
                                    <td className="px-3 py-2 text-xs text-[#2D3748]">{p.process}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{p.application}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{p.site || 'Not recorded'}</td>
                                    <td className="px-3 py-2 text-xs">{p.rto_hours}h</td>
                                    <td className="px-3 py-2 text-xs">{p.autonomy_hours}h</td>
                                    <td className="px-3 py-2 text-xs font-medium text-[#B3261E]">
                                        {p.shortfall_hours}h
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search site, operator or city…' }}
                filters={[
                    {
                        key: 'country',
                        label: 'All countries',
                        value: f.active.country,
                        onChange: (v) => f.setFilter('country', v),
                        options: [...new Set(sites.map((s) => s.country))].filter(Boolean),
                    },
                    {
                        key: 'resilience_band',
                        label: 'All bands',
                        value: f.active.resilience_band,
                        onChange: (v) => f.setFilter('resilience_band', v),
                        options: ['strong', 'adequate', 'weak', 'inadequate'],
                    },
                ]}
                onExport={() => exportCsv('ea-sites.csv', SITE_CSV, f.filtered)}
                canExport={perms.canExport}
                total={sites.length}
                shown={f.filtered.length}
            />

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Site</th>
                                <th className="px-3 py-2">City</th>
                                <th className="px-3 py-2">Tier</th>
                                <th className="px-3 py-2">Generator</th>
                                <th className="px-3 py-2">Grid</th>
                                <th className="px-3 py-2">Flood</th>
                                <th className="px-3 py-2">Connectivity</th>
                                <th className="px-3 py-2">Resilience</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((s) => (
                                <tr key={s.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2">
                                        <span className="text-[#2D3748]">{s.name}</span>
                                        <span className="block text-[10px] text-[#718096]">{s.operator}</span>
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {s.city}
                                        <span className="ml-1 text-[10px] text-[#718096]">{s.country}</span>
                                    </td>
                                    <td className="px-3 py-2 text-xs">{s.tia942_tier ? `Tier ${s.tia942_tier}` : '—'}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {s.generator_autonomy_hours ? `${s.generator_autonomy_hours}h` : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs capitalize text-[#718096]">
                                        {s.grid_reliability_band || '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs capitalize">
                                        <span className={s.flood_risk === 'high' ? 'text-[#B3261E]' : 'text-[#718096]'}>
                                            {s.flood_risk || '—'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {(s.connectivity || []).length || 0}
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={BAND_TONE[s.resilience_band] || 'draft'}
                                            label={`${s.resilience_score}`}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {singleSiteDependencies.length > 0 && (
                <div className="mt-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Single-site dependency register
                    </h3>
                    <ul className="divide-y divide-gray-100">
                        {singleSiteDependencies.map((d) => (
                            <li key={d.site_id} className="px-4 py-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm text-[#2D3748]">
                                        {d.site}
                                        <span className="ml-2 text-xs text-[#718096]">
                                            {d.city} · Tier {d.tier || '?'} · {d.autonomy_hours}h autonomy
                                        </span>
                                    </p>
                                    <StatusBadge
                                        status={BAND_TONE[d.resilience_band] || 'draft'}
                                        label={`${d.exposed_count} exposed`}
                                    />
                                </div>
                                <p className="mt-1 text-[11px] text-[#718096]">{d.exposed.join(' · ')}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
