import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { EVIDENCE_TYPES } from '@/Utils/compliance';

const select = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const FILE_TYPES = ['document', 'screenshot', 'log'];

/**
 * Upload evidence against a control, gap or assessment result.
 * Pass `subject` ({ type, id, label }) to attach to a fixed item, or `subjectOptions`
 * ({ control: [{id,label}], gap: [...] }) to let the user choose.
 */
export default function EvidenceUploadModal({ show, onClose, subject = null, subjectOptions = null }) {
    const blank = {
        title: '', description: '', type: 'document', url: '', file: null,
        subject: subject?.type || 'control', subject_id: subject?.id || '',
        valid_from: '', valid_until: '',
    };
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm(blank);

    useEffect(() => {
        if (show) {
            reset();
            clearErrors();
            setData((d) => ({ ...d, subject: subject?.type || 'control', subject_id: subject?.id || '' }));
        }
    }, [show, subject?.type, subject?.id]);

    const submit = (e) => {
        e.preventDefault();
        post(route('evidence.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const needsFile = FILE_TYPES.includes(data.type);
    const options = subjectOptions?.[data.subject] || [];

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <form onSubmit={submit} className="p-6 space-y-4">
                <div>
                    <h3 className="text-lg font-semibold text-[#2D3748]">Upload evidence</h3>
                    <p className="text-xs text-[#718096] mt-0.5">
                        {subject ? <>Attaching to <span className="font-medium text-[#2D3748]">{subject.label}</span>. </> : null}
                        New evidence is pending until a reviewer (not the uploader) approves it.
                    </p>
                </div>

                {!subject && subjectOptions && (
                    <div className="grid grid-cols-3 gap-3">
                        <div>
                            <InputLabel value="Attach to" />
                            <select value={data.subject} onChange={(e) => setData((d) => ({ ...d, subject: e.target.value, subject_id: '' }))} className={select}>
                                <option value="control">Control</option>
                                <option value="gap">Open gap</option>
                            </select>
                        </div>
                        <div className="col-span-2">
                            <InputLabel value={data.subject === 'gap' ? 'Gap *' : 'Control *'} />
                            <select value={data.subject_id} onChange={(e) => setData('subject_id', e.target.value)} className={select}>
                                <option value="">Select…</option>
                                {options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                            </select>
                            <InputError message={errors.subject_id || errors.subject} className="mt-1" />
                        </div>
                    </div>
                )}

                <div>
                    <InputLabel value="Title *" />
                    <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} className="mt-1 block w-full" placeholder="e.g. Q3 privileged-access review sign-off" />
                    <InputError message={errors.title} className="mt-1" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel value="Type *" />
                        <select value={data.type} onChange={(e) => setData('type', e.target.value)} className={select}>
                            {Object.entries(EVIDENCE_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </select>
                        <InputError message={errors.type} className="mt-1" />
                    </div>
                    {data.type === 'url' ? (
                        <div>
                            <InputLabel value="Link *" />
                            <TextInput type="url" value={data.url} onChange={(e) => setData('url', e.target.value)} className="mt-1 block w-full" placeholder="https://…" />
                            <InputError message={errors.url} className="mt-1" />
                        </div>
                    ) : (
                        <div>
                            <InputLabel value={needsFile ? 'File *' : 'File (optional)'} />
                            <input type="file" onChange={(e) => setData('file', e.target.files[0] || null)}
                                className="mt-1 block w-full text-xs text-[#718096] file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-[#1A365D]/5 file:text-[#1A365D]" />
                            <p className="text-[10px] text-[#A0AEC0] mt-0.5">PDF, Office, image, text/log, ZIP · max 20 MB</p>
                            <InputError message={errors.file} className="mt-1" />
                        </div>
                    )}
                </div>

                <div>
                    <InputLabel value="Description" />
                    <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} className={select} placeholder="What does this show, for which period?" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel value="Valid from" />
                        <input type="date" value={data.valid_from} onChange={(e) => setData('valid_from', e.target.value)} className={select} />
                    </div>
                    <div>
                        <InputLabel value="Valid until" />
                        <input type="date" value={data.valid_until} onChange={(e) => setData('valid_until', e.target.value)} className={select} />
                        <InputError message={errors.valid_until} className="mt-1" />
                    </div>
                </div>

                <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>{processing ? 'Uploading…' : 'Upload evidence'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
