import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * EaFormModal — the single create/edit form used by every EA catalogue.
 *
 * ATH-EAR-002 §2.1 (RC-1): 30 of 44 write endpoints had no caller anywhere in
 * the frontend, and 27 of 46 pages contained no interactive element at all.
 * WS 0.1 calls for a shared `EaEntityForm` so wiring those endpoints is ~12
 * compositions rather than 30 bespoke pages. This is that component.
 *
 * Fields are declared as a schema so a page describes *what* it captures, not
 * *how* to render it:
 *
 *   const FIELDS = [
 *     { name: 'code',  label: 'Code',  type: 'text',   required: true, width: 'half' },
 *     { name: 'status', label: 'Status', type: 'select', options: ['draft','active'] },
 *   ];
 *
 * Supported types: text, textarea, number, date, select, multiselect, checkbox.
 */

const INPUT_CLASS =
    'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm text-[#2D3748]';

function normaliseOptions(options = []) {
    return options.map((o) =>
        typeof o === 'object' && o !== null ? o : { value: o, label: String(o) },
    );
}

function blankValue(field) {
    if (field.type === 'checkbox') return false;
    if (field.type === 'multiselect') return [];
    return '';
}

export function emptyValues(fields) {
    return fields.reduce((acc, f) => {
        acc[f.name] = f.default !== undefined ? f.default : blankValue(f);
        return acc;
    }, {});
}

function valuesFrom(fields, record) {
    if (!record) return emptyValues(fields);
    return fields.reduce((acc, f) => {
        const raw = record[f.name];
        if (raw === null || raw === undefined) {
            acc[f.name] = blankValue(f);
        } else if (f.type === 'checkbox') {
            acc[f.name] = Boolean(raw);
        } else if (f.type === 'date') {
            // Dates arrive as ISO strings or Carbon-serialised objects.
            acc[f.name] = String(raw).slice(0, 10);
        } else {
            acc[f.name] = raw;
        }
        return acc;
    }, {});
}

function Field({ field, value, error, onChange }) {
    const id = `ea-field-${field.name}`;
    const common = { id, name: field.name, className: INPUT_CLASS };

    let control;
    switch (field.type) {
        case 'textarea':
            control = (
                <textarea
                    {...common}
                    rows={field.rows || 3}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
            break;
        case 'select':
            control = (
                <select {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
                    <option value="">{field.placeholder || '— none —'}</option>
                    {normaliseOptions(field.options).map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );
            break;
        case 'multiselect':
            control = (
                <select
                    {...common}
                    multiple
                    size={Math.min(6, Math.max(3, (field.options || []).length))}
                    value={(value || []).map(String)}
                    onChange={(e) =>
                        onChange(Array.from(e.target.selectedOptions).map((o) => Number(o.value) || o.value))
                    }
                >
                    {normaliseOptions(field.options).map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );
            break;
        case 'checkbox':
            control = (
                <label className="mt-2 inline-flex items-center gap-2 text-sm text-[#2D3748]">
                    <input
                        id={id}
                        name={field.name}
                        type="checkbox"
                        checked={Boolean(value)}
                        onChange={(e) => onChange(e.target.checked)}
                        className="rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                    />
                    {field.checkboxLabel || 'Yes'}
                </label>
            );
            break;
        default:
            control = (
                <input
                    {...common}
                    type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                    step={field.step}
                    min={field.min}
                    max={field.max}
                    placeholder={field.placeholder}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }

    const span =
        field.width === 'full' || field.type === 'textarea' || field.type === 'multiselect'
            ? 'md:col-span-2'
            : '';

    return (
        <div className={span}>
            {field.type !== 'checkbox' && (
                <InputLabel htmlFor={id} className="text-xs uppercase tracking-wide text-[#718096]">
                    {field.label}
                    {field.required && <span className="text-[#B3261E]"> *</span>}
                </InputLabel>
            )}
            {field.type === 'checkbox' && (
                <InputLabel className="text-xs uppercase tracking-wide text-[#718096]">
                    {field.label}
                </InputLabel>
            )}
            {control}
            {field.help && <p className="mt-1 text-[11px] text-[#718096]">{field.help}</p>}
            <InputError message={error} className="mt-1 text-xs" />
        </div>
    );
}

export default function EaFormModal({
    show,
    onClose,
    title,
    subtitle,
    fields = [],
    record = null,
    storeRoute,
    updateRoute,
    submitLabel,
    maxWidth = '2xl',
    transform,
}) {
    const isEdit = Boolean(record?.id);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(
        valuesFrom(fields, record),
    );

    // The modal is mounted once per page and reused, so the form state has to
    // be re-seeded whenever the caller switches between "create" and "edit a
    // different row".
    useEffect(() => {
        if (show) {
            clearErrors();
            const next = valuesFrom(fields, record);
            Object.entries(next).forEach(([k, v]) => setData(k, v));
        }
    }, [show, record?.id]);

    const submit = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };
        // Strip empty strings so `nullable` rules see null, not ''.
        const payload = Object.fromEntries(
            Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]),
        );
        const finalPayload = transform ? transform(payload) : payload;

        if (isEdit) {
            put(updateRoute(record), { ...options, data: finalPayload });
        } else {
            post(storeRoute(), { ...options, data: finalPayload });
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth={maxWidth}>
            <form onSubmit={submit}>
                <div className="border-b border-gray-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-[#0A1F44]">
                        {title || (isEdit ? 'Edit record' : 'New record')}
                    </h2>
                    {subtitle && <p className="mt-1 text-xs text-[#718096]">{subtitle}</p>}
                </div>

                <div className="grid max-h-[60vh] grid-cols-1 gap-4 overflow-y-auto px-6 py-5 md:grid-cols-2">
                    {fields.map((f) => (
                        <Field
                            key={f.name}
                            field={f}
                            value={data[f.name]}
                            error={errors[f.name]}
                            onChange={(v) => setData(f.name, v)}
                        />
                    ))}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                    <SecondaryButton type="button" onClick={onClose} disabled={processing}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={processing}>
                        {processing ? 'Saving…' : submitLabel || (isEdit ? 'Save changes' : 'Create')}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
