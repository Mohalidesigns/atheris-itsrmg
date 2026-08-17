import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Glossary({ terms = [], byCategory = {} }) {
    const [search, setSearch] = useState('');
    const form = useForm({ term: '', definition: '', category: 'business', synonyms: '', owner_role: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('ea.glossary.store'), { onSuccess: () => form.reset() });
    };

    const filtered = terms.filter((t) => !search || t.term.toLowerCase().includes(search.toLowerCase()) || (t.definition || '').toLowerCase().includes(search.toLowerCase()));

    return (
        <AuthenticatedLayout header="Business Glossary">
            <Head title="Business Glossary" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Data' }, { label: 'Glossary' }]}
                title="Business Glossary"
                subtitle="Shared definitions so non-technical stakeholders can read the architecture model."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.glossary')} current="ea.glossary" />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="Terms" value={terms.length} tone="navy" />
                {Object.entries(byCategory).map(([k, v]) => <KpiCard key={k} label={k} value={v} tone="white" />)}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-[1fr,360px] gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <input className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mb-3" placeholder="Search terms…" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <div className="space-y-3">
                        {filtered.map((t) => (
                            <div key={t.id} className="border-b border-gray-50 pb-3">
                                <div className="flex items-center gap-2">
                                    <h4 className="font-bold text-[#0A1F44]">{t.term}</h4>
                                    <StatusBadge status="low" label={t.category} />
                                </div>
                                <p className="text-sm text-gray-700 mt-1">{t.definition}</p>
                                {t.synonyms && <div className="text-xs text-gray-500 mt-1">Synonyms: {t.synonyms}</div>}
                            </div>
                        ))}
                        {filtered.length === 0 && <p className="text-sm text-gray-400 text-center py-6">No terms match.</p>}
                    </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="font-bold text-[#0A1F44] mb-3">Add term</h3>
                    <form onSubmit={submit} className="space-y-2 text-sm">
                        <input className="w-full border border-gray-200 rounded-lg px-3 py-2" placeholder="Term" value={form.data.term} onChange={(e) => form.setData('term', e.target.value)} />
                        <select className="w-full border border-gray-200 rounded-lg px-3 py-2" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                            <option value="business">business</option><option value="technical">technical</option><option value="regulatory">regulatory</option><option value="data">data</option>
                        </select>
                        <textarea className="w-full border border-gray-200 rounded-lg px-3 py-2" placeholder="Definition" value={form.data.definition} onChange={(e) => form.setData('definition', e.target.value)} />
                        <input className="w-full border border-gray-200 rounded-lg px-3 py-2" placeholder="Synonyms" value={form.data.synonyms} onChange={(e) => form.setData('synonyms', e.target.value)} />
                        <input className="w-full border border-gray-200 rounded-lg px-3 py-2" placeholder="Owner role" value={form.data.owner_role} onChange={(e) => form.setData('owner_role', e.target.value)} />
                        <button type="submit" disabled={form.processing} className="w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Add term</button>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
