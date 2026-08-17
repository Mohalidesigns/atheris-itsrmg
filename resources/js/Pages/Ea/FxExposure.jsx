import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { exportCsv } from '@/Components/Ea/EaIndexToolbar';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, router } from '@inertiajs/react';

/**
 * FX Exposure lens — A3.
 *
 * ATH-EAR-002 §6.3: "This is the artefact that sells the module to the **CFO**,
 * not the CISO or the CIO. It is generated entirely from EA data and **no
 * global EA tool has a currency dimension at all**."
 *
 * The market condition: tier-1 banks spend at least $10m a year on core banking
 * licences alone, dollar-priced, and naira devaluation nearly doubled that cost
 * — directly driving Sterling Bank's 2024 migration to the indigenous SeaBaaS
 * platform.
 */

const ngn = (v) => '₦' + Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

export default function FxExposure({ dashboard = {}, stressTest = {}, renewals = [], candidates = [], rates = {} }) {
    const perms = useEaPermissions();
    const [rate, setRate] = useState(stressTest.baseline_rate || 1550);

    const projected = (stressTest.usd_native_total || 0) * rate;
    const baseline = stressTest.scenarios?.find((s) => s.rate === stressTest.baseline_rate);
    const nonUsd = (dashboard.total_ngn || 0) - ((stressTest.usd_native_total || 0) * (stressTest.baseline_rate || 0));
    const projectedTotal = projected + nonUsd;

    return (
        <AuthenticatedLayout header="FX Exposure">
            <Head title="FX Exposure" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Portfolio' }, { label: 'FX Exposure' }]}
                title="FX Exposure on the Application Portfolio"
                subtitle="What the estate costs, in which currency, and what happens to that when the naira moves."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Annual cost base" value={ngn(dashboard.total_ngn)} tone="navy" />
                <KpiCard
                    label="Foreign-currency exposure"
                    value={`${dashboard.foreign_share || 0}%`}
                    tone={dashboard.foreign_share > 60 ? 'red' : dashboard.foreign_share > 30 ? 'amber' : 'green'}
                    sublabel={ngn(dashboard.foreign_ngn)}
                />
                <KpiCard label="Systems costed" value={dashboard.systems_costed || 0} tone="white" />
                <KpiCard
                    label="Unknown currency"
                    value={dashboard.unknown_currency || 0}
                    tone={dashboard.unknown_currency ? 'amber' : 'white'}
                    sublabel="Exposure not measurable"
                />
                <KpiCard
                    label="Indigenous platforms"
                    value={`${dashboard.indigenous_share || 0}%`}
                    tone="gold"
                    sublabel="Naira-priced"
                />
            </div>

            <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Cost base by currency</h3>
                    <table className="mt-3 min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="pb-1 pr-3">Currency</th>
                                <th className="pb-1 pr-3">Systems</th>
                                <th className="pb-1 pr-3">Native</th>
                                <th className="pb-1 pr-3">Rate</th>
                                <th className="pb-1">Naira equivalent</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {(dashboard.by_currency || []).map((c) => (
                                <tr key={c.currency}>
                                    <td className="py-1.5 pr-3 font-medium text-[#2D3748]">{c.currency}</td>
                                    <td className="py-1.5 pr-3 text-xs">{c.systems}</td>
                                    <td className="py-1.5 pr-3 text-xs">
                                        {Number(c.native_total).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                                    </td>
                                    <td className="py-1.5 pr-3 text-xs text-[#718096]">{c.rate}</td>
                                    <td className="py-1.5 text-xs">{ngn(c.ngn_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Devaluation stress test</h3>
                    <p className="mt-1 text-xs text-[#718096]">
                        At ₦{rate}/$ the estate costs {ngn(projectedTotal)} a year.
                    </p>
                    <input
                        type="range"
                        min={800}
                        max={3000}
                        step={50}
                        value={rate}
                        onChange={(e) => setRate(Number(e.target.value))}
                        className="mt-3 w-full accent-[#0A1F44]"
                        aria-label="Naira to dollar rate"
                    />
                    <div className="mt-1 flex justify-between text-[10px] text-[#718096]">
                        <span>₦800</span>
                        <span>₦{rate}/$</span>
                        <span>₦3,000</span>
                    </div>

                    <table className="mt-4 min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="pb-1 pr-3">Rate</th>
                                <th className="pb-1 pr-3">Total</th>
                                <th className="pb-1">Change</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {(stressTest.scenarios || []).map((s) => (
                                <tr key={s.rate} className={s.rate === stressTest.baseline_rate ? 'bg-[#F7FAFC]' : ''}>
                                    <td className="py-1.5 pr-3 text-xs">
                                        ₦{s.rate}
                                        {s.rate === stressTest.baseline_rate && (
                                            <span className="ml-1 text-[10px] text-[#718096]">current</span>
                                        )}
                                    </td>
                                    <td className="py-1.5 pr-3 text-xs">{ngn(s.total_ngn)}</td>
                                    <td className="py-1.5 text-xs">
                                        <span className={s.delta_ngn > 0 ? 'text-[#B3261E]' : s.delta_ngn < 0 ? 'text-[#2D7D46]' : 'text-[#718096]'}>
                                            {s.delta_percent > 0 ? '+' : ''}{s.delta_percent}%
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Renewal calendar (18 months)</h3>
                        {perms.canExport && (
                            <button
                                type="button"
                                onClick={() =>
                                    exportCsv('renewal-calendar.csv', [
                                        { key: 'label', label: 'System' },
                                        { key: 'currency', label: 'Currency' },
                                        { key: 'native_cost', label: 'Native cost' },
                                        { key: 'ngn_cost', label: 'NGN cost' },
                                        { key: 'contract_end', label: 'Contract end' },
                                        { key: 'decision_by', label: 'Decision by' },
                                    ], renewals)
                                }
                                className="text-xs text-[#0A1F44] hover:underline"
                            >
                                Export
                            </button>
                        )}
                    </div>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">System</th>
                                <th className="px-3 py-2">Cost</th>
                                <th className="px-3 py-2">Decide by</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {renewals.slice(0, 15).map((r) => (
                                <tr key={r.application_id}>
                                    <td className="px-3 py-2 text-xs text-[#2D3748]">{r.label}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {r.currency} {Number(r.native_cost).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                                        {r.is_foreign_currency && (
                                            <span className="ml-1 text-[10px] text-[#B3261E]">FX</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {r.decision_by}
                                        <span className={`block text-[10px] ${r.days_to_decision < 30 ? 'text-[#B3261E]' : 'text-[#718096]'}`}>
                                            {r.days_to_decision < 0 ? 'notice window passed' : `${r.days_to_decision}d`}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {renewals.length === 0 && (
                                <tr><td colSpan={3} className="px-3 py-6 text-center text-xs text-[#718096]">No contracts expiring in the next 18 months.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Currency localisation candidates</h3>
                        <p className="mt-0.5 text-xs text-[#718096]">
                            Foreign-currency systems ranked by cost, criticality and how substitutable the vendor is.
                        </p>
                    </div>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">System</th>
                                <th className="px-3 py-2">Vendor</th>
                                <th className="px-3 py-2">Cost</th>
                                <th className="px-3 py-2">Score</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {candidates.slice(0, 15).map((c) => (
                                <tr key={c.application_id}>
                                    <td className="px-3 py-2 text-xs text-[#2D3748]">{c.label}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {c.vendor || '—'}
                                        {c.vendor_origin && <span className="ml-1 text-[10px]">({c.vendor_origin})</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{ngn(c.ngn_cost)}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={c.candidate_score >= 60 ? 'critical' : c.candidate_score >= 40 ? 'high' : 'moderate'}
                                            label={String(c.candidate_score)}
                                        />
                                    </td>
                                </tr>
                            ))}
                            {candidates.length === 0 && (
                                <tr><td colSpan={4} className="px-3 py-6 text-center text-xs text-[#718096]">No foreign-currency systems recorded.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 className="text-sm font-semibold text-[#0A1F44]">Exposure by business capability</h3>
                <table className="mt-3 min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="pb-1 pr-3">Capability</th>
                            <th className="pb-1 pr-3">Systems</th>
                            <th className="pb-1 pr-3">Total</th>
                            <th className="pb-1 pr-3">Foreign currency</th>
                            <th className="pb-1">Share</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {(dashboard.by_capability || []).map((c) => (
                            <tr key={c.capability}>
                                <td className="py-1.5 pr-3 text-xs text-[#2D3748]">{c.capability}</td>
                                <td className="py-1.5 pr-3 text-xs">{c.systems}</td>
                                <td className="py-1.5 pr-3 text-xs">{ngn(c.ngn_total)}</td>
                                <td className="py-1.5 pr-3 text-xs">{ngn(c.foreign_ngn)}</td>
                                <td className="py-1.5 text-xs">
                                    <span className={c.foreign_share > 70 ? 'text-[#B3261E]' : 'text-[#718096]'}>
                                        {c.foreign_share}%
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
