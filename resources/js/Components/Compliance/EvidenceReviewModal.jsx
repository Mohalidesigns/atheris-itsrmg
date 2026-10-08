import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { EVIDENCE_TYPES, formatDate } from '@/Utils/compliance';

/** Approve or reject an evidence item; a rejection needs a reason. */
export default function EvidenceReviewModal({ evidence, onClose }) {
    const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({ decision: 'approved', review_notes: '' });

    useEffect(() => {
        reset();
        clearErrors();
    }, [evidence?.id]);

    const submit = (e) => {
        e.preventDefault();
        patch(route('evidence.review', evidence.id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal show={!!evidence} onClose={onClose} maxWidth="lg">
            {evidence && (
                <form onSubmit={submit} className="p-6 space-y-4">
                    <div>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Review evidence</h3>
                        <p className="text-sm text-[#2D3748] mt-1 font-medium">{evidence.title}</p>
                        <p className="text-xs text-[#718096]">
                            {EVIDENCE_TYPES[evidence.type] || evidence.type} · uploaded by {evidence.uploader?.name || '—'} on {formatDate(evidence.created_at)}
                            {evidence.valid_until ? ` · valid until ${formatDate(evidence.valid_until)}` : ''}
                        </p>
                        {evidence.description && <p className="text-xs text-[#718096] mt-2">{evidence.description}</p>}
                        {(evidence.has_file || evidence.file_path || evidence.url) && (
                            <a href={route('evidence.download', evidence.id)} target="_blank" rel="noreferrer" className="inline-block mt-2 text-xs text-[#1A365D] underline">
                                {evidence.type === 'url' ? 'Open link' : `Download ${evidence.file_name || 'file'}`}
                            </a>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {[['approved', 'Approve', 'border-green-300 bg-green-50 text-green-800'], ['rejected', 'Reject', 'border-red-300 bg-red-50 text-red-700']].map(([k, label, on]) => (
                            <button key={k} type="button" onClick={() => setData('decision', k)}
                                className={`flex-1 py-2 text-sm rounded-lg border ${data.decision === k ? on : 'border-gray-200 text-[#718096]'}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                    <InputError message={errors.decision} />
                    <div>
                        <InputLabel value={data.decision === 'rejected' ? 'Reason for rejection *' : 'Review notes'} />
                        <textarea value={data.review_notes} onChange={(e) => setData('review_notes', e.target.value)} rows={3}
                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm" />
                        <InputError message={errors.review_notes} className="mt-1" />
                    </div>
                    <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={processing}>Record decision</PrimaryButton>
                    </div>
                </form>
            )}
        </Modal>
    );
}
