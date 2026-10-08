import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { lockMessage } from '@/Utils/csat';

export default function InherentRiskNarratives({ assessment, narratives, narrativeFields, editable = true }) {
    const narrativeMap = {};
    (narratives || []).forEach(n => { narrativeMap[`${n.category_code}_${n.narrative_key}`] = n.narrative_value; });

    const saveNarrative = (categoryCode, key, value) => {
        router.post(route('csat.ir.narrative.save', assessment.id), {
            category_code: categoryCode, narrative_key: key, narrative_value: value,
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Inherent Risk Narratives">
            <Head title="IR Narratives" />
            <div className="mb-4">
                <Link href={route('csat.ir.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to IR Dashboard</Link>
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}
            <p className="text-sm text-[#718096] mb-6">Provide narrative explanations for each inherent risk category (Sheet 12). Each category needs at least one completed narrative before submission. Fields save when you leave them.</p>
            {narrativeFields.map(cat => (
                <div key={cat.category_code} className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-4">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">{cat.category_name}</h3>
                    <div className="space-y-4">
                        {cat.fields.map(f => (
                            <div key={f.key}>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">{f.label}</label>
                                <textarea
                                    defaultValue={narrativeMap[`${cat.category_code}_${f.key}`] || ''}
                                    rows={3}
                                    disabled={!editable}
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30 disabled:bg-gray-50"
                                    onBlur={e => e.target.value !== (narrativeMap[`${cat.category_code}_${f.key}`] || '') && saveNarrative(cat.category_code, f.key, e.target.value)}
                                    placeholder={`Describe ${f.label.toLowerCase()}...`}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </AuthenticatedLayout>
    );
}
