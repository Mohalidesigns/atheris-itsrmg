import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { fmtDateTime, Pill } from '@/Components/Monitoring/format';

const snapTone = { ok: ['success', 'check_circle'], findings: ['warning', 'warning'], failed: ['error', 'cancel'] };

export default function CoreBankingSnapshots({ snapshots = [], integrations = [], filters = {} }) {
    return (
        <AuthenticatedLayout header="Core Banking Snapshots">
            <Head title="Core Banking Snapshots" />
            <PageHeader
                breadcrumbs={[{ label: 'Core Banking', href: route('core-banking.index') }, { label: 'Snapshots' }]}
                title="Core Banking Snapshots"
                subtitle="Technical metadata snapshots captured on schedule. No customer data, by design."
            />
            <div className="mb-3">
                <select value={filters.integration || ''} onChange={(e) => router.get(route('core-banking.snapshots'), e.target.value ? { integration: e.target.value } : {}, { preserveState: true })} className="rounded-lg border-gray-300 text-xs">
                    <option value="">All integrations</option>
                    {integrations.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                </select>
            </div>
            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Integration</th>
                            <th className="px-3 py-2">Captured</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Findings</th>
                            <th className="px-3 py-2">Embargoed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {snapshots.map((s) => (
                            <tr key={s.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">
                                    {s.integration ? <Link href={route('core-banking.show', s.integration_id)} className="hover:underline">{s.integration.name}</Link> : s.product}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{fmtDateTime(s.captured_at)}</td>
                                <td className="px-3 py-2"><Pill value={s.status} tones={snapTone} /></td>
                                <td className="px-3 py-2 text-xs text-[#2D3748]">{(s.findings || []).length}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{(s.embargoed_keys || []).join(', ') || '—'}</td>
                            </tr>
                        ))}
                        {snapshots.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-xs text-[#718096]">No snapshots captured yet.</td></tr>}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
