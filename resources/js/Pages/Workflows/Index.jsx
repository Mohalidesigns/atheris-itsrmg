import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { Head, Link } from '@inertiajs/react';

const engineNote = { change: 'Change Management engine', csat: 'CSAT sign-off engine', generic: null };

export default function WorkflowsIndex({ processes = {}, summary = {} }) {
    return (
        <AuthenticatedLayout header="Workflow Studio">
            <Head title="Approval Workflows" />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio' }, { label: 'Approval Workflows' }]}
                title="Approval Workflows"
                subtitle="Every process in the platform that needs an approval. For each, set who may start it and the stages that approve it: who, in what order, how many, and for which records. Stages apply to approvals started after you save them."
                actions={<Link href={route('workflows.inbox')} className="inline-flex items-center gap-1 rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white"><Icon name="inbox" size={18} /> Approval inbox</Link>}
            />
            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
                <KpiCard label="Processes" value={summary.processes ?? 0} tone="navy" />
                <KpiCard label="With configured stages" value={summary.configured ?? 0} tone="white" />
                <KpiCard label="Awaiting a decision" value={summary.pending ?? 0} tone={summary.pending ? 'gold' : 'white'} />
            </div>
            <div className="space-y-5">
                {Object.entries(processes).map(([module, list]) => (
                    <section key={module}>
                        <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-[#718096]">{module}</h2>
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                            {list.map((p) => (
                                <Link key={p.key} href={route('workflows.process', p.key)} className="block rounded-xl border border-gray-100 bg-white p-4 shadow-sm hover:border-[#0A1F44]/30">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 className="text-sm font-semibold text-[#0A1F44]">{p.label}</h3>
                                            <p className="mt-0.5 text-xs text-[#718096]">{p.description}</p>
                                        </div>
                                        <Icon name="chevron_right" size={20} className="text-[#A0AEC0]" />
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        {p.configured
                                            ? <Chip tone="success" icon="account_tree">{p.stages} stage{p.stages === 1 ? '' : 's'}</Chip>
                                            : <Chip tone="muted" icon="person">Default: single approver</Chip>}
                                        {p.initiators.length > 0 && <Chip tone="info" icon="send" variant="outlined">Started by {p.initiators.join(', ')}</Chip>}
                                        {p.pending > 0 && <Chip tone="warning" icon="hourglass_top">{p.pending} awaiting</Chip>}
                                        {engineNote[p.engine] && <span className="text-[11px] text-[#A0AEC0]">{engineNote[p.engine]}</span>}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
