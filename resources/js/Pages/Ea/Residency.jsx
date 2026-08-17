import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link } from '@inertiajs/react';

/**
 * Data Residency & Localisation Control Tower — A2.
 *
 * ATH-EAR-002 §11 wedge play 2: "Between now and 1 January 2027, 'which systems
 * hold Nigerian payment data and where do they and their DR sites physically
 * run' is a board-level question with a legal deadline. It is an EA query and
 * nothing else in the market answers it."
 *
 * §6.3 A2: "LeanIX and Ardoq model 'region' as a tag. Neither models a *legal*
 * residency obligation with a deadline, a basis and an approval reference."
 */

const SEVERITY_TONE = { breach: 'fail', critical: 'critical', high: 'high', medium: 'moderate' };

const FLOW_STATUS = {
    prohibited: { tone: 'fail', label: 'Prohibited' },
    no_basis: { tone: 'critical', label: 'No basis' },
    awaiting_approval: { tone: 'warn', label: 'Awaiting NDPC approval' },
    documented: { tone: 'pass', label: 'Documented' },
};

const GAP_CSV = [
    { key: 'label', label: 'System' },
    { key: 'legal_entity', label: 'Legal entity' },
    { key: 'criticality', label: 'Criticality' },
    { key: 'hosting_country', label: 'Primary country' },
    { key: 'hosting_site', label: 'Primary site' },
    { key: 'dr_country', label: 'DR country' },
    { key: 'dr_site', label: 'DR site' },
    { key: 'reason', label: 'Breach reason' },
    { key: 'severity', label: 'Severity' },
    { key: 'owner', label: 'Owner' },
    { key: 'remediation_initiative', label: 'Remediation initiative' },
];

export default function Residency({ summary = {}, gapRegister = [], crossBorderFlows = [], readiness = {} }) {
    const perms = useEaPermissions();

    const f = useEaFilter(gapRegister, {
        searchKeys: ['label', 'legal_entity', 'owner', 'hosting_site', 'dr_site'],
        filters: { severity: {}, criticality: {} },
    });

    const days = summary.days_to_deadline ?? 0;
    const drOnly = gapRegister.filter((g) => g.hosting_country === 'NG');

    return (
        <AuthenticatedLayout header="Data Residency">
            <Head title="Data Residency & Localisation" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Data & Privacy Architecture' },
                    { label: 'Residency' },
                ]}
                title="Data Residency & Localisation Control Tower"
                subtitle="Which systems hold Nigerian payment transaction data, and where they and their DR sites physically run."
                actions={
                    <Link
                        href={route('regulatory-returns.index')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Compile the gap report
                    </Link>
                }
            />

            <div
                className={`mb-5 rounded-xl border p-4 ${
                    days < 0
                        ? 'border-[#B3261E]/40 bg-[#B3261E]/5'
                        : days <= 180
                          ? 'border-[#E5A100]/40 bg-[#E5A100]/5'
                          : 'border-gray-200 bg-white'
                }`}
            >
                <p className="text-sm font-semibold text-[#0A1F44]">
                    {days < 0
                        ? `The localisation deadline passed ${Math.abs(days)} days ago.`
                        : `${days} days to the payment data localisation deadline (${summary.deadline}).`}
                </p>
                <p className="mt-1 text-xs text-[#2D3748]">
                    The circular of 15 June 2026 requires domestically generated Nigerian payment transaction data
                    to be stored and managed within Nigeria. The requirement covers{' '}
                    <span className="font-medium">primary and disaster recovery infrastructure</span>, and prohibits
                    cross-border transfer of that data outright.
                </p>
            </div>

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Systems holding payment data" value={summary.payment_data_systems || 0} tone="navy" />
                <KpiCard
                    label="Localisation gaps"
                    value={summary.localisation_gaps || 0}
                    tone={summary.localisation_gaps ? 'red' : 'green'}
                />
                <KpiCard
                    label="Critical or high"
                    value={summary.critical_gaps || 0}
                    tone={summary.critical_gaps ? 'red' : 'white'}
                />
                <KpiCard
                    label="Prohibited flows"
                    value={summary.prohibited_flows || 0}
                    tone={summary.prohibited_flows ? 'red' : 'white'}
                    sublabel="Payment data crossing a border"
                />
                <KpiCard
                    label="Unknown residency"
                    value={summary.unknown_residency || 0}
                    tone={summary.unknown_residency ? 'amber' : 'white'}
                    sublabel="Cannot be assessed"
                />
            </div>

            {drOnly.length > 0 && (
                <div className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <p className="text-sm font-semibold text-[#8A6400]">
                        {drOnly.length} system(s) run a compliant primary in Nigeria but replicate offshore
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        This is the half of the requirement most often missed. A repatriated primary with an
                        offshore DR site is still non-compliant, and the teams responsible usually believe they
                        have finished.
                    </p>
                </div>
            )}

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search system, entity or owner…' }}
                filters={[
                    {
                        key: 'severity',
                        label: 'All severities',
                        value: f.active.severity,
                        onChange: (v) => f.setFilter('severity', v),
                        options: ['breach', 'critical', 'high', 'medium'],
                    },
                    {
                        key: 'criticality',
                        label: 'All criticalities',
                        value: f.active.criticality,
                        onChange: (v) => f.setFilter('criticality', v),
                        options: ['critical', 'high', 'medium', 'low'],
                    },
                ]}
                onExport={() => exportCsv('localisation-gap-register.csv', GAP_CSV, f.filtered)}
                canExport={perms.canExport}
                total={gapRegister.length}
                shown={f.filtered.length}
            />

            <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                    Localisation gap register
                </h3>
                {f.filtered.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#2D7D46]">
                        No system holding Nigerian payment transaction data sits outside Nigeria.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">System</th>
                                    <th className="px-3 py-2">Entity</th>
                                    <th className="px-3 py-2">Primary</th>
                                    <th className="px-3 py-2">DR</th>
                                    <th className="px-3 py-2">Breach</th>
                                    <th className="px-3 py-2">Severity</th>
                                    <th className="px-3 py-2">Owner</th>
                                    <th className="px-3 py-2">Remediation</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {f.filtered.map((g, i) => (
                                    <tr key={`${g.application_id}-${i}`} className="hover:bg-gray-50/60">
                                        <td className="px-3 py-2 text-[#2D3748]">{g.label}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{g.legal_entity || '—'}</td>
                                        <td className="px-3 py-2 text-xs">
                                            <span className={g.hosting_country === 'NG' ? 'text-[#2D7D46]' : 'text-[#B3261E]'}>
                                                {g.hosting_country || '?'}
                                            </span>
                                            <span className="block text-[10px] text-[#718096]">{g.hosting_site || '—'}</span>
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            <span className={g.dr_country === 'NG' ? 'text-[#2D7D46]' : 'text-[#B3261E]'}>
                                                {g.dr_country || '?'}
                                            </span>
                                            <span className="block text-[10px] text-[#718096]">{g.dr_site || '—'}</span>
                                        </td>
                                        <td className="max-w-xs px-3 py-2 text-xs text-[#2D3748]">{g.reason}</td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={SEVERITY_TONE[g.severity] || 'moderate'} label={g.severity} />
                                        </td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{g.owner || 'Unowned'}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {g.remediation_initiative || (
                                                <span className="text-[#B3261E]">None in flight</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Cross-border transfer register
                    </h3>
                    {crossBorderFlows.length === 0 ? (
                        <p className="px-4 py-6 text-center text-xs text-[#718096]">No cross-border flows recorded.</p>
                    ) : (
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Flow</th>
                                    <th className="px-3 py-2">Route</th>
                                    <th className="px-3 py-2">Basis</th>
                                    <th className="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {crossBorderFlows.map((flow) => (
                                    <tr key={flow.id}>
                                        <td className="px-3 py-2 text-[#2D3748]">{flow.name}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {flow.source_country || '?'} → {flow.destination_country || '?'}
                                        </td>
                                        <td className="max-w-xs px-3 py-2 text-[11px] text-[#718096]">
                                            {flow.transfer_basis_label}
                                            {flow.approval_reference && (
                                                <span className="block font-mono text-[10px]">
                                                    {flow.approval_reference}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <StatusBadge
                                                status={FLOW_STATUS[flow.status]?.tone || 'draft'}
                                                label={FLOW_STATUS[flow.status]?.label || flow.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Sovereign hosting readiness</h3>
                        <p className="mt-0.5 text-xs text-[#718096]">
                            {readiness.workloads_to_relocate || 0} workload(s) to relocate ·{' '}
                            {readiness.tier_iii_plus_capacity || 0} in-country site(s) at TIA-942 Tier III or above
                        </p>
                    </div>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Site</th>
                                <th className="px-3 py-2">City</th>
                                <th className="px-3 py-2">Tier</th>
                                <th className="px-3 py-2">Autonomy</th>
                                <th className="px-3 py-2">Load</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {(readiness.in_country_sites || []).slice(0, 12).map((s) => (
                                <tr key={s.site_id}>
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {s.name}
                                        <span className="block text-[10px] text-[#718096]">{s.operator}</span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{s.city}</td>
                                    <td className="px-3 py-2 text-xs">{s.tier ? `Tier ${s.tier}` : '—'}</td>
                                    <td className="px-3 py-2 text-xs">{s.autonomy_hours}h</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{s.current_load}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
