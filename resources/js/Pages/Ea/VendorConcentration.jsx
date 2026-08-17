import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';

export default function VendorConcentration({ rows = [] }) {
    const fmt = (n) => '₦' + Number(n).toLocaleString();
    return (
        <AuthenticatedLayout header="Vendor Concentration">
            <Head title="Vendor Concentration" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Vendor Concentration' }]}
                title="Vendor Concentration"
                subtitle="Read-through from Vendor Management. Surface over-reliance on any one vendor across applications."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.vendor-concentration')} current="ea.vendor-concentration" />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Vendor</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Applications</th>
                            <th className="px-3 py-2">Critical apps</th>
                            <th className="px-3 py-2">Annual spend (₦)</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((r, i) => (
                            <tr key={i}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{r.vendor}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{r.category}</td>
                                <td className="px-3 py-2 text-xs">{r.apps}</td>
                                <td className="px-3 py-2 text-xs text-[#B3261E]">{r.critical_apps}</td>
                                <td className="px-3 py-2 text-xs font-semibold">{fmt(r.annual_spend_ngn)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
