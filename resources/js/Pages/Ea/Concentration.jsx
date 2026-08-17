import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';

/**
 * Vendor & Integrator Concentration — A4.
 *
 * ATH-EAR-002 §6.3: "CWG Plc is the sole Nigerian distributor of Infosys
 * Finacle and serves First Bank, GTBank, UBA, Fidelity, Stanbic IBTC, FCMB and
 * Wema… The CBN cybersecurity framework §2.3 and App. II §1.4 explicitly
 * require banks to manage third-party concentration — and **no bank can
 * currently visualise it**."
 *
 * The role dimension is what makes this different from a vendor list: "the CWG
 * case is a *services* concentration, which is invisible if you only model the
 * software publisher."
 */

const BAND = {
    highly_concentrated: { tone: 'fail', label: 'Highly concentrated' },
    moderately_concentrated: { tone: 'warn', label: 'Moderately concentrated' },
    unconcentrated: { tone: 'pass', label: 'Unconcentrated' },
    no_data: { tone: 'draft', label: 'No data' },
};

const ngn = (v) => '₦' + Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

export default function Concentration({ summary = {}, byCapability = [], byRole = [], spofRegister = [] }) {
    return (
        <AuthenticatedLayout header="Concentration">
            <Head title="Vendor & Integrator Concentration" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Portfolio' }, { label: 'Concentration' }]}
                title="Vendor & Integrator Concentration"
                subtitle="Where the estate depends on one supplier — measured across publishers, integrators, hosting and connectivity separately."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.vendor-concentration')} current="ea.vendor-concentration" />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Capabilities assessed" value={summary.capabilities_assessed || 0} tone="navy" />
                <KpiCard
                    label="Highly concentrated"
                    value={summary.highly_concentrated || 0}
                    tone={summary.highly_concentrated ? 'red' : 'green'}
                    sublabel="HHI ≥ 2,500"
                />
                <KpiCard label="Mean HHI" value={summary.mean_hhi || 0} tone="white" />
                <KpiCard
                    label="Single points of failure"
                    value={summary.spof_count || 0}
                    tone={summary.critical_spof ? 'red' : 'amber'}
                    sublabel={`${summary.critical_spof || 0} critical`}
                />
                <KpiCard
                    label="Vendors without a role"
                    value={summary.vendors_without_role || 0}
                    tone={summary.vendors_without_role ? 'amber' : 'white'}
                    sublabel="Services concentration invisible"
                />
            </div>

            {summary.vendors_without_role > 0 && (
                <p className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4 text-xs text-[#2D3748]">
                    <span className="font-semibold text-[#8A6400]">
                        {summary.vendors_without_role} vendor(s) have no role recorded.
                    </span>{' '}
                    Until a supplier is classified as publisher, integrator, hosting, managed service or
                    connectivity, a services concentration behind several different software publishers stays
                    invisible — which is exactly the shape of the best-known Nigerian example.
                </p>
            )}

            {spofRegister.length > 0 && (
                <div className="mb-5 overflow-hidden rounded-xl border border-[#B3261E]/30 bg-white shadow-sm">
                    <h3 className="border-b border-[#B3261E]/20 bg-[#B3261E]/5 px-4 py-3 text-sm font-semibold text-[#B3261E]">
                        Single-point-of-failure register
                    </h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Capability</th>
                                <th className="px-3 py-2">Supplier</th>
                                <th className="px-3 py-2">Role</th>
                                <th className="px-3 py-2">Share</th>
                                <th className="px-3 py-2">Critical apps</th>
                                <th className="px-3 py-2">Severity</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {spofRegister.map((s, i) => (
                                <tr key={i} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {s.capability}
                                        {s.note && <span className="block text-[10px] text-[#718096]">{s.note}</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs font-medium text-[#0A1F44]">{s.vendor}</td>
                                    <td className="px-3 py-2 text-xs capitalize text-[#718096]">
                                        {String(s.role || '').replace(/_/g, ' ') || '—'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{s.share}%</td>
                                    <td className="px-3 py-2 text-xs">{s.critical_applications}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={s.severity === 'critical' ? 'critical' : s.severity === 'high' ? 'high' : 'moderate'}
                                            label={s.severity}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="mb-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Concentration by supplier role</h3>
                    <p className="mt-0.5 text-xs text-[#718096]">
                        A bank looking only at software publishers can conclude it is diversified while the services
                        layer underneath is a single firm.
                    </p>
                </div>
                {byRole.length === 0 ? (
                    <p className="px-4 py-6 text-center text-xs text-[#718096]">
                        No suppliers have a role recorded yet.
                    </p>
                ) : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Role</th>
                                <th className="px-3 py-2">Suppliers</th>
                                <th className="px-3 py-2">Applications</th>
                                <th className="px-3 py-2">HHI</th>
                                <th className="px-3 py-2">Largest supplier</th>
                                <th className="px-3 py-2">Share</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {byRole.map((r) => (
                                <tr key={r.role} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">{r.role_label}</td>
                                    <td className="px-3 py-2 text-xs">{r.vendors}</td>
                                    <td className="px-3 py-2 text-xs">{r.applications}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={BAND[r.band]?.tone || 'draft'} label={String(r.hhi)} />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {r.top_vendor}
                                        {r.top_vendor_origin && (
                                            <span className="ml-1 text-[10px]">({r.top_vendor_origin})</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        <span className={r.top_vendor_share >= 60 ? 'font-medium text-[#B3261E]' : ''}>
                                            {r.top_vendor_share}%
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Concentration by business capability</h3>
                    <p className="mt-0.5 text-xs text-[#718096]">
                        HHI on the competition-authority scale: 2,500+ highly concentrated, 1,500–2,500 moderately.
                    </p>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Capability</th>
                                <th className="px-3 py-2">Apps</th>
                                <th className="px-3 py-2">Critical</th>
                                <th className="px-3 py-2">Vendors</th>
                                <th className="px-3 py-2">HHI</th>
                                <th className="px-3 py-2">Largest</th>
                                <th className="px-3 py-2">Share</th>
                                <th className="px-3 py-2">Spend</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {byCapability.slice(0, 30).map((c) => (
                                <tr key={c.capability_id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">{c.capability}</td>
                                    <td className="px-3 py-2 text-xs">{c.applications}</td>
                                    <td className="px-3 py-2 text-xs">{c.critical_applications}</td>
                                    <td className="px-3 py-2 text-xs">{c.vendors}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={BAND[c.band]?.tone || 'draft'} label={String(c.hhi)} />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.top_vendor || '—'}</td>
                                    <td className="px-3 py-2 text-xs">{c.top_vendor_share}%</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{ngn(c.annual_spend_ngn)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
