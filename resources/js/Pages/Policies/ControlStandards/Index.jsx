import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ShieldCheckIcon } from '@heroicons/react/24/outline';

export default function Index() {
    return (
        <AuthenticatedLayout header="Control Standards">
            <Head title="Control Standards" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Control Standards</h2>
                    <p className="text-sm text-[#718096] mt-1">Define and manage control standards that policies must adhere to.</p>
                </div>
            </div>

            {/* Empty State */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-12 text-center">
                    <ShieldCheckIcon className="w-12 h-12 text-gray-300 mx-auto" />
                    <h3 className="text-sm font-medium text-[#2D3748] mt-4">No control standards yet</h3>
                    <p className="text-sm text-[#718096] mt-1">Get started by creating your first control standard.</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
