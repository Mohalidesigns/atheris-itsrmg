import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';

const RULE_NAMES = {
    'EA-ANO-01': 'Applications without owner',
    'EA-ANO-02': 'Capabilities without supporting application',
    'EA-ANO-03': 'Active interfaces to retired apps',
    'EA-ANO-04': 'Tech EOL ≤12m without replacement',
    'EA-ANO-05': 'PII domain without DPO',
    'EA-ANO-06': 'Cross-border flow without DPIA',
    'EA-ANO-07': 'API missing contract',
};

export default function Anomalies({ findings = [], byStatus = {}, bySeverity = {}, byRule = {} }) {
    const run = () => router.post(route('ea.anomalies.run'));

    return (
        <AuthenticatedLayout header="Anomaly Inbox">
            <Head title="Anomaly Inbox" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Operations' }, { label: 'Anomalies' }]}
                title="Architecture Anomaly Inbox"
                subtitle="Seven rule-based detectors run nightly to surface hygiene gaps in the EA repository (ATH-GAP-EA-001 §5.12)."
                actions={<button onClick={run} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Run rules now</button>}
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.anomalies')} current="ea.anomalies" />

            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Open" value={byStatus.open || 0} tone="red" />
                <KpiCard label="Acknowledged" value={byStatus.acknowledged || 0} tone="amber" />
                <KpiCard label="Resolved" value={byStatus.resolved || 0} tone="green" />
                <KpiCard label="Critical sev" value={bySeverity.critical || 0} tone="red" />
                <KpiCard label="Total" value={findings.length} tone="navy" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-[280px,1fr] gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="font-bold text-[#0A1F44] mb-3">By rule</h3>
                    <ul className="space-y-1 text-sm">
                        {Object.entries(RULE_NAMES).map(([code, name]) => (
                            <li key={code} className="flex items-center justify-between border-b border-gray-50 py-1">
                                <span><span className="font-mono text-xs text-[#C9A86A]">{code}</span> {name}</span>
                                <span className="font-bold">{byRule[code] || 0}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="font-bold text-[#0A1F44] mb-3">Findings</h3>
                    <table className="w-full text-sm">
                        <thead className="text-xs text-gray-500 border-b border-gray-100">
                            <tr><th className="text-left py-2">Rule</th><th className="text-left">Title</th><th className="text-left">Severity</th><th className="text-left">Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            {findings.map((f) => (
                                <tr key={f.id} className="border-b border-gray-50">
                                    <td className="py-2 font-mono text-xs">{f.rule_code}</td>
                                    <td>{f.title}<div className="text-xs text-gray-500">{f.detail}</div></td>
                                    <td><StatusBadge status={f.severity === 'critical' ? 'critical' : f.severity === 'high' ? 'high' : 'moderate'} label={f.severity} /></td>
                                    <td><StatusBadge status={f.status === 'resolved' ? 'compliant' : f.status === 'acknowledged' ? 'in_progress' : 'critical'} label={f.status} /></td>
                                    <td className="text-right whitespace-nowrap">
                                        {f.status === 'open' && <button onClick={() => router.post(route('ea.anomalies.ack', f.id))} className="text-xs text-[#0A1F44] underline mr-2">Ack</button>}
                                        {f.status !== 'resolved' && <button onClick={() => router.post(route('ea.anomalies.resolve', f.id))} className="text-xs text-green-700 underline">Resolve</button>}
                                    </td>
                                </tr>
                            ))}
                            {findings.length === 0 && <tr><td colSpan={5} className="py-6 text-center text-gray-400 text-sm">No anomalies. Run the rules to populate.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
