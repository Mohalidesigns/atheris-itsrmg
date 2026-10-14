import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import KpiCard from '@/Components/KpiCard';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';
import { Pill } from '@/Components/Monitoring/format';

const assuranceTone = { assured: 'bg-[#D1FAE5] text-[#2D7D46]', partial: 'bg-[#FEF3C7] text-[#8A6400]', weak: 'bg-[#FEE2E2] text-[#C53030]', none: 'bg-gray-100 text-[#4A5568]' };
const label = { assured: 'assured', partial: 'partly assured', weak: 'weak', none: 'no controls' };

function Apps({ apps }) {
    if (apps.length === 0) return <p className="px-4 pb-4 text-xs text-[#718096]">No applications assigned.</p>;
    return (
        <table className="min-w-full divide-y divide-gray-100 text-sm">
            <thead className="bg-[#F7FAFC]"><tr className="text-left text-[11px] uppercase text-[#718096]"><th className="px-3 py-2">Application</th><th className="px-3 py-2">Controls</th><th className="px-3 py-2">Effective</th><th className="px-3 py-2">CCM</th><th className="px-3 py-2">Threat model</th><th className="px-3 py-2">Open drift</th><th className="px-3 py-2">Assurance</th></tr></thead>
            <tbody className="divide-y divide-gray-100">
                {apps.map((a) => (
                    <tr key={a.id}>
                        <td className="px-3 py-2"><p className="text-[#2D3748]">{a.name}</p><p className="font-mono text-[11px] text-[#718096]">{a.code} · {a.criticality}</p></td>
                        <td className="px-3 py-2 text-xs">{a.controls}{a.gaps > 0 && <span className="text-[#C53030]"> · {a.gaps} gap</span>}{a.unresolved > 0 && <span className="text-[#C53030]"> · {a.unresolved} unresolved</span>}</td>
                        <td className="px-3 py-2 text-xs">{a.controls ? `${a.effective}/${a.controls}` : '—'}</td>
                        <td className="px-3 py-2 text-xs">{a.ccm_monitored ? (a.ccm_fail ? <span className="text-[#C53030]">{a.ccm_fail} failing</span> : `${a.ccm_monitored} passing`) : <span className="text-[#A0AEC0]">not monitored</span>}</td>
                        <td className="px-3 py-2 text-xs">{a.threat_model ? 'yes' : <span className={['critical', 'high'].includes(a.criticality) ? 'text-[#C53030]' : 'text-[#A0AEC0]'}>none</span>}</td>
                        <td className={`px-3 py-2 text-xs ${a.drift ? 'text-[#C53030]' : 'text-[#A0AEC0]'}`}>{a.drift || '—'}</td>
                        <td className="px-3 py-2"><Pill value={a.assurance} tones={assuranceTone} label={label[a.assurance]} /></td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function SecurityAssurance({ zones = [], unzoned = [], summary = {} }) {
    return (
        <AuthenticatedLayout header="Security Architecture & Assurance">
            <Head title="Security Assurance" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Security Architecture & Assurance' }, { label: 'Assurance' }]}
                title="Security Assurance"
                subtitle="For each trust zone and its applications: the bank's controls mapped to them, whether those controls are rated effective, what continuous monitoring says, threat-model coverage and open configuration drift. Assured means every mapped control is effective and monitored, with nothing failing."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.security-assurance')} current="ea.security-assurance" />
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-6">
                <KpiCard label="Applications" value={summary.applications ?? 0} tone="navy" />
                <KpiCard label="In a zone" value={`${summary.zoned ?? 0}`} tone="white" />
                <KpiCard label="With controls" value={summary.with_controls ?? 0} tone="white" />
                <KpiCard label="Assured" value={summary.assured ?? 0} tone="green" />
                <KpiCard label="Weak" value={summary.weak ?? 0} tone={summary.weak ? 'red' : 'white'} />
                <KpiCard label="Critical, no threat model" value={summary.critical_without_threat_model ?? 0} tone={summary.critical_without_threat_model ? 'amber' : 'white'} />
            </div>
            <div className="space-y-4">
                {zones.map((z) => (
                    <div key={z.id} className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                        <div className="flex items-center justify-between p-4"><div><p className="font-mono text-xs text-[#C9A86A]">{z.code}</p><h3 className="text-sm font-semibold text-[#0A1F44]">{z.name}</h3></div><span className="text-xs text-[#718096]">trust level {z.trust_level} · {z.apps.length} application{z.apps.length === 1 ? '' : 's'}</span></div>
                        <Apps apps={z.apps} />
                    </div>
                ))}
                {unzoned.length > 0 && (
                    <div className="overflow-x-auto rounded-xl border border-[#FEB2B2] bg-white shadow-sm">
                        <div className="p-4"><h3 className="text-sm font-semibold text-[#C53030]">Not assigned to a zone</h3><p className="text-xs text-[#718096]">These applications have no trust boundary in the architecture.</p></div>
                        <Apps apps={unzoned} />
                    </div>
                )}
                {zones.length === 0 && unzoned.length === 0 && <p className="text-sm text-[#718096]">No applications or zones in the repository yet.</p>}
            </div>
        </AuthenticatedLayout>
    );
}
