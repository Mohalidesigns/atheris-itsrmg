import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';

export default function Motivation({ goals = [], drivers = [], stakeholders = [], coa = [] }) {
    const form = useForm({ kind: 'goal', code: '', name: '', description: '', priority: 'medium' });
    const submit = (e) => { e.preventDefault(); form.post(route('ea.motivation.store'), { onSuccess: () => form.reset() }); };

    return (
        <AuthenticatedLayout header="ArchiMate Motivation Layer">
            <Head title="Motivation Layer" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Strategy' }, { label: 'Motivation' }]}
                title="Goals, Drivers, Stakeholders & Outcomes"
                subtitle="ArchiMate 3.2 Motivation layer — links strategic drivers and stakeholders to architecture initiatives."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.motivation')} current="ea.motivation" />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Goals" value={goals.length} tone="navy" />
                <KpiCard label="Drivers" value={drivers.length} tone="gold" />
                <KpiCard label="Stakeholders" value={stakeholders.length} tone="white" />
                <KpiCard label="Courses of action" value={coa.length} tone="white" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <Section title="Strategic Goals">
                    {goals.map((g) => (
                        <div key={g.id} className="border-b border-gray-50 py-2">
                            <div className="flex items-center gap-2">
                                <span className="font-mono text-xs text-[#0A1F44]">{g.code}</span>
                                <span className="font-medium">{g.name}</span>
                                <StatusBadge status={g.priority === 'high' ? 'critical' : 'moderate'} label={g.priority} />
                            </div>
                            <p className="text-xs text-gray-600">{g.description}</p>
                            <div className="text-xs text-gray-500">Horizon: {g.horizon} · Outcomes: {g.outcomes?.length || 0} · Progress: {g.progress_percent}%</div>
                        </div>
                    ))}
                    {goals.length === 0 && <p className="text-sm text-gray-400">No goals yet.</p>}
                </Section>

                <Section title="Drivers">
                    {drivers.map((d) => (
                        <div key={d.id} className="border-b border-gray-50 py-2 text-sm">
                            <div className="flex items-center gap-2">
                                <span className="font-mono text-xs text-[#0A1F44]">{d.code}</span>
                                <span className="font-medium">{d.name}</span>
                                <StatusBadge status={d.origin === 'regulatory' ? 'critical' : 'moderate'} label={d.origin} />
                            </div>
                            <p className="text-xs text-gray-600">{d.description}</p>
                        </div>
                    ))}
                    {drivers.length === 0 && <p className="text-sm text-gray-400">No drivers yet.</p>}
                </Section>

                <Section title="Stakeholders">
                    {stakeholders.map((s) => (
                        <div key={s.id} className="border-b border-gray-50 py-2 text-sm flex items-center gap-2">
                            <span className="font-mono text-xs text-[#0A1F44]">{s.code}</span>
                            <span className="font-medium">{s.name}</span>
                            <span className="text-xs text-gray-500">{s.role}</span>
                            <StatusBadge status={s.influence === 'high' ? 'critical' : 'moderate'} label={`I:${s.influence}/${s.interest}`} />
                        </div>
                    ))}
                    {stakeholders.length === 0 && <p className="text-sm text-gray-400">No stakeholders yet.</p>}
                </Section>

                <Section title="Courses of Action">
                    {coa.map((c) => (
                        <div key={c.id} className="border-b border-gray-50 py-2 text-sm">
                            <div className="flex items-center gap-2">
                                <span className="font-mono text-xs text-[#0A1F44]">{c.code}</span>
                                <span className="font-medium">{c.name}</span>
                                <StatusBadge status="moderate" label={c.status} />
                            </div>
                            {c.initiative && <div className="text-xs text-gray-500">Initiative: {c.initiative.name}</div>}
                        </div>
                    ))}
                    {coa.length === 0 && <p className="text-sm text-gray-400">No courses of action yet.</p>}
                </Section>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="font-bold text-[#0A1F44] mb-3">Add motivation element</h3>
                <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-5 gap-2 text-sm">
                    <select className="border border-gray-200 rounded-lg px-3 py-2" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                        <option value="goal">goal</option><option value="driver">driver</option><option value="stakeholder">stakeholder</option><option value="outcome">outcome</option><option value="course-of-action">course-of-action</option>
                    </select>
                    <input className="border border-gray-200 rounded-lg px-3 py-2" placeholder="Code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                    <input className="border border-gray-200 rounded-lg px-3 py-2" placeholder="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <input className="border border-gray-200 rounded-lg px-3 py-2" placeholder="Description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    <button type="submit" disabled={form.processing} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white">Add</button>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <h3 className="font-bold text-[#0A1F44] mb-3">{title}</h3>
            <div className="space-y-1">{children}</div>
        </div>
    );
}
