import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { lockMessage } from '@/Utils/csat';

function NarrativeField({ n, editable, onSave }) {
    const [value, setValue] = useState(n.response_text || '');
    const length = value.trim().length;
    const short = n.is_mandatory && length < n.min_characters;
    return (
        <div>
            <label className="block text-sm font-medium text-[#2D3748] mb-1">
                {n.question_number}. {n.question_text}{n.is_mandatory && <span className="text-[#C53030]"> *</span>}
            </label>
            <textarea value={value} rows={3} disabled={!editable}
                onChange={e => setValue(e.target.value)}
                onBlur={() => value !== (n.response_text || '') && onSave(n.id, value)}
                className={`w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30 disabled:bg-gray-50 ${short ? 'border-amber-300' : 'border-gray-200'}`}
                placeholder="Enter your response..." />
            <p className={`text-[11px] mt-0.5 ${short ? 'text-[#DD6B20]' : 'text-[#718096]'}`}>
                {length} / {n.min_characters} characters minimum{short ? ' — needed before submission' : ''}
            </p>
        </div>
    );
}

export default function MaturityNarratives({ assessment, narratives, domainNames, editable = true }) {
    const grouped = {};
    (narratives || []).forEach(n => {
        const d = n.domain_code || 0;
        if (!grouped[d]) grouped[d] = [];
        grouped[d].push(n);
    });

    const saveNarrative = (id, value) => {
        router.post(route('csat.ma.narrative.save', assessment.id), {
            id, response_text: value,
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Maturity Narratives">
            <Head title="Maturity Narratives" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.ma.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Maturity Dashboard</Link>
            </div>

            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}
            <p className="text-sm text-[#718096] mb-6">
                Narrative responses for each maturity domain (Sheet 15). {(narratives || []).filter(n => n.is_mandatory && (n.response_text || '').trim().length >= n.min_characters).length} of {(narratives || []).filter(n => n.is_mandatory).length} mandatory narratives complete. Fields save when you leave them.
            </p>

            {Object.entries(grouped).map(([domainCode, items]) => (
                <div key={domainCode} className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-4">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">
                        {domainNames[domainCode] || `Domain ${domainCode}`}
                    </h3>
                    <div className="space-y-4">
                        {items.map(n => <NarrativeField key={n.id} n={n} editable={editable} onSave={saveNarrative} />)}
                    </div>
                </div>
            ))}

            {(narratives || []).length === 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                    <p className="text-sm text-[#718096]">No narrative questions have been provisioned for this assessment.</p>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
