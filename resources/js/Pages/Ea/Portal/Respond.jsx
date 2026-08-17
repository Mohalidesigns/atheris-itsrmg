import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The magic-link response form — WS 1.6, and the surface B2 lives or dies on.
 *
 * Deliberately NOT wrapped in AuthenticatedLayout: the respondent is typically
 * an application owner in a business unit who holds no account and never will
 * (§3.4 — LeanIX's licensed-user-only surveys are "a structural crowdsourcing
 * ceiling"). No sidebar, no navigation, no login. One record, a handful of
 * questions, pre-filled with what we currently believe so the job is to confirm
 * rather than to retype.
 *
 * §10 also applies here: this must be usable on 3G, so the page ships no charts
 * and no graph views.
 */

const INPUT =
    'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm text-[#2D3748]';

function Field({ field, value, onChange }) {
    const id = `q-${field.attribute}`;

    let control;
    if (field.type === 'textarea') {
        control = (
            <textarea id={id} className={INPUT} rows={3} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
        );
    } else if (field.type === 'select') {
        control = (
            <select id={id} className={INPUT} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">— not sure —</option>
                {(field.options || []).map((o) => (
                    <option key={o} value={o}>
                        {o}
                    </option>
                ))}
            </select>
        );
    } else {
        control = (
            <input
                id={id}
                type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                className={INPUT}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-[#2D3748]">
                {field.label}
            </label>
            {control}
        </div>
    );
}

export default function Respond({ token, survey = {}, entity = {}, recipient = {}, current = {}, closesAt }) {
    const [declining, setDeclining] = useState(false);
    const form = useForm({ answers: { ...current }, comment: '' });
    const declineForm = useForm({ comment: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('ea.portal.submit', token));
    };

    const decline = (e) => {
        e.preventDefault();
        declineForm.post(route('ea.portal.decline', token));
    };

    return (
        <div className="min-h-screen bg-[#F7FAFC] px-4 py-8">
            <Head title="Confirm your architecture record" />

            <div className="mx-auto max-w-2xl">
                <div className="mb-6 text-center">
                    <p className="text-xs font-semibold uppercase tracking-widest text-[#C9A86A]">Atheris</p>
                    <h1 className="mt-1 text-xl font-semibold text-[#0A1F44]">
                        {survey.name || 'Confirm your architecture record'}
                    </h1>
                    {survey.description && (
                        <p className="mx-auto mt-2 max-w-lg text-sm text-[#718096]">{survey.description}</p>
                    )}
                </div>

                <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-6 py-4">
                        <p className="text-xs uppercase tracking-wide text-[#718096]">{entity.type_label}</p>
                        <p className="mt-0.5 text-base font-semibold text-[#0A1F44]">{entity.label}</p>
                        {recipient.role && (
                            <p className="mt-1 text-xs text-[#718096]">
                                You are listed as <span className="font-medium">{recipient.role}</span> for this
                                record.
                            </p>
                        )}
                    </div>

                    <form onSubmit={submit}>
                        <div className="space-y-4 px-6 py-5">
                            <p className="text-xs text-[#718096]">
                                These are the details we currently hold. Correct anything that is wrong and leave the
                                rest as it is.
                            </p>

                            {(survey.fields || []).map((field) => (
                                <Field
                                    key={field.attribute}
                                    field={field}
                                    value={form.data.answers[field.attribute]}
                                    onChange={(v) => form.setData('answers', { ...form.data.answers, [field.attribute]: v })}
                                />
                            ))}

                            <div>
                                <label htmlFor="comment" className="block text-sm font-medium text-[#2D3748]">
                                    Anything else we should know?
                                </label>
                                <textarea
                                    id="comment"
                                    className={INPUT}
                                    rows={2}
                                    value={form.data.comment}
                                    onChange={(e) => form.setData('comment', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                            <button
                                type="button"
                                onClick={() => setDeclining((v) => !v)}
                                className="text-xs text-[#718096] underline hover:text-[#2D3748]"
                            >
                                This is not mine
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-[#0A1F44] px-5 py-2 text-sm font-semibold text-white hover:bg-[#1A365D] disabled:opacity-50"
                            >
                                {form.processing ? 'Sending…' : 'Confirm details'}
                            </button>
                        </div>
                    </form>
                </div>

                {declining && (
                    <form onSubmit={decline} className="mt-4 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <p className="text-sm font-medium text-[#2D3748]">Not the right person?</p>
                        <p className="mt-1 text-xs text-[#718096]">
                            Tell us who owns this and we will re-route it. Nothing you entered above will be saved.
                        </p>
                        <textarea
                            className={INPUT}
                            rows={2}
                            placeholder="e.g. This moved to the Payments team — ask Adaeze."
                            value={declineForm.data.comment}
                            onChange={(e) => declineForm.setData('comment', e.target.value)}
                        />
                        <button
                            type="submit"
                            disabled={declineForm.processing}
                            className="mt-3 rounded-lg border border-gray-200 bg-white px-4 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                        >
                            Send and close
                        </button>
                    </form>
                )}

                <p className="mt-6 text-center text-[11px] text-[#718096]">
                    {closesAt ? `Please respond by ${closesAt}. ` : ''}
                    This link is personal to you — no login is needed, and it only gives access to this one record.
                </p>
            </div>
        </div>
    );
}
