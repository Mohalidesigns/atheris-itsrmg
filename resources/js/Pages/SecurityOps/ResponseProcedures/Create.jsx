import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { useState } from 'react';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function CreateResponseProcedure({ incidentTypes = [] }) {
    const [stepsText, setStepsText] = useState('');
    const [contactsText, setContactsText] = useState('');
    const { data, setData, post, processing, errors, transform } = useForm({
        title: '',
        description: '',
        category: '',
        incident_types: [],
        steps: [],
        escalation_contacts: [],
        version: '1.0',
        last_reviewed: '',
        is_active: true,
    });

    transform((d) => ({
        ...d,
        steps: stepsText.split('\n').map(s => s.trim()).filter(Boolean),
        escalation_contacts: contactsText.split('\n').map(s => s.trim()).filter(Boolean),
    }));

    const toggleType = (t) => {
        setData('incident_types', data.incident_types.includes(t)
            ? data.incident_types.filter(x => x !== t)
            : [...data.incident_types, t]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('response-procedures.store'));
    };

    return (
        <AuthenticatedLayout header="New Response Procedure">
            <Head title="New Response Procedure" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Procedure Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput id="title" value={data.title} className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)} required
                                placeholder="e.g. Ransomware Containment & Recovery" />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="category" value="Category" />
                                <TextInput id="category" value={data.category} className="mt-1 block w-full"
                                    onChange={e => setData('category', e.target.value)} placeholder="e.g. malware" />
                                <InputError message={errors.category} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="version" value="Version" />
                                <TextInput id="version" value={data.version} className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('version', e.target.value)} />
                                <InputError message={errors.version} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="last_reviewed" value="Last Reviewed" />
                                <TextInput id="last_reviewed" type="date" value={data.last_reviewed} className="mt-1 block w-full"
                                    onChange={e => setData('last_reviewed', e.target.value)} />
                                <InputError message={errors.last_reviewed} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value={`Applicable Incident Types (${data.incident_types.length} selected)`} />
                            <div className="mt-1 flex flex-wrap gap-2">
                                {incidentTypes.map(t => (
                                    <button key={t} type="button" onClick={() => toggleType(t)}
                                        className={`px-2.5 py-1 rounded-full text-xs border transition-colors ${
                                            data.incident_types.includes(t)
                                                ? 'bg-[#0A1F44] text-white border-[#0A1F44]'
                                                : 'bg-white text-[#2D3748] border-gray-200 hover:bg-gray-50'
                                        }`}>
                                        {cap(t)}
                                    </button>
                                ))}
                            </div>
                            <InputError message={errors.incident_types} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="steps" value="Response Steps (one per line)" />
                            <textarea id="steps" value={stepsText} onChange={e => setStepsText(e.target.value)} rows={6}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm font-mono-data"
                                placeholder={'Isolate affected hosts\nPreserve evidence\nNotify stakeholders'} />
                            <InputError message={errors.steps} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="contacts" value="Escalation Contacts (one per line)" />
                            <textarea id="contacts" value={contactsText} onChange={e => setContactsText(e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder={'CISO\nHead of IT Operations'} />
                            <InputError message={errors.escalation_contacts} className="mt-1" />
                        </div>

                        <label className="flex items-center gap-2 text-sm text-[#2D3748]">
                            <input type="checkbox" checked={data.is_active} onChange={e => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30" />
                            Procedure is active
                        </label>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('response-procedures.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Procedure</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
