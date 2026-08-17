import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { QuestionMarkCircleIcon } from '@heroicons/react/24/outline';

export default function Index() {
    return (
        <AuthenticatedLayout header="Question Library">
            <Head title="Question Library" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Question Library</h2>
                    <p className="text-sm text-[#718096] mt-1">Manage assessment questions used in risk evaluations.</p>
                </div>
            </div>

            {/* Empty State */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-12 text-center">
                    <QuestionMarkCircleIcon className="w-12 h-12 text-gray-300 mx-auto" />
                    <h3 className="text-sm font-medium text-[#2D3748] mt-4">No questions yet</h3>
                    <p className="text-sm text-[#718096] mt-1">Get started by creating your first assessment question.</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
