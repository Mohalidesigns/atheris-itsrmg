import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import RowActions from '@/Components/Ea/RowActions';
import useEaPermissions from '@/Components/Ea/useEaPermissions';

/**
 * Diagrams — the index for WS 4.1.
 *
 * Two things changed in Phase 4 beyond the editor itself. A new diagram can be
 * *derived* from the repository rather than started blank, which is how a real
 * estate gets its first hundred diagrams. And the topology counter is on the
 * page because RBCF App. II §1.1(i) asks a Nigerian bank for an **approved**
 * network topology diagram — surfacing the count is how an architect discovers
 * the bank does not have one.
 */
export default function Diagrams({ diagrams = [], viewpoints = [], topology = {}, startTypes = [] }) {
    const perms = useEaPermissions();
    const [showNew, setShowNew] = useState(false);
    const [deleting, setDeleting] = useState(null);

    const form = useForm({
        code: '',
        name: '',
        viewpoint: 'custom',
        description: '',
        is_topology: false,
        layout_algorithm: 'layered',
        seed_entity_type: '',
        seed_entity_id: '',
        seed_depth: 1,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('ea.diagrams.store'), { onSuccess: () => setShowNew(false) });
    };

    const withErrors = diagrams.filter((d) => d.errors > 0).length;

    return (
        <AuthenticatedLayout header="EA Diagrams">
            <Head title="EA Diagrams" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Diagrams' }]}
                title="ArchiMate Diagrams & Viewpoints"
                subtitle="An authoring canvas over the repository: drag records from the palette, connect them with validated ArchiMate relationships, auto-layout, version every save, and export to SVG, PNG or PDF."
                actions={
                    <div className="flex items-center gap-2">
                        <Link href={route('ea.round-trip')} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            Round-trip gate
                        </Link>
                        {perms.canCreate && (
                            <button onClick={() => setShowNew(true)} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                                New diagram
                            </button>
                        )}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Saved diagrams" value={diagrams.length} tone="navy" />
                <KpiCard label="Approved" value={diagrams.filter((d) => d.approved_at).length} tone="green" />
                <KpiCard
                    label="With metamodel errors"
                    value={withErrors}
                    tone={withErrors ? 'amber' : 'white'}
                    sublabel="cannot be approved until resolved"
                />
                <KpiCard
                    label="Topology diagrams"
                    value={`${topology.approved ?? 0} / ${topology.total ?? 0}`}
                    tone={(topology.approved ?? 0) > 0 ? 'gold' : 'red'}
                    sublabel="approved — CBN RBCF App. II §1.1(i)"
                />
                <KpiCard label="Generated viewpoints" value={viewpoints.length} tone="white" />
            </div>

            <div className="mb-6 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 className="mb-3 font-bold text-[#0A1F44]">ArchiMate 3.2 viewpoints (generated from the repository)</h3>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                    {viewpoints.map((v) => (
                        <Link key={v} href={route('ea.viewpoint', v)} className="block rounded-lg border border-gray-100 p-3 hover:border-[#C9A86A]">
                            <div className="text-sm font-medium text-[#0A1F44]">
                                {v.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
                            </div>
                            <div className="mt-1 text-xs text-gray-500">Live view — no canvas to maintain</div>
                        </Link>
                    ))}
                </div>
            </div>

            <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 className="mb-3 font-bold text-[#0A1F44]">Saved diagrams</h3>
                {/* Eight columns do not fit a phone. Without this the table
                    widens the page instead of itself, and the right-hand action
                    column ends up off-screen and unreachable. */}
                <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                    <thead className="border-b border-gray-100 text-xs text-gray-500">
                        <tr>
                            <th className="py-2 text-left">Code</th>
                            <th className="text-left">Name</th>
                            <th className="text-left">Viewpoint</th>
                            <th className="text-left">Status</th>
                            <th className="text-right">Contents</th>
                            <th className="text-left">Validation</th>
                            <th className="text-left">Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {diagrams.map((d) => (
                            <tr key={d.id} className="border-b border-gray-50">
                                <td className="py-2 font-mono text-xs">{d.code}</td>
                                <td className="text-[#0A1F44]">
                                    {d.name}
                                    {d.is_topology && (
                                        <span className="ml-2 rounded bg-[#C9A86A]/15 px-1.5 py-0.5 text-[10px] text-[#8a6100]">
                                            topology
                                        </span>
                                    )}
                                </td>
                                <td className="text-xs text-gray-500">{d.viewpoint}</td>
                                <td className="text-xs">
                                    <span className={`rounded px-1.5 py-0.5 text-[10px] ${
                                        d.approved_at ? 'bg-[#2D7D46]/10 text-[#2D7D46]'
                                            : d.status === 'review' ? 'bg-[#E5A100]/15 text-[#8a6100]'
                                                : 'bg-gray-100 text-gray-600'
                                    }`}>
                                        {d.status}
                                    </span>
                                    {d.approved_at && <div className="mt-0.5 text-[10px] text-gray-400">{d.approved_at}</div>}
                                </td>
                                <td className="text-right text-xs text-gray-500">
                                    {d.element_count} el / {d.edge_count} rel
                                    <div className="text-[10px] text-gray-400">v{d.version}</div>
                                </td>
                                <td className="text-xs">
                                    {d.errors > 0
                                        ? <span className="text-[#B3261E]">{d.errors} error(s)</span>
                                        : <span className="text-[#2D7D46]">clean</span>}
                                </td>
                                <td className="text-[11px] text-gray-400">{d.updated_at}</td>
                                <td className="text-right">
                                    <div className="flex items-center justify-end gap-2">
                                        <Link href={route('ea.diagrams.show', d.id)} className="text-[#0A1F44] underline">Open</Link>
                                        <RowActions canEdit={false} canDelete={perms.canDelete} onDelete={() => setDeleting(d)} />
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {diagrams.length === 0 && (
                            <tr><td colSpan={8} className="py-6 text-center text-sm text-gray-400">
                                No diagrams yet. Create one from a repository record and the canvas arrives populated.
                            </td></tr>
                        )}
                    </tbody>
                </table>
                </div>
            </div>

            {showNew && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="mb-1 font-bold text-[#0A1F44]">New diagram</h3>
                        <p className="mb-3 text-xs text-gray-500">
                            Optionally derive the canvas from a repository record and its neighbours — the first diagram
                            of a system should be generated and then curated, not drawn.
                        </p>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid grid-cols-2 gap-2">
                                <input
                                    className="rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                    placeholder="Code (e.g. DGM-010)"
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                />
                                <select
                                    className="rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                    value={form.data.viewpoint}
                                    onChange={(e) => form.setData('viewpoint', e.target.value)}
                                >
                                    <option value="custom">custom viewpoint</option>
                                    {viewpoints.map((v) => <option key={v} value={v}>{v}</option>)}
                                </select>
                            </div>
                            <input
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                placeholder="Name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            <textarea
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                placeholder="Description"
                                rows={2}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                            />

                            <label className="flex items-center gap-2 text-xs text-gray-600">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_topology}
                                    onChange={(e) => form.setData('is_topology', e.target.checked)}
                                />
                                This is a network topology diagram (CBN RBCF Appendix II §1.1(i))
                            </label>

                            <div className="rounded-lg border border-gray-100 bg-[#F7FAFC] p-3">
                                <h4 className="mb-2 text-xs font-semibold text-[#0A1F44]">Derive from the repository (optional)</h4>
                                <div className="grid grid-cols-3 gap-2">
                                    <select
                                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                        value={form.data.seed_entity_type}
                                        onChange={(e) => form.setData('seed_entity_type', e.target.value)}
                                    >
                                        <option value="">blank canvas</option>
                                        {startTypes.map((type) => (
                                            <option key={type.entity_type} value={type.entity_type}>{type.label}</option>
                                        ))}
                                    </select>
                                    <input
                                        type="number"
                                        min="1"
                                        placeholder="record id"
                                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                        value={form.data.seed_entity_id}
                                        onChange={(e) => form.setData('seed_entity_id', e.target.value)}
                                    />
                                    <select
                                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                        value={form.data.seed_depth}
                                        onChange={(e) => form.setData('seed_depth', e.target.value)}
                                    >
                                        {[1, 2, 3].map((n) => <option key={n} value={n}>{n} hop(s)</option>)}
                                    </select>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <button type="button" onClick={() => setShowNew(false)} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                                    Create
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete diagram ${deleting?.code}?`}
                body="The canvas and every saved version are removed. Generated viewpoints read from the repository and are unaffected."
                url={deleting ? route('ea.diagrams.destroy', deleting.id) : ''}
            />
        </AuthenticatedLayout>
    );
}
