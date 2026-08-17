import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function MaturityNarratives({ assessment, narratives, domainNames }) {
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

            <p className="text-sm text-[#718096] mb-6">Provide narrative responses for each maturity domain's supplementary questions.</p>

            {Object.entries(grouped).map(([domainCode, items]) => (
                <div key={domainCode} className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-4">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">
                        {domainNames[domainCode] || `Domain ${domainCode}`}
                    </h3>
                    <div className="space-y-4">
                        {items.map(n => (
                            <div key={n.id}>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">{n.question_text}</label>
                                <textarea
                                    defaultValue={n.response_text || ''}
                                    rows={3}
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30"
                                    onBlur={e => saveNarrative(n.id, e.target.value)}
                                    placeholder="Enter your response..."
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}

            {(narratives || []).length === 0 && (
                <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                    <p className="text-sm text-[#718096]">No narrative questions configured. Run the narrative template seeder first.</p>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
