import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * IAS 38.57 / IAS 16.7 checklist: each criterion is ticked with a note of at least `noteMin` characters and can point at an uploaded document.
 * Saves through the capitalisation update route, so `basePayload` carries every other form field unchanged.
 */
export default function ChecklistPanel({ items, documents = [], editable, basePayload, updateUrl, noteMin = 10, errors = {} }) {
    const [rows, setRows] = useState(items);
    const [busy, setBusy] = useState(false);
    useEffect(() => setRows(items), [items]);
    const set = (key, patch) => setRows(rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));
    const complete = rows.every((r) => r.met && (r.note ?? '').trim().length >= noteMin);
    const dirty = JSON.stringify(rows) !== JSON.stringify(items);
    const save = () => router.put(updateUrl, { ...basePayload, criteria_checklist: rows.map((r) => ({ key: r.key, met: !!r.met, note: r.note || null, document_id: r.document_id || null })) }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const err = Object.entries(errors).find(([k]) => k.startsWith('criteria_checklist'))?.[1];
    return (
        <Card title="Capitalisation criteria" right={<Chip size="xs" tone={complete ? 'success' : 'warning'}>{complete ? 'Complete' : `${rows.filter((r) => r.met && (r.note ?? '').trim().length >= noteMin).length} of ${rows.length} evidenced`}</Chip>}>
            <ul className="divide-y divide-gray-50">
                {rows.map((r) => (
                    <li key={r.key} className="space-y-2 px-5 py-3">
                        <label className="flex items-start gap-2 text-sm text-[#2D3748]"><input type="checkbox" disabled={!editable} checked={!!r.met} onChange={(e) => set(r.key, { met: e.target.checked })} className="mt-1 rounded border-gray-300" /><span>{r.label}</span></label>
                        <div className="grid grid-cols-1 gap-2 pl-6 sm:grid-cols-3">
                            <input aria-label={`Evidence note for ${r.label}`} disabled={!editable} value={r.note ?? ''} onChange={(e) => set(r.key, { note: e.target.value })} placeholder={`Evidence note (at least ${noteMin} characters)`} className={`${FIELD} mt-0 sm:col-span-2`} />
                            <select aria-label={`Document for ${r.label}`} disabled={!editable} value={r.document_id ?? ''} onChange={(e) => set(r.key, { document_id: e.target.value || null })} className={`${FIELD} mt-0`}>
                                <option value="">No document</option>{documents.map((d) => <option key={d.id} value={d.id}>{d.title}</option>)}
                            </select>
                        </div>
                    </li>
                ))}
            </ul>
            <InputError message={err} />
            {editable && <div className="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3"><p className="text-[11px] text-[#718096]">Upload evidence under Documents first, then pick it here.</p><button type="button" disabled={busy || !dirty} onClick={save} className={BTN_PRIMARY}>{busy ? 'Saving…' : 'Save checklist'}</button></div>}
        </Card>
    );
}
