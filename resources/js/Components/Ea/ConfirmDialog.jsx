import { router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import DangerButton from '@/Components/DangerButton';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';

/**
 * ConfirmDialog — destructive and irreversible EA actions.
 *
 * `blockers` is the dependency guard required by Appendix A ("Delete with
 * dependency guard", "Delete with descendant guard", "Delete with usage
 * guard"). When a caller supplies blockers the action is refused outright and
 * the reasons are shown, rather than allowing a delete that orphans the graph.
 */
export default function ConfirmDialog({
    show,
    onClose,
    title = 'Are you sure?',
    body,
    blockers = [],
    method = 'delete',
    url,
    confirmLabel = 'Delete',
    tone = 'danger',
}) {
    const [processing, setProcessing] = useState(false);
    const blocked = blockers.length > 0;
    const Button = tone === 'danger' ? DangerButton : PrimaryButton;

    const go = () => {
        setProcessing(true);
        router[method](url, {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="px-6 py-5">
                <h2 className="text-base font-semibold text-[#0A1F44]">{title}</h2>
                {body && <p className="mt-2 text-sm text-[#2D3748]">{body}</p>}

                {blocked && (
                    <div className="mt-4 rounded-lg border border-[#B3261E]/30 bg-[#B3261E]/5 p-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[#B3261E]">
                            Blocked — resolve these dependencies first
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-4 text-xs text-[#2D3748]">
                            {blockers.map((b, i) => (
                                <li key={i}>{b}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
            <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                <SecondaryButton onClick={onClose} disabled={processing}>
                    {blocked ? 'Close' : 'Cancel'}
                </SecondaryButton>
                {!blocked && (
                    <Button onClick={go} disabled={processing}>
                        {processing ? 'Working…' : confirmLabel}
                    </Button>
                )}
            </div>
        </Modal>
    );
}
