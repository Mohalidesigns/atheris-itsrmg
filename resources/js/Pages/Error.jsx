import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldExclamationIcon } from '@heroicons/react/24/outline';

const titles = {
    403: 'Access Denied',
    404: 'Page Not Found',
    500: 'Server Error',
    503: 'Service Unavailable',
};

export default function ErrorPage({ status = 403, message }) {
    const { auth } = usePage().props;
    const title = titles[status] || 'Something went wrong';

    const content = (
        <div className="flex items-center justify-center min-h-[60vh]">
            <div className="max-w-md w-full text-center bg-white rounded-xl border border-gray-100 shadow-sm p-10">
                <div className="mx-auto w-14 h-14 rounded-full bg-[#B3261E]/10 flex items-center justify-center">
                    <ShieldExclamationIcon className="w-8 h-8 text-[#B3261E]" />
                </div>
                <p className="mt-4 text-xs font-mono font-semibold text-[#718096] uppercase tracking-widest">
                    Error {status}
                </p>
                <h1 className="mt-1 text-xl font-bold text-[#2D3748]">{title}</h1>
                <p className="mt-2 text-sm text-[#718096]">
                    {message || 'You do not have permission to access this page. Contact your administrator if you believe this is a mistake.'}
                </p>
                <div className="mt-6 flex items-center justify-center gap-3">
                    <button
                        onClick={() => window.history.back()}
                        className="px-4 py-2 text-sm text-[#718096] border border-gray-200 rounded-lg hover:bg-gray-50"
                    >
                        Go Back
                    </button>
                    <Link
                        href={route('dashboard')}
                        className="px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]"
                    >
                        Go to Dashboard
                    </Link>
                </div>
            </div>
        </div>
    );

    if (!auth?.user) {
        return (
            <div className="min-h-screen bg-[#F7FAFC] flex items-center justify-center px-4">
                <Head title={title} />
                {content}
            </div>
        );
    }

    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />
            {content}
        </AuthenticatedLayout>
    );
}
