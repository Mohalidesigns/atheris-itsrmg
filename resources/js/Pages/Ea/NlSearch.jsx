import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function NlSearch({ result = { hits: [], intents: [], count: 0, query: '' }, query = '' }) {
    const [q, setQ] = useState(query);
    const submit = (e) => { e.preventDefault(); router.get(route('ea.search'), { q }, { preserveState: true }); };

    return (
        <AuthenticatedLayout header="EA Search">
            <Head title="EA Search" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Search' }]}
                title="Natural-Language EA Search"
                subtitle="Find anything in the EA repository — applications, capabilities, tech, principles, standards, plateaux. Intents like 'critical', 'pii', 'eol', 'cross-border' refine the result."
            />

            <form onSubmit={submit} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4 flex items-center gap-2">
                <input className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder='e.g. "critical applications with pii" or "tech components at eol"' value={q} onChange={(e) => setQ(e.target.value)} />
                <button className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Search</button>
            </form>

            {result.query && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <div className="text-sm text-gray-600 mb-2">
                        {result.count} result{result.count !== 1 && 's'} for <span className="font-mono text-[#0A1F44]">"{result.query}"</span>
                        {result.intents && result.intents.length > 0 && (
                            <span className="ml-3">Intents: {result.intents.map((i) => <span key={i} className="ml-1 px-2 py-0.5 text-xs rounded bg-[#C9A86A]/20">{i}</span>)}</span>
                        )}
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {(result.hits || []).map((h, i) => (
                            <li key={i} className="py-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <StatusBadge status="moderate" label={h.type} />
                                    <span className="font-mono text-xs">{h.code}</span>
                                    <span className="font-medium">{h.title}</span>
                                    <span className="ml-auto text-xs text-gray-400">score {h.score}</span>
                                </div>
                                {h.preview && <p className="text-xs text-gray-600 ml-1">{h.preview}</p>}
                            </li>
                        ))}
                        {result.count === 0 && <li className="py-6 text-center text-gray-400 text-sm">No matches.</li>}
                    </ul>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
