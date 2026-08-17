import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * PlateauMembership — the authoring half of WS 4.3.
 *
 * §5.1's objection to the old Scenarios page was that it could not create a
 * scenario. This is where one gets created: pick entities, set a disposition,
 * optionally record what the target state costs and why.
 *
 * The rationalisation candidates panel is deliberately adjacent. The cost model
 * can identify where two systems cover one capability and what consolidating
 * would save; it cannot decide which survives. Putting the analysis next to the
 * disposition control is the whole workflow — the model proposes, the architect
 * decides, and the rationale is captured with the decision.
 */

const naira = (value) => (value === null || value === undefined
    ? '—'
    : `₦${Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);

const DISPOSITION_TONES = {
    retain: 'bg-gray-100 text-gray-600',
    introduce: 'bg-[#2D7D46]/10 text-[#2D7D46]',
    modify: 'bg-[#1D4ED8]/10 text-[#1D4ED8]',
    replace: 'bg-[#E5A100]/10 text-[#8a6100]',
    retire: 'bg-[#B3261E]/10 text-[#B3261E]',
};

export default function PlateauMembership({
    plateau,
    entityType,
    entityTypes = [],
    rows = [],
    dispositions = [],
    plateaux = [],
    currencies = [],
    candidates = [],
}) {
    const [selected, setSelected] = useState([]);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const [showBulk, setShowBulk] = useState(false);

    const bulk = useForm({
        entity_type: entityType,
        entity_ids: [],
        disposition: 'retain',
        replaced_by_id: '',
        target_annual_cost: '',
        target_cost_currency: '',
        one_off_cost: '',
        confidence: '',
        rationale: '',
    });

    const seed = useForm({ source_plateau_id: '' });

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();
        return rows.filter((row) => {
            if (filter === 'in' && !row.in_plateau) return false;
            if (filter === 'out' && row.in_plateau) return false;
            if (filter !== 'all' && filter !== 'in' && filter !== 'out' && row.disposition !== filter) return false;
            if (!term) return true;
            return `${row.name} ${row.code || ''}`.toLowerCase().includes(term);
        });
    }, [rows, search, filter]);

    const counts = useMemo(() => {
        const out = { total: rows.length, in: 0 };
        dispositions.forEach((d) => { out[d] = 0; });
        rows.forEach((row) => {
            if (row.in_plateau) out.in += 1;
            if (row.disposition) out[row.disposition] = (out[row.disposition] || 0) + 1;
        });
        return out;
    }, [rows, dispositions]);

    const toggle = (id) => setSelected((current) => (
        current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
    ));

    const applyBulk = (e) => {
        e.preventDefault();
        bulk.transform((data) => ({
            ...data,
            entity_type: entityType,
            entity_ids: selected,
            replaced_by_id: data.replaced_by_id || null,
            target_annual_cost: data.target_annual_cost || null,
            target_cost_currency: data.target_cost_currency || null,
            one_off_cost: data.one_off_cost || null,
            confidence: data.confidence || null,
        }));
        bulk.post(route('ea.plateaux.dispositions', plateau.id), {
            preserveScroll: true,
            onSuccess: () => { setSelected([]); setShowBulk(false); },
        });
    };

    return (
        <AuthenticatedLayout header={`${plateau.name} — membership`}>
            <Head title={`${plateau.name} — membership`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Plateau Diff', href: route('ea.plateau-diff') },
                    { label: plateau.name },
                ]}
                title={`${plateau.name} — scenario membership`}
                subtitle={`${plateau.plateau_type} plateau. A disposition says what happens to each entity by this plateau: retained, introduced, modified in place, replaced by a successor, or retired.`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <form
                            onSubmit={(e) => { e.preventDefault(); seed.post(route('ea.plateaux.seed', plateau.id), { preserveScroll: true }); }}
                            className="flex items-center gap-1"
                        >
                            <select
                                value={seed.data.source_plateau_id}
                                onChange={(e) => seed.setData('source_plateau_id', e.target.value)}
                                className="rounded-lg border border-gray-200 px-2 py-2 text-xs"
                            >
                                <option value="">seed from the live estate</option>
                                {plateaux.map((p) => <option key={p.id} value={p.id}>copy from {p.name}</option>)}
                            </select>
                            <button type="submit" className="rounded-lg border border-gray-200 px-3 py-2 text-xs">Seed</button>
                        </form>
                        <Link href={route('ea.plateau-diff', { to: plateau.id })} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                            Compare
                        </Link>
                    </div>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <KpiCard label="Present in plateau" value={counts.in} tone="navy" sublabel={`of ${counts.total} candidate records`} />
                {dispositions.map((disposition) => (
                    <KpiCard
                        key={disposition}
                        label={disposition}
                        value={counts[disposition] || 0}
                        tone={disposition === 'retire' ? 'red' : disposition === 'introduce' ? 'green' : 'white'}
                    />
                ))}
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 border-b border-gray-200">
                {entityTypes.map((type) => (
                    <Link
                        key={type.value}
                        href={route('ea.plateaux.membership', { plateau: plateau.id, entity_type: type.value })}
                        className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium ${
                            type.value === entityType ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-gray-500'
                        }`}
                    >
                        {type.label} ({type.count})
                    </Link>
                ))}
            </div>

            {candidates.length > 0 && (
                <div className="mb-5 rounded-xl border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-4">
                    <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">
                        Rationalisation candidates — {candidates.length} capability(ies) covered by more than one live system
                    </h3>
                    <p className="mb-3 text-[11px] text-gray-500">
                        The survivor proposed is the best-fitting system, not the cheapest: a bank does not consolidate
                        onto its weakest platform to save money. Selecting the displaced systems here stages them for a
                        disposition below.
                    </p>
                    <div className="max-h-64 overflow-y-auto">
                        <table className="w-full text-xs">
                            <thead className="border-b border-[#C9A86A]/30 text-gray-500">
                                <tr>
                                    <th className="py-1.5 text-left">Capability</th>
                                    <th className="text-left">Proposed survivor</th>
                                    <th className="text-left">Displaced</th>
                                    <th className="text-right">Annual saving</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {candidates.map((candidate) => (
                                    <tr key={candidate.capability.id} className="border-b border-[#C9A86A]/20">
                                        <td className="py-1.5">{candidate.capability.code} — {candidate.capability.name}</td>
                                        <td>
                                            {candidate.survivor?.name}
                                            <span className="ml-1 text-[10px] text-gray-400">
                                                fit {candidate.survivor?.business_fit}/{candidate.survivor?.technical_fit}
                                            </span>
                                        </td>
                                        <td>{candidate.displaced.map((d) => d.name).join(', ')}</td>
                                        <td className="text-right font-medium">
                                            {naira(candidate.annual_saving_ngn)}
                                            {candidate.displaced_without_cost > 0 && (
                                                <span className="ml-1 text-[10px] text-[#8a6100]">
                                                    +{candidate.displaced_without_cost} uncosted
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-right">
                                            <button
                                                onClick={() => {
                                                    setSelected(candidate.displaced.map((d) => d.id));
                                                    bulk.setData('disposition', 'retire');
                                                    bulk.setData('rationale', `Duplicate coverage of ${candidate.capability.name}; consolidated onto ${candidate.survivor?.name}.`);
                                                    setShowBulk(true);
                                                }}
                                                className="text-[#0A1F44] underline"
                                            >
                                                Stage retirement
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search…"
                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                    />
                    <select
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                    >
                        <option value="all">all records</option>
                        <option value="in">in this plateau</option>
                        <option value="out">not in this plateau</option>
                        {dispositions.map((d) => <option key={d} value={d}>disposition: {d}</option>)}
                    </select>
                    <button
                        onClick={() => setSelected(filtered.map((row) => row.entity_id))}
                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                    >
                        Select all {filtered.length}
                    </button>
                    {selected.length > 0 && (
                        <>
                            <span className="text-xs font-medium text-[#0A1F44]">{selected.length} selected</span>
                            <button onClick={() => setShowBulk(true)} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">
                                Set disposition
                            </button>
                            <button onClick={() => setSelected([])} className="text-xs text-gray-500 underline">clear</button>
                        </>
                    )}
                </div>

                <div className="max-h-[520px] overflow-y-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 border-b border-gray-100 bg-white text-xs text-gray-500">
                            <tr>
                                <th className="w-8 py-2"></th>
                                <th className="text-left">Code</th>
                                <th className="text-left">Name</th>
                                <th className="text-left">Criticality</th>
                                <th className="text-right">Current TCO</th>
                                <th className="text-left">Disposition</th>
                                <th className="text-right">Target cost</th>
                                <th className="text-left">Rationale</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.map((row) => (
                                <tr key={row.entity_id} className={`border-b border-gray-50 ${selected.includes(row.entity_id) ? 'bg-[#C9A86A]/5' : ''}`}>
                                    <td className="py-1.5">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(row.entity_id)}
                                            onChange={() => toggle(row.entity_id)}
                                        />
                                    </td>
                                    <td className="font-mono text-xs">{row.code}</td>
                                    <td className="text-[#0A1F44]">{row.name}</td>
                                    <td className="text-xs">{row.criticality || '—'}</td>
                                    <td className="text-right text-xs">{naira(row.current_tco_ngn)}</td>
                                    <td>
                                        {row.disposition ? (
                                            <span className={`rounded px-1.5 py-0.5 text-[10px] ${DISPOSITION_TONES[row.disposition] || ''}`}>
                                                {row.disposition}
                                            </span>
                                        ) : (
                                            <span className="text-[10px] text-gray-300">not a member</span>
                                        )}
                                    </td>
                                    <td className="text-right text-xs">
                                        {row.target_annual_cost
                                            ? `${row.target_cost_currency || 'NGN'} ${Number(row.target_annual_cost).toLocaleString()}`
                                            : '—'}
                                        {row.confidence && <span className="ml-1 text-[10px] text-gray-400">c{row.confidence}</span>}
                                    </td>
                                    <td className="max-w-[220px] truncate text-[11px] text-gray-500" title={row.rationale || ''}>
                                        {row.rationale || '—'}
                                    </td>
                                </tr>
                            ))}
                            {filtered.length === 0 && (
                                <tr><td colSpan={8} className="py-6 text-center text-xs text-gray-400">Nothing matches.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {showBulk && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="mb-1 font-bold text-[#0A1F44]">
                            Set disposition for {selected.length} record(s)
                        </h3>
                        <p className="mb-3 text-xs text-gray-500">
                            In {plateau.name}. Leave target cost blank to keep today's cost — an honest default, since
                            an unmodified system costs what it costs.
                        </p>
                        <form onSubmit={applyBulk} className="space-y-3">
                            <label className="block text-xs">
                                <span className="mb-1 block font-medium text-gray-500">Disposition</span>
                                <select
                                    value={bulk.data.disposition}
                                    onChange={(e) => bulk.setData('disposition', e.target.value)}
                                    className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                >
                                    {dispositions.map((d) => <option key={d} value={d}>{d}</option>)}
                                </select>
                            </label>

                            {bulk.data.disposition === 'replace' && (
                                <label className="block text-xs">
                                    <span className="mb-1 block font-medium text-gray-500">Replaced by (required)</span>
                                    <select
                                        value={bulk.data.replaced_by_id}
                                        onChange={(e) => bulk.setData('replaced_by_id', e.target.value)}
                                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                    >
                                        <option value="">choose the successor…</option>
                                        {rows.filter((row) => !selected.includes(row.entity_id))
                                            .map((row) => <option key={row.entity_id} value={row.entity_id}>{row.name}</option>)}
                                    </select>
                                    <span className="mt-1 block text-[11px] text-gray-400">
                                        A replacement without a successor leaves a gap where a capability used to be, so
                                        this is refused server-side too.
                                    </span>
                                </label>
                            )}

                            <div className="grid grid-cols-3 gap-2">
                                <label className="block text-xs">
                                    <span className="mb-1 block font-medium text-gray-500">Target annual cost</span>
                                    <input
                                        type="number" step="0.01" min="0"
                                        value={bulk.data.target_annual_cost}
                                        onChange={(e) => bulk.setData('target_annual_cost', e.target.value)}
                                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                    />
                                </label>
                                <label className="block text-xs">
                                    <span className="mb-1 block font-medium text-gray-500">Currency</span>
                                    <select
                                        value={bulk.data.target_cost_currency}
                                        onChange={(e) => bulk.setData('target_cost_currency', e.target.value)}
                                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                    >
                                        <option value="">—</option>
                                        {currencies.map((c) => <option key={c} value={c}>{c}</option>)}
                                    </select>
                                </label>
                                <label className="block text-xs">
                                    <span className="mb-1 block font-medium text-gray-500">One-off cost</span>
                                    <input
                                        type="number" step="0.01" min="0"
                                        value={bulk.data.one_off_cost}
                                        onChange={(e) => bulk.setData('one_off_cost', e.target.value)}
                                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                    />
                                </label>
                            </div>

                            <label className="block text-xs">
                                <span className="mb-1 block font-medium text-gray-500">
                                    Confidence in the numbers (1 = planning assumption, 5 = signed commercial position)
                                </span>
                                <select
                                    value={bulk.data.confidence}
                                    onChange={(e) => bulk.setData('confidence', e.target.value)}
                                    className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                >
                                    <option value="">not stated</option>
                                    {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                                </select>
                            </label>

                            <label className="block text-xs">
                                <span className="mb-1 block font-medium text-gray-500">Rationale</span>
                                <textarea
                                    rows={3}
                                    value={bulk.data.rationale}
                                    onChange={(e) => bulk.setData('rationale', e.target.value)}
                                    className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                                    placeholder="Why this entity has this disposition in the target state."
                                />
                            </label>

                            <div className="flex items-center justify-end gap-2">
                                <button type="button" onClick={() => setShowBulk(false)} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={bulk.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                                    Apply to {selected.length}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
