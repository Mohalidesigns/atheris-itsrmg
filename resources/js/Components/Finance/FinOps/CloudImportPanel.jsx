import ImportReport from '@/Components/Finance/ImportReport';
import InputError from '@/Components/InputError';
import { BTN_PRIMARY, FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

/** Provider export upload (multipart) to finance.cloud.import; the result shows through flash.import_report. */
export default function CloudImportPanel({ accounts, options }) {
    const input = useRef(null);
    const form = useForm({ cloud_account_id: accounts[0]?.id ?? '', format: '', file: null });
    const [touched, setTouched] = useState(false);
    const account = accounts.find((a) => String(a.id) === String(form.data.cloud_account_id));
    const fmt = options.formats.find((f) => f.value === (form.data.format || account?.default_format));
    const submit = (ev) => {
        ev.preventDefault();
        setTouched(true);
        if (!form.data.file) return;
        form.transform((d) => ({ ...d, format: d.format || null }));
        form.post(route('finance.cloud.import'), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset('file'); if (input.current) input.current.value = ''; } });
    };
    return (
        <div>
            <form onSubmit={submit} className="rounded-lg border border-dashed border-gray-300 bg-white p-4">
                <p className="text-sm font-medium text-[#2D3748]">Import a provider cost export</p>
                <p className="mt-1 text-xs text-[#718096]">CSV only. Rows are aggregated to daily lines. A file already imported for the account is refused; days in closed periods are never overwritten.</p>
                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL} htmlFor="ci-ac">Account</label><select id="ci-ac" value={form.data.cloud_account_id} onChange={(e) => form.setData('cloud_account_id', e.target.value)} className={FIELD}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select><InputError message={form.errors.cloud_account_id} /></div>
                    <div><label className={LABEL} htmlFor="ci-fm">Format</label><select id="ci-fm" value={form.data.format} onChange={(e) => form.setData('format', e.target.value)} className={FIELD}><option value="">Account default{account ? ` (${options.formats.find((f) => f.value === account.default_format)?.label ?? account.default_format})` : ''}</option>{options.formats.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={form.errors.format} /></div>
                    <div><label className={LABEL} htmlFor="ci-fi">File</label><input ref={input} id="ci-fi" type="file" accept=".csv,.txt,text/csv" onChange={(e) => form.setData('file', e.target.files[0] || null)} className={FIELD} /><InputError message={form.errors.file || (touched && !form.data.file ? 'Choose a file.' : '')} /></div>
                </div>
                {fmt?.columns?.length > 0 && <p className="mt-2 break-words font-mono text-[11px] text-[#718096]">Expected columns: {fmt.columns.join(', ')}</p>}
                <div className="mt-3 flex justify-end"><button disabled={form.processing || accounts.length === 0} className={BTN_PRIMARY}>{form.processing ? 'Importing…' : 'Import'}</button></div>
            </form>
            <div className="mt-3"><ImportReport type="cloud" /></div>
        </div>
    );
}
