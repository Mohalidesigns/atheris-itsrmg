import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function SsoIndex({ connections = [] }) {
    const { auth } = usePage().props;
    const canEdit = (auth?.user?.roles || []).includes('Super Admin') || (auth?.user?.permissions || []).includes('edit platform');
    const [editing, setEditing] = useState(null); // 'new' | connection id
    const form = useForm({ type: 'saml', idp_name: '', idp_entity_id: '', sso_url: '', certificate: '', enabled: false });
    const open = (c) => {
        form.setData(c ? { type: c.type, idp_name: c.idp_name, idp_entity_id: c.idp_entity_id || '', sso_url: c.sso_url || '', certificate: c.certificate || '', enabled: !!c.enabled }
            : { type: 'saml', idp_name: '', idp_entity_id: '', sso_url: '', certificate: '', enabled: false });
        setEditing(c ? c.id : 'new');
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post(route('identity.sso.store'), opts);
        else form.put(route('identity.sso.update', editing), opts);
    };
    const field = 'w-full px-3 py-2 rounded-lg border border-gray-200 text-sm';
    return (
        <AuthenticatedLayout header="SSO Connections">
            <Head title="SSO Connections" />
            <PageHeader
                breadcrumbs={[{ label: 'Identity & Access' }, { label: 'SSO Connections' }]}
                title="SSO — SAML 2.0 & OIDC"
                subtitle="Entra ID, Okta, Ping, Google Workspace. Atheris is an SP; upload IdP metadata here."
                actions={canEdit && <button onClick={() => open(null)} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ Add connection</button>}
            />
            {editing !== null && (
                <form onSubmit={submit} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <select className={field} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                        <option value="saml">SAML 2.0</option>
                        <option value="oidc">OIDC</option>
                    </select>
                    <input className={field} placeholder="Connection name" value={form.data.idp_name} onChange={(e) => form.setData('idp_name', e.target.value)} required />
                    <input className={field} placeholder="IdP entity ID / issuer" value={form.data.idp_entity_id} onChange={(e) => form.setData('idp_entity_id', e.target.value)} />
                    <input className={field} placeholder="SSO URL" value={form.data.sso_url} onChange={(e) => form.setData('sso_url', e.target.value)} />
                    <textarea className={`${field} md:col-span-2 font-mono text-xs`} rows={4} placeholder="IdP signing certificate (PEM)" value={form.data.certificate} onChange={(e) => form.setData('certificate', e.target.value)} />
                    <label className="text-sm flex items-center gap-2"><input type="checkbox" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} /> Enabled</label>
                    <div className="flex gap-2 justify-end">
                        <button type="button" onClick={() => setEditing(null)} className="px-3 py-2 rounded-lg border border-gray-200 text-sm">Cancel</button>
                        <button type="submit" disabled={form.processing} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm disabled:opacity-60">Save</button>
                    </div>
                    {Object.values(form.errors).map((m, i) => <p key={i} className="text-xs text-[#B3261E] md:col-span-2">{m}</p>)}
                </form>
            )}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {connections.map((c) => (
                    <div key={c.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{c.idp_name}</h3>
                                <p className="text-xs text-[#718096] uppercase">{c.type}</p>
                            </div>
                            <StatusBadge status={c.enabled ? 'enabled' : 'disabled'} label={c.enabled ? 'Enabled' : 'Disabled'} />
                        </div>
                        <p className="text-xs text-[#718096] mt-2 font-mono truncate">SP Entity: {c.sp_entity_id}</p>
                        {canEdit && (
                            <div className="mt-3 flex gap-2">
                                <button onClick={() => open(c)} className="flex-1 px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">Edit</button>
                                <a href={route('identity.sso.metadata', c.id)} className="flex-1 text-center px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                                    Download SP metadata
                                </a>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
