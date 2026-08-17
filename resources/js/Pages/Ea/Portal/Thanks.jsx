import { Head } from '@inertiajs/react';
import { CheckCircleIcon } from '@heroicons/react/24/outline';

/**
 * Confirmation after a magic-link submission.
 *
 * Shows what the answer actually changed. §9 WS 1.2's exit criterion requires
 * responses to "visibly change seal state and completeness score" — a
 * respondent who can see their two minutes moved something is measurably more
 * likely to answer the next campaign, which is the whole of R5's mitigation.
 */
export default function Thanks({
    entity,
    alreadyDone = false,
    declined = false,
    updated = [],
    sealState,
    completeness,
    submittedAt,
}) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-[#F7FAFC] px-4 py-8">
            <Head title="Thank you" />

            <div className="w-full max-w-lg rounded-xl border border-gray-100 bg-white p-8 text-center shadow-sm">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#2D7D46]/10">
                    <CheckCircleIcon className="h-7 w-7 text-[#2D7D46]" />
                </div>

                {declined ? (
                    <>
                        <h1 className="text-lg font-semibold text-[#0A1F44]">Thank you — we will re-route it</h1>
                        <p className="mt-2 text-sm text-[#718096]">
                            We have recorded that <span className="font-medium text-[#2D3748]">{entity}</span> is not
                            yours. The architecture team will find the right owner.
                        </p>
                    </>
                ) : alreadyDone ? (
                    <>
                        <h1 className="text-lg font-semibold text-[#0A1F44]">You have already answered this one</h1>
                        <p className="mt-2 text-sm text-[#718096]">
                            Your response for <span className="font-medium text-[#2D3748]">{entity}</span> was
                            recorded{submittedAt ? ` on ${submittedAt}` : ''}. Nothing further is needed.
                        </p>
                    </>
                ) : (
                    <>
                        <h1 className="text-lg font-semibold text-[#0A1F44]">Thank you</h1>
                        <p className="mt-2 text-sm text-[#718096]">
                            Your answers have been applied to{' '}
                            <span className="font-medium text-[#2D3748]">{entity}</span>.
                        </p>

                        {updated.length > 0 && (
                            <div className="mt-4 rounded-lg bg-[#F7FAFC] p-3 text-left">
                                <p className="text-xs font-semibold uppercase tracking-wide text-[#718096]">
                                    Updated
                                </p>
                                <p className="mt-1 text-sm text-[#2D3748]">{updated.join(', ')}</p>
                            </div>
                        )}

                        {(sealState || completeness !== undefined) && (
                            <p className="mt-4 text-xs text-[#718096]">
                                This record is now{' '}
                                <span className="font-medium text-[#2D3748]">{completeness}% complete</span>
                                {sealState ? ` and its quality seal reads “${sealState}”.` : '.'}
                            </p>
                        )}
                    </>
                )}

                <p className="mt-6 text-[11px] text-[#718096]">
                    You can close this window. The link will not work again.
                </p>
            </div>
        </div>
    );
}
