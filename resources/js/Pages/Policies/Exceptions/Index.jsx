import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ExclamationCircleIcon } from '@heroicons/react/24/outline';

export default function Index() {
    return (
        <AuthenticatedLayout header="Policy Exceptions">
            <Head title="Policy Exceptions" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Policy Exceptions</h2>
                    <p className="text-sm text-[#718096] mt-1">Track and manage approved policy exceptions and waivers.</p>
                </div>
            </div>

            {/* Empty State */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-12 text-center">
                    <ExclamationCircleIcon className="w-12 h-12 text-gray-300 mx-auto" />
                    <h3 className="text-sm font-medium text-[#2D3748] mt-4">No policy exceptions yet</h3>
                    <p className="text-sm text-[#718096] mt-1">Get started by creating your first policy exception.</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
