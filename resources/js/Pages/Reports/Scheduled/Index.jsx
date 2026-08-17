import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ClockIcon } from '@heroicons/react/24/outline';

export default function Index() {
    return (
        <AuthenticatedLayout header="Scheduled Reports">
            <Head title="Scheduled Reports" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Scheduled Reports</h2>
                    <p className="text-sm text-[#718096] mt-1">Configure and manage automated report generation and distribution.</p>
                </div>
            </div>

            {/* Empty State */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-12 text-center">
                    <ClockIcon className="w-12 h-12 text-gray-300 mx-auto" />
                    <h3 className="text-sm font-medium text-[#2D3748] mt-4">No scheduled reports yet</h3>
                    <p className="text-sm text-[#718096] mt-1">Get started by configuring your first scheduled report.</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
