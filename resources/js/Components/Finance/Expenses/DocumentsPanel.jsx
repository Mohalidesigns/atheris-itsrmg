import InputError from '@/Components/InputError';
import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize, localDate } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/** Documents for an expense / PO / goods receipt / invoice; uploads to finance.documents.store. */
export default function DocumentsPanel({ subject, subjectId, documents, kinds, canAttach, defaultKind }) {
    const [show, setShow] = useState(false);
    const form = useForm({ subject, subject_id: subjectId, kind: defaultKind ?? kinds[0]?.value ?? 'other', title: '', file: null });
    const submit = () => form.post(route('finance.documents.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset('title', 'file'); setShow(false); } });
    return (
        <Card title="Documents" right={canAttach && <AddButton onClick={() => setShow(true)}>Upload document</AddButton>}>
            <Table head={['Kind', 'Title', 'SHA-256', 'Uploaded by', 'Date', '']} empty={documents.length === 0 && <Empty>No documents attached.</Empty>}>
                {documents.map((d) => (
                    <tr key={d.id}>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(d.kind)}</td>
                        <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{d.title}{d.file_name && <span className="block text-xs text-[#718096]">{d.file_name}</span>}</td>
                        <td className="px-4 py-2 font-mono text-xs" title={d.sha256 ?? undefined}>{d.sha256 ? `${d.sha256.slice(0, 10)}…` : '—'}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{d.uploaded_by?.name ?? '—'}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(d.created_at)}</td>
                        <td className="px-4 py-2 text-right text-xs">{d.download_url ? <a className="text-[#0A1F44] underline" href={d.download_url}>Download</a> : '—'}</td>
                    </tr>
                ))}
            </Table>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.title || !form.data.file} title="Upload document" submitLabel="Upload">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL} htmlFor="fd-kind">Kind</label><select id="fd-kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} className={FIELD}>{kinds.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={form.errors.kind} /></div>
                    <div><label className={LABEL} htmlFor="fd-title">Title</label><input id="fd-title" maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={FIELD} /><InputError message={form.errors.title} /></div>
                </div>
                <div><label className={LABEL} htmlFor="fd-file">File</label><input id="fd-file" type="file" onChange={(e) => form.setData('file', e.target.files[0] ?? null)} className={FIELD} /><InputError message={form.errors.file} /></div>
                <InputError message={form.errors.subject_id} />
            </FormModal>
        </Card>
    );
}
