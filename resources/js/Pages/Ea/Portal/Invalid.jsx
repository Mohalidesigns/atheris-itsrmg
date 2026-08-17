import { Head } from '@inertiajs/react';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';

/**
 * Shown when a magic link is unrecognised or its campaign has closed.
 *
 * Says nothing about whether the token ever existed — an unrecognised link and
 * a deleted campaign read identically, so the page cannot be used to probe for
 * valid tokens.
 */
export default function Invalid({ reason }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-[#F7FAFC] px-4 py-8">
            <Head title="Link unavailable" />

            <div className="w-full max-w-lg rounded-xl border border-gray-100 bg-white p-8 text-center shadow-sm">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#E5A100]/10">
                    <ExclamationTriangleIcon className="h-7 w-7 text-[#E5A100]" />
                </div>
                <h1 className="text-lg font-semibold text-[#0A1F44]">This link is no longer available</h1>
                <p className="mt-2 text-sm text-[#718096]">{reason}</p>
            </div>
        </div>
    );
}
