import InputError from '@/Components/InputError';
import Field from './Field';
import FlashReport from './ReportView';
import { Card, Check } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const FORMAT_LABEL = { 'Y-m-d': 'YYYY-MM-DD', 'd/m/Y': 'DD/MM/YYYY', 'm/d/Y': 'MM/DD/YYYY', 'd-m-Y': 'DD-MM-YYYY', 'd.m.Y': 'DD.MM.YYYY', 'Y/m/d': 'YYYY/MM/DD' };

/**
 * GL extract import: choose a saved mapping or map columns inline, preview (dry run, nothing posted), then import.
 * Server refusals (duplicate file, bad rows) appear under the form; the report is read from flash.import_report.
 */
export default function GlImportPanel({ mappings, mappingKeys, dateFormats }) {
    const def = mappings.find((m) => m.is_default) ?? null;
    const form = useForm({
        file: null, mode: 'preview', mapping_id: def?.id ?? '', columns: {}, delimiter: ',', date_format: dateFormats[0] ?? 'Y-m-d', decimal_comma: false, has_header: true,
        default_currency: '', skip_invalid: false, force: false, save_mapping_as: '',
    });
    const d = form.data;
    const e = form.errors;
    const inline = !d.mapping_id;
    const send = (mode) => {
        form.transform((cur) => ({ ...cur, mode, mapping_id: cur.mapping_id || null, default_currency: cur.default_currency || null, save_mapping_as: cur.mapping_id ? null : cur.save_mapping_as || null,
            columns: cur.mapping_id ? {} : cur.columns }));
        form.post(route('finance.actuals.import'), { forceFormData: true, preserveScroll: true });
    };
    const setCol = (k, v) => form.setData('columns', { ...d.columns, [k]: v });
    return (
        <div className="space-y-4">
            <Card title="Import a GL extract (CSV)">
                <div className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <Field id="gl-file" label="File" error={e.file} className="sm:col-span-2"><input id="gl-file" type="file" accept=".csv,.txt,text/csv" onChange={(ev) => form.setData('file', ev.target.files[0] || null)} className={FIELD} /></Field>
                        <Field id="gl-map" label="Column mapping" error={e.mapping_id}><select id="gl-map" value={d.mapping_id} onChange={(ev) => form.setData('mapping_id', ev.target.value)} className={FIELD}><option value="">Map columns now</option>{mappings.map((m) => <option key={m.id} value={m.id}>{m.name}{m.is_default ? ' (default)' : ''}</option>)}</select></Field>
                    </div>
                    {inline && (
                        <div className="rounded-lg border border-gray-100 p-3">
                            <p className="mb-2 text-xs text-[#718096]">Type the header name of each column in your file (or its position when the file has no header).</p>
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                                {[...mappingKeys.required.map((k) => [k, true]), ...mappingKeys.optional.map((k) => [k, false])].map(([k, req]) => (
                                    <div key={k}><label className="text-xs text-[#4A5568]" htmlFor={`gl-c-${k}`}>{humanize(k)}{req ? ' (required)' : ''}</label><input id={`gl-c-${k}`} value={d.columns[k] ?? ''} onChange={(ev) => setCol(k, ev.target.value)} className={FIELD} /></div>
                                ))}
                            </div>
                            <InputError message={e.columns} />
                            <div className="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-5">
                                <Field id="gl-del" label="Delimiter" error={e.delimiter}><select id="gl-del" value={d.delimiter} onChange={(ev) => form.setData('delimiter', ev.target.value)} className={FIELD}><option value=",">Comma</option><option value=";">Semicolon</option><option value={'\t'}>Tab</option><option value="|">Pipe</option></select></Field>
                                <Field id="gl-df" label="Date format" error={e.date_format}><select id="gl-df" value={d.date_format} onChange={(ev) => form.setData('date_format', ev.target.value)} className={FIELD}>{dateFormats.map((f) => <option key={f} value={f}>{FORMAT_LABEL[f] ?? f}</option>)}</select></Field>
                                <Field id="gl-cur" label="Default currency" error={e.default_currency}><input id="gl-cur" maxLength={3} value={d.default_currency} onChange={(ev) => form.setData('default_currency', ev.target.value.toUpperCase())} className={FIELD} placeholder="Base" /></Field>
                                <Field id="gl-save" label="Save mapping as" error={e.save_mapping_as} className="col-span-2"><input id="gl-save" maxLength={120} value={d.save_mapping_as} onChange={(ev) => form.setData('save_mapping_as', ev.target.value)} className={FIELD} /></Field>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-4"><Check label="Decimal comma (1.234,56)" checked={d.decimal_comma} onChange={(v) => form.setData('decimal_comma', v)} /><Check label="File has a header row" checked={d.has_header} onChange={(v) => form.setData('has_header', v)} /></div>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-4"><Check label="Skip invalid rows and import the rest" checked={d.skip_invalid} onChange={(v) => form.setData('skip_invalid', v)} /><Check label="Import even if this file was imported before" checked={d.force} onChange={(v) => form.setData('force', v)} /></div>
                    <InputError message={e.mode} />
                    <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" disabled={form.processing || !d.file} onClick={() => send('preview')} className={BTN_SECONDARY}>Preview</button>
                        <button type="button" disabled={form.processing || !d.file} onClick={() => send('apply')} className={BTN_PRIMARY}>{form.processing ? 'Working…' : 'Import'}</button>
                    </div>
                </div>
            </Card>
            <FlashReport type="actuals" />
        </div>
    );
}
