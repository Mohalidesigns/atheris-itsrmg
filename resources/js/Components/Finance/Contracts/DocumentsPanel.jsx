import InputError from '@/Components/InputError';
import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize, localDate } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function DocumentsPanel({ contract, documents, options, can }) {
    const [show, setShow] = useState(false);
    const form = useForm({ kind: options.documentKinds[0]?.value ?? 'contract', title: '', file: null, signed_on: '', expires_on: '' });
    const submit = () => {
        form.transform((d) => ({ ...d, signed_on: d.signed_on || null, expires_on: d.expires_on || null }));
        form.post(route('finance.contracts.documents.store', contract.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } });
    };
    return (
        <Card title="Documents" right={can.addDocument && <AddButton onClick={() => setShow(true)}>Upload document</AddButton>}>
            <Table head={['Kind', 'Title', 'Signed', 'Expires', 'SHA-256', 'Uploaded', '']} empty={documents.length === 0 && <Empty>No documents recorded.</Empty>}>
                {documents.map((d) => (
                    <tr key={d.id}>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(d.kind)}</td>
                        <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{d.title}{d.file_name && <span className="block text-xs text-[#718096]">{d.file_name}</span>}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(d.signed_on)}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(d.expires_on)}</td>
                        <td className="px-4 py-2 font-mono text-xs" title={d.sha256 ?? undefined}>{d.sha256 ? `${d.sha256.slice(0, 10)}…` : '—'}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{d.uploaded_by?.name ?? '—'}</td>
                        <td className="px-4 py-2 text-right text-xs">{d.download_url ? <a className="text-[#0A1F44] underline" href={d.download_url}>Download</a> : <span className="text-[#A0A4AB]">No file</span>}</td>
                    </tr>
                ))}
            </Table>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.title} title="Upload document" submitLabel="Upload">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL} htmlFor="d-kind">Kind</label><select id="d-kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} className={FIELD}>{options.documentKinds.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={form.errors.kind} /></div>
                    <div><label className={LABEL} htmlFor="d-title">Title</label><input id="d-title" maxLength={200} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={FIELD} /><InputError message={form.errors.title} /></div>
                    <div><label className={LABEL} htmlFor="d-signed">Signed on</label><input id="d-signed" type="date" value={form.data.signed_on} onChange={(e) => form.setData('signed_on', e.target.value)} className={FIELD} /><InputError message={form.errors.signed_on} /></div>
                    <div><label className={LABEL} htmlFor="d-exp">Expires on</label><input id="d-exp" type="date" value={form.data.expires_on} onChange={(e) => form.setData('expires_on', e.target.value)} className={FIELD} /><InputError message={form.errors.expires_on} /></div>
                </div>
                <div><label className={LABEL} htmlFor="d-file">File (PDF, Office, image, up to 20 MB)</label><input id="d-file" type="file" onChange={(e) => form.setData('file', e.target.files[0] ?? null)} className={FIELD} /><InputError message={form.errors.file} /></div>
            </FormModal>
        </Card>
    );
}
