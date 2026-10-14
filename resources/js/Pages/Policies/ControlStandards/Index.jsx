import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import { PolicyStatus } from '@/Components/Policies/PolicyBadges';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const tone = { effective: 'bg-[#D1FAE5] text-[#2D7D46]', partially_effective: 'bg-[#FEF3C7] text-[#8A6400]', ineffective: 'bg-[#FEE2E2] text-[#C53030]', not_assessed: 'bg-gray-100 text-[#718096]' };

export default function ControlStandards({ policies = [], totals = {}, categories = {}, filters = {}, canEdit = false }) {
    const [open, setOpen] = useState(null);

    return (
        <AuthenticatedLayout header="Control Standards">
            <Head title="Control Standards" />
            <div className="mb-4">
                <h2 className="text-lg font-semibold text-[#2D3748]">Control Standards</h2>
                <p className="text-sm text-[#718096]">The controls that implement each policy, and how effective they are. A policy in force with no control, or with failing controls, is a policy on paper only.</p>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Policies" value={totals.policies ?? 0} sublabel="in review, approved or in force" tone="navy" />
                <KpiCard label="Control mappings" value={totals.controls ?? 0} tone="white" />
                <KpiCard label="No control mapped" value={totals.unmapped ?? 0} tone={totals.unmapped ? 'amber' : 'white'} />
                <KpiCard label="With a failing control" value={totals.with_failing ?? 0} tone={totals.with_failing ? 'red' : 'white'} />
            </div>

            <div className="mb-3">
                <select value={filters.category || ''} onChange={(e) => router.get(route('control-standards.index'), { category: e.target.value || undefined }, { preserveState: true })} className="text-sm border-gray-200 rounded-lg">
                    <option value="">All categories</option>
                    {Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                {policies.map((p) => (
                    <div key={p.id}>
                        <button type="button" onClick={() => setOpen(open === p.id ? null : p.id)} className="flex w-full flex-col gap-2 px-4 py-3 text-left hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <span className="font-mono-data text-xs text-[#1A365D]">{p.policy_code}</span> <span className="text-sm font-medium text-[#2D3748]">{p.title}</span>
                                <p className="text-[11px] text-[#718096]">{categories[p.category] || p.category} · v{p.version_number}</p>
                            </div>
                            <div className="flex items-center gap-3 text-xs">
                                <PolicyStatus status={p.status} />
                                {p.controls.length === 0
                                    ? <span className="font-semibold text-[#B3261E]">No control mapped</span>
                                    : <span className="text-[#718096]">{p.controls.length} controls · {p.effective_count} effective{p.failing_count ? <span className="font-semibold text-[#B3261E]"> · {p.failing_count} failing</span> : ''}</span>}
                                {p.coverage != null && <span className="w-10 text-right font-semibold text-[#0A1F44]">{p.coverage}%</span>}
                            </div>
                        </button>
                        {open === p.id && (
                            <div className="bg-gray-50/60 px-4 py-3">
                                {p.controls.length === 0 ? <p className="text-xs text-[#718096]">Map controls from the policy's edit form.</p> : (
                                    <ul className="space-y-1">
                                        {p.controls.map((c) => (
                                            <li key={c.id} className="flex items-center justify-between text-sm">
                                                <span><Link href={route('controls.show', c.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">{c.control_code}</Link> {c.title} <span className="text-[11px] text-[#718096]">· {c.domain}</span></span>
                                                <span className={`rounded px-1.5 py-0.5 text-[11px] ${tone[c.effectiveness] || tone.not_assessed}`}>{(c.effectiveness || 'not assessed').replace('_', ' ')}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <div className="mt-2 flex gap-3 text-xs">
                                    <Link href={route('policies.show', p.id)} className="text-[#0A1F44] underline">Open policy</Link>
                                    {canEdit && p.status !== 'retired' && <Link href={route('policies.edit', p.id)} className="text-[#0A1F44] underline">Edit mapping</Link>}
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
