import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { lockMessage } from '@/Utils/csat';

function CommentBox({ disabled, initial, onSave }) {
    const [value, setValue] = useState(initial || '');
    return (
        <textarea rows={1} value={value} disabled={disabled} placeholder="Comment / evidence reference (saved when you leave the field)"
            onChange={(e) => setValue(e.target.value)}
            onBlur={() => value !== (initial || '') && onSave(value)}
            className="mt-3 ml-10 w-[calc(100%-2.5rem)] text-xs border-gray-200 rounded-lg disabled:bg-gray-50" />
    );
}

const riskLevelLabels = { 1: 'Least', 2: 'Minimal', 3: 'Moderate', 4: 'Significant', 5: 'Most' };
const riskLevelColors = { 1: '#2D7D46', 2: '#319795', 3: '#D4AF37', 4: '#DD6B20', 5: '#C53030' };
const categoryNames = {
    1: 'Technologies and Connection Types', 2: 'Delivery Channels',
    3: 'Online/Mobile Products and Technology Services', 4: 'Organisational Characteristics', 5: 'External Threats',
};

export default function InherentRiskQuestions({ assessment, questions, responses, editable = true }) {
    const [activeCategory, setActiveCategory] = useState(1);
    const [saving, setSaving] = useState(null);

    const grouped = {};
    questions.forEach(q => {
        if (!grouped[q.category_code]) grouped[q.category_code] = [];
        grouped[q.category_code].push(q);
    });

    // The comment is only sent when it is being edited, so choosing a level never erases it.
    const saveResponse = (questionId, level, comment) => {
        setSaving(questionId);
        router.post(route('csat.ir.response.save', assessment.id), {
            question_id: questionId,
            selected_level: level,
            ...(comment !== undefined ? { comment } : {}),
        }, {
            preserveScroll: true,
            onFinish: () => setSaving(null),
        });
    };

    const catQuestions = grouped[activeCategory] || [];

    return (
        <AuthenticatedLayout header="Inherent Risk Assessment">
            <Head title="Inherent Risk Questions" />

            <div className="mb-4">
                <Link href={route('csat.ir.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to IR Dashboard</Link>
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            <div className="flex gap-6">
                {/* Category Sidebar */}
                <div className="w-56 shrink-0">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm sticky top-20">
                        <div className="p-3">
                            <p className="text-xs text-[#718096] uppercase font-medium mb-2">Categories</p>
                            {Object.entries(categoryNames).map(([code, name]) => {
                                const catQ = grouped[parseInt(code)] || [];
                                const answered = catQ.filter(q => responses[q.id]).length;
                                return (
                                    <button key={code} onClick={() => setActiveCategory(parseInt(code))}
                                        className={`w-full text-left px-3 py-2 rounded-lg text-sm mb-1 transition-colors ${
                                            activeCategory === parseInt(code)
                                                ? 'bg-[#1A365D] text-white'
                                                : 'text-[#2D3748] hover:bg-gray-50'
                                        }`}>
                                        <span className="block truncate">{name}</span>
                                        <span className={`text-xs ${activeCategory === parseInt(code) ? 'text-white/70' : 'text-[#718096]'}`}>
                                            {answered}/{catQ.length} answered
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Questions */}
                <div className="flex-1 space-y-4">
                    <h2 className="text-lg font-semibold text-[#2D3748]">{categoryNames[activeCategory]}</h2>
                    {catQuestions.map(q => {
                        const resp = responses[q.id];
                        const selectedLevel = resp?.selected_level;
                        return (
                            <div key={q.id} className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                                <div className="flex items-start gap-3 mb-3">
                                    <span className="bg-[#1A365D] text-white text-xs font-bold w-7 h-7 rounded-full flex items-center justify-center shrink-0">
                                        {q.question_number}
                                    </span>
                                    <div>
                                        <p className="text-sm font-medium text-[#2D3748]">{q.question_text}</p>
                                        {q.cbn_specific && (
                                            <span className="inline-block mt-1 px-2 py-0.5 bg-[#D4AF37]/10 text-[#D4AF37] text-xs font-bold rounded">CBN-Specific</span>
                                        )}
                                    </div>
                                </div>
                                <div className="grid grid-cols-5 gap-2 ml-10">
                                    {[1, 2, 3, 4, 5].map(level => (
                                        <button key={level}
                                            onClick={() => level !== selectedLevel && saveResponse(q.id, level)}
                                            disabled={!editable || saving === q.id}
                                            className={`p-2 rounded-lg border text-xs transition-all ${
                                                selectedLevel === level
                                                    ? 'border-2 shadow-md text-white'
                                                    : 'border-gray-200 text-[#2D3748] hover:border-gray-300 hover:bg-gray-50'
                                            }`}
                                            style={selectedLevel === level ? { borderColor: riskLevelColors[level], backgroundColor: riskLevelColors[level] } : {}}>
                                            <span className="block font-bold mb-1">{riskLevelLabels[level]}</span>
                                            <span className={`block leading-tight ${selectedLevel === level ? 'text-white/90' : 'text-[#718096]'}`}>
                                                {q[`level_${level}_criteria`]}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {selectedLevel && (
                                    <CommentBox key={`${q.id}-${resp?.updated_at}`} disabled={!editable} initial={resp?.comment}
                                        onSave={(comment) => saveResponse(q.id, selectedLevel, comment)} />
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
