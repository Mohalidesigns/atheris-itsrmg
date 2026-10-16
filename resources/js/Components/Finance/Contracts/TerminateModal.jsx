import InputError from '@/Components/InputError';
import Icon from '@/Components/Material/Icon';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

export default function TerminateModal({ show, onClose, contract, documents = [], needsOverride = false }) {
    const form = useForm({ reason: '', terminated_on: '', override_reason: '', document_id: '' });
    const submit = () => {
        form.transform((d) => ({
            ...d,
            terminated_on: d.terminated_on || null,
            override_reason: needsOverride ? d.override_reason : null,
            document_id: needsOverride ? d.document_id || null : null,
        }));
        form.post(route('finance.contracts.terminate', contract.id), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    const missing = !form.data.reason.trim() || (needsOverride && (!form.data.override_reason.trim() || !form.data.document_id));
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || missing} title={`Request termination of ${contract.contract_code}`} submitLabel="Request termination">
            <p className="text-sm text-[#4A5568]">This submits a termination request for approval. The contract stays in force until the request is approved.</p>
            {needsOverride && (
                <div role="alert" className="flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-3 py-2 text-sm text-[#5F4300]">
                    <Icon name="warning" size={18} className="mt-0.5" />
                    <span>The notice deadline has passed on this auto-renewing contract. An override reason and a supporting document are required.</span>
                </div>
            )}
            <div><label className={LABEL} htmlFor="t-reason">Reason</label><textarea id="t-reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} /><InputError message={form.errors.reason} /></div>
            <div><label className={LABEL} htmlFor="t-on">Terminated on (defaults to today)</label><input id="t-on" type="date" value={form.data.terminated_on} onChange={(e) => form.setData('terminated_on', e.target.value)} className={FIELD} /><InputError message={form.errors.terminated_on} /></div>
            {needsOverride && (
                <>
                    <div><label className={LABEL} htmlFor="t-override">Override reason (required)</label><textarea id="t-override" rows={3} value={form.data.override_reason} onChange={(e) => form.setData('override_reason', e.target.value)} className={FIELD} /><InputError message={form.errors.override_reason} /></div>
                    <div><label className={LABEL} htmlFor="t-doc">Supporting document (required)</label>
                        <select id="t-doc" value={form.data.document_id} onChange={(e) => form.setData('document_id', e.target.value)} className={FIELD}>
                            <option value="">Select a document</option>
                            {documents.map((d) => <option key={d.id} value={d.id}>{d.title}</option>)}
                        </select>
                        {documents.length === 0 && <p className="mt-1 text-[11px] text-[#718096]">Upload a document on the Documents tab first.</p>}
                        <InputError message={form.errors.document_id} /></div>
                </>
            )}
            <InputError message={form.errors.workflow} />
        </FormModal>
    );
}
