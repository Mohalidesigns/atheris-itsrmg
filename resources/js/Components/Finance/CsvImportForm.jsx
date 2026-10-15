import InputError from '@/Components/InputError';
import { ArrowUpTrayIcon } from '@/Components/Material/Symbols';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

/** Upload a CSV to a Ziggy route; the server reports back through flash.import_report. */
export default function CsvImportForm({ routeName, hint, label = 'Import CSV' }) {
    const form = useForm({ file: null });
    const input = useRef(null);
    const submit = (e) => {
        e.preventDefault();
        if (!form.data.file) return;
        form.post(route(routeName), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { form.reset(); if (input.current) input.current.value = ''; },
        });
    };
    return (
        <form onSubmit={submit} className="rounded-lg border border-dashed border-gray-300 bg-white p-4">
            <p className="text-sm font-medium text-[#2D3748]">{label}</p>
            {hint && <p className="mt-1 break-words font-mono text-[11px] text-[#718096]">{hint}</p>}
            <div className="mt-3 flex flex-wrap items-center gap-3">
                <input ref={input} type="file" accept=".csv,.txt,text/csv" onChange={(e) => form.setData('file', e.target.files[0] || null)} className="max-w-full text-sm text-[#4A5568]" />
                <button disabled={form.processing || !form.data.file} className="inline-flex items-center gap-1 rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">
                    <ArrowUpTrayIcon className="h-4 w-4" /> {form.processing ? 'Uploading…' : 'Upload'}
                </button>
            </div>
            <InputError message={form.errors.file} />
        </form>
    );
}
