import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { statusTone } from './Saq';

export default function PciMatrix({ requirements = [], labels = {} }) {
    return (
        <AuthenticatedLayout header="PCI Control Matrix">
            <Head title="PCI Control Matrix" />
            <PageHeader breadcrumbs={[{ label: 'PCI Management', href: route('pci.dashboard') }, { label: 'Control Matrix' }]}
                title="Requirement matrix"
                subtitle="Each PCI DSS v4.0.1 sub-requirement coloured by its result in the latest assessment, with the number of mapped controls." />
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {Object.entries(labels).map(([k, l]) => <span key={k} className={`rounded px-2 py-0.5 ${statusTone[k]}`}>{l}</span>)}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 space-y-2">
                {requirements.map((r) => (
                    <div key={r.code} className="grid grid-cols-1 items-start gap-2 md:grid-cols-[16rem_1fr]">
                        <p className="text-xs text-[#2D3748]"><span className="font-mono text-[#0A1F44]">Req {r.code}</span> {r.title}</p>
                        <div className="flex flex-wrap gap-1">
                            {r.subs.map((s) => (
                                <Link key={s.id} href={route('pci.saq')} title={`${s.code} ${s.title} — ${labels[s.status]}; ${s.controls.length} controls`}
                                    className={`min-w-[3.5rem] rounded px-2 py-1 text-center font-mono text-[11px] ${statusTone[s.status]}`}>
                                    {s.code}<span className="block text-[9px] opacity-80">{s.controls.length} ctl</span>
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
