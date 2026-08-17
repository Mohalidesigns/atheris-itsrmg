import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

/**
 * Channels & Rails — A7, the African channel model.
 *
 * ATH-EAR-002 §6.3: "USSD is a first-class retail channel in Nigeria with
 * commercial and regulatory fragility that has no Western analogue: a
 * **four-year debt dispute between banks and telcos**, telcos suspending
 * service, and the FG **deactivating nine banks' USSD codes**, resolved only in
 * early 2026."
 *
 * Modelling the telco as a dependency turns "MTN can switch off this channel
 * over a billing dispute" from institutional folklore into an assessable risk.
 */
export default function Channels({ channels = [], rails = [], summary = {} }) {
    const exposed = channels.filter((c) => c.is_exposed);

    return (
        <AuthenticatedLayout header="Channels & Rails">
            <Head title="Channels & Rails" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Integration Architecture' },
                    { label: 'Channels & Rails' },
                ]}
                title="Channels & Payment Rails"
                subtitle="The retail channels customers actually use, the shared rails underneath them, and who can switch either off."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Channels" value={summary.channels || 0} tone="navy" />
                <KpiCard
                    label="Third party can suspend"
                    value={summary.exposed_channels || 0}
                    tone={summary.exposed_channels ? 'red' : 'green'}
                    sublabel="Critical or high criticality"
                />
                <KpiCard label="Payment rails" value={summary.rails || 0} tone="white" />
                <KpiCard label="Licensed switches" value={summary.switches || 0} tone="white" />
                <KpiCard label="Card schemes" value={summary.schemes || 0} tone="white" />
            </div>

            {exposed.length > 0 && (
                <div className="mb-5 rounded-xl border border-[#B3261E]/30 bg-[#B3261E]/5 p-4">
                    <p className="text-sm font-semibold text-[#B3261E]">
                        {exposed.length} critical channel(s) can be suspended by a third party
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        This is a commercial dependency, not a technical one — it does not appear in an availability
                        model and no global EA tool has a place to record it. Nigerian precedent includes telcos
                        suspending USSD service during a multi-year billing dispute.
                    </p>
                </div>
            )}

            <div className="mb-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">Channels</h3>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Channel</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Entity</th>
                                <th className="px-3 py-2">Criticality</th>
                                <th className="px-3 py-2">Third-party dependency</th>
                                <th className="px-3 py-2">Suspension risk</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {channels.map((c) => (
                                <tr key={c.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {c.name}
                                        {c.shortcode && (
                                            <span className="ml-2 font-mono text-[10px] text-[#718096]">
                                                {c.shortcode}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.type_label}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.legal_entity || '—'}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={
                                                c.criticality === 'critical'
                                                    ? 'critical'
                                                    : c.criticality === 'high'
                                                      ? 'high'
                                                      : 'moderate'
                                            }
                                            label={c.criticality}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {c.telco || c.aggregator || '—'}
                                    </td>
                                    <td className="max-w-md px-3 py-2 text-[11px]">
                                        {c.third_party_can_suspend ? (
                                            <span className="text-[#B3261E]">
                                                {c.suspension_risk_notes || 'A third party can suspend this channel.'}
                                            </span>
                                        ) : (
                                            <span className="text-[#718096]">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Rails, switches and schemes</h3>
                    <p className="mt-0.5 text-xs text-[#718096]">
                        Licence conditions are carried as attributes so the constraint set is inspectable — and
                        answerable in a return.
                    </p>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Rail</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Operator</th>
                                <th className="px-3 py-2">Mandatory</th>
                                <th className="px-3 py-2">Licence conditions</th>
                                <th className="px-3 py-2">Interfaces</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rails.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        <span className="font-mono text-xs text-[#0A1F44]">{r.code}</span>
                                        <span className="block">{r.name}</span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.type_label}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.operator || '—'}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {r.is_mandatory ? (
                                            <StatusBadge status="approved" label="Mandatory" />
                                        ) : (
                                            <span className="text-[#718096]">—</span>
                                        )}
                                    </td>
                                    <td className="max-w-md px-3 py-2 text-[11px] text-[#718096]">
                                        {r.conditions?.length ? r.conditions.join(' · ') : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{r.interface_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
