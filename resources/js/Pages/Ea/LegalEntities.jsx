import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

/**
 * Legal entities and the group roll-up — A6.
 *
 * ATH-EAR-002 §6.3: "A global tool models 'regions'. It does not model *'this
 * subsidiary's core banking instance must simultaneously satisfy CBN
 * localisation, the Ghanaian directive and UK operational resilience.'* That is
 * genuinely hard, it is table stakes for GTCO/Access/UBA, and it is worth
 * building properly."
 *
 * The ITSB category shown here is what sets each entity's architecture maturity
 * target: Level 3 for Category One, Level 2 for Category Two.
 */

const ngn = (v) => (v ? '₦' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }) : '—');

export default function LegalEntities({ entities = [], summary = {} }) {
    return (
        <AuthenticatedLayout header="Legal Entities">
            <Head title="Legal Entities & Jurisdictions" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Legal Entities' }]}
                title="Legal Entities & Jurisdictions"
                subtitle="One group, several regulators. Each entity carries its own licence class, maturity target and residency obligations."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Legal entities" value={summary.entities || 0} tone="navy" />
                <KpiCard label="Jurisdictions" value={summary.jurisdictions || 0} tone="gold" />
                <KpiCard
                    label="ITSB Category One"
                    value={summary.category_one || 0}
                    tone="white"
                    sublabel="Target maturity Level 3"
                />
                <KpiCard
                    label="ITSB Category Two"
                    value={summary.category_two || 0}
                    tone="white"
                    sublabel="Target maturity Level 2"
                />
            </div>

            {summary.regulators?.length > 0 && (
                <div className="mb-5 rounded-xl border border-gray-200 bg-white p-4">
                    <p className="text-xs uppercase tracking-wide text-[#718096]">Regulators across the group</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {summary.regulators.map((r) => (
                            <span
                                key={r}
                                className="rounded-full border border-gray-200 bg-[#F7FAFC] px-2.5 py-0.5 text-[11px] text-[#2D3748]"
                            >
                                {r}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Entity</th>
                                <th className="px-3 py-2">Jurisdiction</th>
                                <th className="px-3 py-2">Licence class</th>
                                <th className="px-3 py-2">Regulators</th>
                                <th className="px-3 py-2">ITSB target</th>
                                <th className="px-3 py-2">Capital base</th>
                                <th className="px-3 py-2">Estate</th>
                                <th className="px-3 py-2">Localisation gaps</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {entities.map((e) => (
                                <tr key={e.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2">
                                        <span className="font-mono text-xs text-[#0A1F44]">{e.code}</span>
                                        <span className="block text-[#2D3748]">{e.name}</span>
                                        {e.parent && (
                                            <span className="block text-[10px] text-[#718096]">under {e.parent}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {e.jurisdiction_name || e.jurisdiction}
                                        <span className="ml-1 font-mono text-[10px] text-[#718096]">
                                            {e.jurisdiction}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{e.licence_label || '—'}</td>
                                    <td className="max-w-xs px-3 py-2 text-[11px] text-[#718096]">
                                        {(e.regulators || []).join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {e.itsb_target_level ? (
                                            <StatusBadge
                                                status="approved"
                                                label={`Level ${e.itsb_target_level} (Cat ${e.itsb_category === 'one' ? 'I' : 'II'})`}
                                            />
                                        ) : (
                                            <span className="text-xs text-[#718096]">n/a</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {ngn(e.capital_base_ngn)}
                                        {e.meets_capital_threshold !== null && e.meets_capital_threshold !== undefined && (
                                            <span
                                                className={`block text-[10px] ${e.meets_capital_threshold ? 'text-[#2D7D46]' : 'text-[#B3261E]'}`}
                                            >
                                                {e.meets_capital_threshold ? 'meets threshold' : 'below threshold'}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {e.instances} instance(s)
                                        {e.applications > 0 && (
                                            <span className="block text-[10px]">{e.applications} application(s)</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {e.localisation_gaps > 0 ? (
                                            <span className="font-medium text-[#B3261E]">{e.localisation_gaps}</span>
                                        ) : (
                                            <span className="text-[#2D7D46]">none</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
