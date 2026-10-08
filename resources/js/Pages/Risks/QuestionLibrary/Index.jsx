import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import Pagination from '@/Components/Pagination';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon, PlusIcon, QuestionMarkCircleIcon, TrashIcon } from '@heroicons/react/24/outline';
import { humanize } from '@/Utils/risk';

const inputClass = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const TYPE_LABELS = { scale: 'Scale (1–5)', yes_no: 'Yes / No', multiple_choice: 'Multiple choice', text: 'Free text' };
const MODULE_LABELS = { risk: 'Risk', compliance: 'Compliance', vendor: 'Vendor', isms: 'ISMS' };
const BLANK = { title: '', question_text: '', category: '', module: 'risk', response_type: 'scale', response_options: [], weight: 3, is_active: true, sort_order: '' };

function QuestionForm({ question, modules, responseTypes, categories, onClose }) {
    const { data, setData, post, put, processing, errors } = useForm(question
        ? { ...BLANK, ...question, category: question.category || '', response_options: question.response_options || [], sort_order: question.sort_order ?? '' }
        : BLANK);
    const [optionsText, setOptionsText] = useState((data.response_options || []).join('\n'));

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        question ? put(route('question-libraries.update', question.id), opts) : post(route('question-libraries.store'), opts);
    };

    return (
        <form onSubmit={submit} className="p-6 space-y-4">
            <h2 className="text-lg font-semibold text-[#2D3748]">{question ? 'Edit question' : 'New question'}</h2>
            <div>
                <InputLabel htmlFor="q-title" value="Title *" />
                <input id="q-title" value={data.title} onChange={(e) => setData('title', e.target.value)} className={inputClass} required />
                <InputError message={errors.title} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="q-text" value="Question *" />
                <textarea id="q-text" rows={3} value={data.question_text} onChange={(e) => setData('question_text', e.target.value)} className={inputClass} required />
                <InputError message={errors.question_text} className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <InputLabel htmlFor="q-module" value="Module *" />
                    <select id="q-module" value={data.module} onChange={(e) => setData('module', e.target.value)} className={inputClass}>
                        {modules.map((m) => <option key={m} value={m}>{MODULE_LABELS[m] || humanize(m)}</option>)}
                    </select>
                    <InputError message={errors.module} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="q-category" value="Category" />
                    <input id="q-category" list="q-categories" value={data.category} onChange={(e) => setData('category', e.target.value)} className={inputClass} />
                    <datalist id="q-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                    <InputError message={errors.category} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="q-type" value="Response type *" />
                    <select id="q-type" value={data.response_type} onChange={(e) => setData('response_type', e.target.value)} className={inputClass}>
                        {responseTypes.map((t) => <option key={t} value={t}>{TYPE_LABELS[t] || humanize(t)}</option>)}
                    </select>
                    <InputError message={errors.response_type} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="q-weight" value="Weight (1–10) *" />
                    <input id="q-weight" type="number" min="1" max="10" value={data.weight} onChange={(e) => setData('weight', e.target.value)} className={inputClass} required />
                    <InputError message={errors.weight} className="mt-1" />
                </div>
            </div>
            {data.response_type === 'multiple_choice' && (
                <div>
                    <InputLabel htmlFor="q-options" value="Options (one per line) *" />
                    <textarea id="q-options" rows={4} value={optionsText}
                        onChange={(e) => { setOptionsText(e.target.value); setData('response_options', e.target.value.split('\n')); }}
                        className={inputClass} />
                    <InputError message={errors.response_options || errors['response_options.0']} className="mt-1" />
                </div>
            )}
            <div className="flex items-center gap-6">
                <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                    <input type="checkbox" checked={!!data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="rounded border-gray-300 text-[#1A365D]" />
                    Active (offered in new questionnaires)
                </label>
                <div className="flex items-center gap-2">
                    <InputLabel htmlFor="q-order" value="Order" />
                    <input id="q-order" type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} className="w-20 rounded-lg border-gray-300 text-sm" />
                </div>
            </div>
            <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
                <button type="button" onClick={onClose} className="px-4 py-2 text-sm rounded-lg border border-gray-200">Cancel</button>
                <button type="submit" disabled={processing} className="px-4 py-2 text-sm rounded-lg bg-[#0A1F44] text-white disabled:opacity-60">
                    {processing ? 'Saving…' : question ? 'Save changes' : 'Add question'}
                </button>
            </div>
        </form>
    );
}

export default function QuestionLibraryIndex({ questions, filters = {}, modules = [], responseTypes = [], categories = [], summary = {} }) {
    const [editing, setEditing] = useState(null); // null = closed, {} = new, question = edit
    const [search, setSearch] = useState(filters.search || '');
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const isSuper = (auth?.user?.roles || []).includes('Super Admin');
    const can = (p) => isSuper || perms.includes(p);
    const apply = (params) => router.get(route('question-libraries.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    const selectClass = 'text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]';

    const remove = (q) => {
        if (confirm(`Remove "${q.title}" from the library?`)) {
            router.delete(route('question-libraries.destroy', q.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Question Library">
            <Head title="Question Library" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'Question Library' }]}
                title="Question Library"
                subtitle={`${summary.active ?? 0} active of ${summary.total ?? 0} reusable questions for risk workshops, compliance self-assessments, vendor due diligence and ISMS audits.`}
                actions={can('create risks') && (
                    <button onClick={() => setEditing({})} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg">
                        <PlusIcon className="w-4 h-4" /> New Question
                    </button>
                )}
            />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                {modules.map((m) => (
                    <button key={m} onClick={() => apply({ module: filters.module === m ? undefined : m })}
                        className={`text-left bg-white rounded-xl border p-3 shadow-sm hover:border-[#C9A86A] ${filters.module === m ? 'border-[#C9A86A]' : 'border-gray-100'}`}>
                        <p className="text-xs text-[#718096] uppercase font-medium">{MODULE_LABELS[m]}</p>
                        <p className="text-xl font-bold font-mono-data text-[#2D3748]">{summary.by_module?.[m] ?? 0}</p>
                    </button>
                ))}
            </div>

            <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="flex flex-wrap gap-2 mb-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search questions…" className={`${selectClass} flex-1 min-w-[220px]`} />
                <select value={filters.category || ''} onChange={(e) => apply({ category: e.target.value || undefined })} className={selectClass}>
                    <option value="">All categories</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <select value={filters.response_type || ''} onChange={(e) => apply({ response_type: e.target.value || undefined })} className={selectClass}>
                    <option value="">All response types</option>
                    {responseTypes.map((t) => <option key={t} value={t}>{TYPE_LABELS[t]}</option>)}
                </select>
                <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value || undefined })} className={selectClass}>
                    <option value="">Active &amp; retired</option>
                    <option value="active">Active</option>
                    <option value="inactive">Retired</option>
                </select>
                <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">Search</button>
            </form>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                {questions.data.length === 0 ? (
                    <div className="p-12 text-center">
                        <QuestionMarkCircleIcon className="w-12 h-12 text-gray-300 mx-auto" />
                        <h3 className="text-sm font-medium text-[#2D3748] mt-4">{Object.values(filters).some(Boolean) ? 'No questions match these filters' : 'No questions yet'}</h3>
                        {can('create risks') && <button onClick={() => setEditing({})} className="mt-3 text-sm text-[#1A365D] underline">Add the first question</button>}
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50/50 border-b border-gray-100 text-left text-[#718096]">
                                <th className="px-4 py-3 font-medium">Question</th>
                                <th className="px-4 py-3 font-medium">Module</th>
                                <th className="px-4 py-3 font-medium">Category</th>
                                <th className="px-4 py-3 font-medium">Response</th>
                                <th className="px-4 py-3 font-medium text-center">Weight</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {questions.data.map((q) => (
                                <tr key={q.id} className={`hover:bg-gray-50/50 ${q.is_active ? '' : 'opacity-60'}`}>
                                    <td className="px-4 py-3 max-w-xl">
                                        <p className="font-medium text-[#2D3748]">{q.title}</p>
                                        <p className="text-xs text-[#718096]">{q.question_text}</p>
                                        {q.response_type === 'multiple_choice' && q.response_options?.length > 0 && (
                                            <p className="text-[11px] text-[#A0AEC0] mt-0.5">Options: {q.response_options.join(' · ')}</p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">{MODULE_LABELS[q.module] || humanize(q.module)}</td>
                                    <td className="px-4 py-3 text-[#718096]">{q.category || '—'}</td>
                                    <td className="px-4 py-3 text-[#718096] whitespace-nowrap">{TYPE_LABELS[q.response_type] || humanize(q.response_type)}</td>
                                    <td className="px-4 py-3 text-center font-mono-data">{q.weight}</td>
                                    <td className="px-4 py-3">
                                        <span className={`text-xs px-2 py-0.5 rounded-full ${q.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'}`}>{q.is_active ? 'Active' : 'Retired'}</span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-right">
                                        {can('edit risks') && (
                                            <button onClick={() => setEditing(q)} className="p-1.5 rounded hover:bg-gray-100" aria-label={`Edit ${q.title}`}>
                                                <PencilIcon className="w-4 h-4 text-[#718096]" />
                                            </button>
                                        )}
                                        {can('delete risks') && (
                                            <button onClick={() => remove(q)} className="p-1.5 rounded hover:bg-red-50" aria-label={`Delete ${q.title}`}>
                                                <TrashIcon className="w-4 h-4 text-[#C53030]" />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={questions.links} />
                </div>
            </div>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="2xl">
                {editing !== null && (
                    <QuestionForm key={editing.id || 'new'} question={editing.id ? editing : null}
                        modules={modules} responseTypes={responseTypes} categories={categories} onClose={() => setEditing(null)} />
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
