import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';

export default function ThemeSettings({ theme }) {
    const form = useForm({ tokens: theme.tokens, logo_path: theme.logo_path || '' });
    const setToken = (k, v) => form.setData('tokens', { ...form.data.tokens, [k]: v });
    const submit = (e) => { e.preventDefault(); form.put(route('settings.theme.update'), { preserveScroll: true }); };
    return (
        <AuthenticatedLayout header="Tenant Theme">
            <Head title="Tenant Theme" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Tenant Theme' }]}
                title="Tenant Theme"
                subtitle={`Colour tokens and logo for your bank${theme.is_default ? ' (currently the platform defaults)' : ''}. Saved per bank; the application shell does not yet read these values, so changes are stored but not applied.`}
            />
            <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Colour tokens</h3>
                    <div className="grid grid-cols-2 gap-4 mt-3">
                        {Object.entries(form.data.tokens).map(([k, v]) => (
                            <div key={k}>
                                <label className="block text-xs text-[#718096] mb-1 uppercase tracking-wider">{k}</label>
                                <div className="flex items-center gap-2">
                                    <span className="w-10 h-10 rounded-lg border border-gray-200" style={{ background: v }} />
                                    <input type="text" value={v} onChange={(e) => setToken(k, e.target.value)}
                                        className="flex-1 rounded-lg border border-gray-200 text-sm px-3 py-2 font-mono" />
                                </div>
                                {form.errors[`tokens.${k}`] && <p className="text-xs text-[#B3261E] mt-1">Use a hex colour such as #0A1F44.</p>}
                            </div>
                        ))}
                    </div>
                    <button type="submit" disabled={form.processing} className="mt-5 px-4 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Save theme</button>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Logo URL</h3>
                    <input type="url" value={form.data.logo_path} onChange={(e) => form.setData('logo_path', e.target.value)}
                        placeholder="https://…/logo.svg" className="mt-3 w-full rounded-lg border border-gray-200 text-sm px-3 py-2" />
                    {form.errors.logo_path && <p className="text-xs text-[#B3261E] mt-1">{form.errors.logo_path}</p>}
                </aside>
            </form>
        </AuthenticatedLayout>
    );
}
