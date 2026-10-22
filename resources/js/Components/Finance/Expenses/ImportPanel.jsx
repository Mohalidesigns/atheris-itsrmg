import InputError from '@/Components/InputError';
import FlashReport from '@/Components/Finance/Expenses/ReportView';
import { BTN_PRIMARY, BTN_SECONDARY } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

/** CSV upload with Preview (dry-run) and Import buttons; both flash `import_report`, shown below. */
export default function ImportPanel({ routeName, type, hint, label = 'Import CSV', extra = {}, children }) {
    const input = useRef(null);
    const form = useForm({ file: null, mode: 'preview', ...extra });
    const send = (mode) => {
        if (!form.data.file) return;
        form.transform((d) => ({ ...d, mode }));
        form.post(route(routeName), { forceFormData: true, preserveScroll: true });
    };
    return (
        <div>
            <div className="rounded-lg border border-dashed border-gray-300 bg-white p-4">
                <p className="text-sm font-medium text-[#2D3748]">{label}</p>
                {hint && <p className="mt-1 break-words font-mono text-[11px] text-[#718096]">{hint}</p>}
                {children}
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <input ref={input} type="file" accept=".csv,.txt,text/csv" onChange={(e) => form.setData('file', e.target.files[0] || null)} className="max-w-full text-sm text-[#4A5568]" />
                    <button type="button" disabled={form.processing || !form.data.file} onClick={() => send('preview')} className={BTN_SECONDARY}>Preview</button>
                    <button type="button" disabled={form.processing || !form.data.file} onClick={() => send('apply')} className={BTN_PRIMARY}>{form.processing ? 'Working…' : 'Import'}</button>
                </div>
                <InputError message={form.errors.file} />
                <InputError message={form.errors.mode} />
            </div>
            <div className="mt-3"><FlashReport type={type} /></div>
        </div>
    );
}
