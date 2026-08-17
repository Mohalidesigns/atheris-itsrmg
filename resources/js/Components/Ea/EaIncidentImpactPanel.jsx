import StatusBadge from '@/Components/StatusBadge';
import { Link } from '@inertiajs/react';

/**
 * Blast radius on the incident screen — ATH-EAR-002 §7.4, contract I-13.
 *
 * §7.3: "Incident detail shows blast radius, dependent business services, RTO
 * exposure and third parties — inside the **CBN 30-minute response window**."
 * Before this the analysis existed but was reachable only from inside the EA
 * module, which does not help someone triaging a live incident.
 *
 * Deliberately compact and text-first: §10 requires the platform to be usable
 * on 3G, and a responder needs the numbers, not a graph render.
 */
export default function EaIncidentImpactPanel({ impact }) {
    if (!impact) {
        return null;
    }

    const { application: app } = impact;
    const rto = impact.tightest_rto_hours;

    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 px-5 py-4">
                <div>
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Architecture impact</h3>
                    <Link href={app.href} className="text-xs text-[#0A1F44] hover:underline">
                        {app.code} — {app.name}
                    </Link>
                </div>
                <div className="flex items-center gap-2">
                    <StatusBadge
                        status={
                            app.criticality === 'critical'
                                ? 'critical'
                                : app.criticality === 'high'
                                  ? 'high'
                                  : 'moderate'
                        }
                        label={app.criticality}
                    />
                    <Link
                        href={impact.blast_radius_href}
                        className="rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Full blast radius
                    </Link>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 px-5 py-4 md:grid-cols-4">
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Dependent nodes</p>
                    <p className="mt-1 text-xl font-bold text-[#0A1F44]">{impact.node_count}</p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Business processes</p>
                    <p className="mt-1 text-xl font-bold text-[#0A1F44]">{impact.processes?.length || 0}</p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Tightest RTO</p>
                    <p
                        className={`mt-1 text-xl font-bold ${rto !== null && rto !== undefined && rto <= 4 ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}
                    >
                        {rto !== null && rto !== undefined ? `${rto}h` : '—'}
                    </p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Impacted types</p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        {impact.by_type && Object.keys(impact.by_type).length
                            ? Object.entries(impact.by_type)
                                  .map(([type, count]) => `${count} ${type}`)
                                  .join(' · ')
                            : '—'}
                    </p>
                </div>
            </div>

            {impact.processes?.length > 0 && (
                <div className="border-t border-gray-100 px-5 py-4">
                    <p className="mb-2 text-[10px] uppercase tracking-wide text-[#718096]">
                        Business processes affected
                    </p>
                    <table className="min-w-full text-xs">
                        <thead>
                            <tr className="text-left text-[10px] uppercase text-[#718096]">
                                <th className="pb-1 pr-3">Process</th>
                                <th className="pb-1 pr-3">Criticality</th>
                                <th className="pb-1 pr-3">RTO</th>
                                <th className="pb-1">RPO</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {impact.processes.map((p) => (
                                <tr key={p.id}>
                                    <td className="py-1.5 pr-3 text-[#2D3748]">{p.name}</td>
                                    <td className="py-1.5 pr-3 capitalize text-[#718096]">{p.criticality}</td>
                                    <td className="py-1.5 pr-3 text-[#2D3748]">
                                        {p.rto_hours !== null ? `${p.rto_hours}h` : '—'}
                                    </td>
                                    <td className="py-1.5 text-[#2D3748]">
                                        {p.rpo_hours !== null ? `${p.rpo_hours}h` : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-2 text-[10px] text-[#718096]">
                        Recovery objectives come from the BIA, not from the architecture repository — they are what
                        the business signed off.
                    </p>
                </div>
            )}
        </div>
    );
}
