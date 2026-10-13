import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const BLANK = { name: '', system: 'jira', base_url: 'https://', project_key: '', username: '', api_token: '', mode: 'sandbox', status: 'active', auto_push_severity: '' };

export default function ItsmIndex({ connections = [], tickets = [], systems = {}, unticketed = 0, newSecret = null }) {
    const { auth } = usePage().props;
    const canManage = (auth?.user?.permissions || []).includes('approve issues') || (auth?.user?.roles || []).includes('Super Admin');
    const [editing, setEditing] = useState(null); // null | 'new' | connection
    const form = useForm(BLANK);

    const open = (c) => {
        setEditing(c || 'new');
        form.clearErrors();
        form.setData(c ? { ...BLANK, ...c, status: c.status === 'disabled' ? 'disabled' : 'active', api_token: '', project_key: c.project_key || '', username: c.username || '', auto_push_severity: c.auto_push_severity || '' } : BLANK);
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        editing === 'new' ? form.post(route('itsm.store'), opts) : form.put(route('itsm.update', editing.id), opts);
    };

    return (
        <AuthenticatedLayout header="ITSM Integrations">
            <Head title="ITSM Integrations" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation', href: route('issues.index') }, { label: 'ITSM Integrations' }]}
                title="ITSM Integrations"
                subtitle="Push issues to Jira, ServiceNow or Freshservice as tickets. The tool reports ticket status back through a signed webhook, and a resolved ticket marks the issue remediated, ready for verification."
                actions={canManage && <button type="button" onClick={() => open(null)} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white">Add connector</button>}
            />

            {unticketed > 0 && (
                <p className="mb-4 rounded-lg bg-[#E5A100]/10 px-4 py-2 text-xs text-[#8A6400]">
                    {unticketed} open critical or high issue{unticketed === 1 ? ' has' : 's have'} no ticket. <Link href={route('issues.index', { status: 'active', view: 'list' })} className="underline">Review in the console →</Link>
                </p>
            )}

            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {connections.length === 0 && (
                    <p className="rounded-xl border border-gray-100 bg-white p-4 text-sm text-[#718096] shadow-sm md:col-span-2 xl:col-span-3">No ITSM connector is configured for this bank.</p>
                )}
                {connections.map((c) => (
                    <div key={c.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{c.name}</h3>
                                <p className="text-xs text-[#718096]">{c.system_label}{c.project_key ? ` · project ${c.project_key}` : ''}</p>
                            </div>
                            <div className="flex flex-col items-end gap-1">
                                <StatusBadge status={c.status === 'active' ? 'active' : c.status === 'error' ? 'fail' : 'disabled'} label={c.status} />
                                {c.mode === 'sandbox' && <StatusBadge status="draft" label="sandbox" />}
                            </div>
                        </div>
                        <p className="mt-2 break-all text-xs text-[#4A5568]">{c.base_url}</p>
                        <dl className="mt-2 grid grid-cols-2 gap-1 text-xs">
                            <dt className="text-[#718096]">Tickets</dt><dd className="text-[#2D3748]">{c.issues_count} ({c.open_issues_count} open)</dd>
                            <dt className="text-[#718096]">Last sync</dt><dd className="text-[#2D3748]">{c.last_synced_at ? formatDate(c.last_synced_at, true) : 'never'}</dd>
                            <dt className="text-[#718096]">Auto-push</dt><dd className="text-[#2D3748]">{c.auto_push_severity ? `${humanize(c.auto_push_severity)} and above` : 'off'}</dd>
                            <dt className="text-[#718096]">Last test</dt>
                            <dd className={c.last_test_ok === false ? 'text-[#B3261E]' : 'text-[#2D3748]'}>{c.last_tested_at ? `${c.last_test_ok ? 'passed' : 'failed'} ${formatDate(c.last_tested_at, true)}` : 'not tested'}</dd>
                        </dl>
                        {c.last_test_message && <p className="mt-1 text-[11px] text-[#718096]">{c.last_test_message}</p>}
                        <p className="mt-2 break-all font-mono text-[10px] text-[#4A5568]">POST {c.webhook_url}</p>
                        <p className="text-[10px] text-[#718096]">{c.has_secret ? 'Webhook secret set' : 'No webhook secret: status updates are rejected'}</p>
                        {newSecret?.connection_id === c.id && (
                            <div className="mt-2 rounded bg-[#C9A86A]/15 p-2">
                                <p className="text-[10px] font-semibold text-[#8A6400]">New secret (shown once):</p>
                                <p className="break-all font-mono text-[11px] text-[#2D3748]">{newSecret.secret}</p>
                            </div>
                        )}
                        {canManage && (
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button type="button" onClick={() => router.post(route('itsm.test', c.id), {}, { preserveScroll: true })}
                                    className="rounded-lg border border-[#0A1F44]/20 px-3 py-1.5 text-xs text-[#0A1F44]">Test connection</button>
                                <button type="button" onClick={() => open(c)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-[#2D3748]">Edit</button>
                                <button type="button"
                                    onClick={() => confirm(c.has_secret ? 'Rotate the secret? The tool must be updated or its status updates will be rejected.' : 'Issue a webhook secret?')
                                        && router.post(route('itsm.secret', c.id), {}, { preserveScroll: true })}
                                    className="px-1 text-xs text-[#0A1F44] underline">{c.has_secret ? 'Rotate secret' : 'Issue secret'}</button>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="px-4 pt-4 text-sm font-semibold text-[#2D3748]">Recently synced tickets</h3>
                <table className="mt-2 min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-4 py-2">Issue</th><th className="px-4 py-2">Ticket</th><th className="px-4 py-2">Ticket status</th>
                            <th className="px-4 py-2">Issue status</th><th className="px-4 py-2">Synced</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tickets.length === 0 && <tr><td colSpan={5} className="px-4 py-4 text-center text-xs text-[#718096]">No issues have been ticketed yet.</td></tr>}
                        {tickets.map((t) => (
                            <tr key={t.id}>
                                <td className="px-4 py-2"><Link href={route('issues.show', t.id)} className="font-mono text-xs text-[#0A1F44] underline">{t.issue_code}</Link><p className="line-clamp-1 text-xs text-[#4A5568]">{t.title}</p></td>
                                <td className="px-4 py-2 font-mono text-xs">
                                    {t.external_ticket_url ? <a href={t.external_ticket_url} target="_blank" rel="noreferrer" className="text-[#0A1F44] underline">{t.external_ticket_id}</a> : t.external_ticket_id}
                                    <p className="font-sans text-[10px] text-[#718096]">{t.itsm_connection?.name}</p>
                                </td>
                                <td className="px-4 py-2 text-xs">{t.external_ticket_status || '—'}</td>
                                <td className="px-4 py-2"><StatusBadge status={t.status} label={humanize(t.status)} /></td>
                                <td className="px-4 py-2 text-xs text-[#718096]">{formatDate(t.ticket_synced_at, true)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#4A5568] shadow-sm">
                <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">Webhook format</h3>
                <p>Send <span className="font-mono">X-Atheris-Timestamp</span> (Unix seconds) and <span className="font-mono">X-Atheris-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)</span>. The body may be the tool's own payload (Jira <span className="font-mono">issue.key</span> / <span className="font-mono">issue.fields.status.name</span>, ServiceNow <span className="font-mono">number</span> / <span className="font-mono">state</span>) or <span className="font-mono">{'{"ticket":"KHB-104","status":"Done","comment":"…"}'}</span>.</p>
            </div>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="lg">
                <form onSubmit={submit} className="space-y-3 p-6">
                    <h2 className="text-lg font-semibold text-[#0A1F44]">{editing === 'new' ? 'Add ITSM connector' : `Edit ${editing?.name}`}</h2>
                    {[['name', 'Name', 'text', 'e.g. IT Service Desk (Jira)'], ['base_url', 'Base URL', 'url', 'https://yourbank.atlassian.net']].map(([k, label, type, ph]) => (
                        <div key={k}>
                            <label className="block text-xs font-medium text-[#4A5568]">{label}</label>
                            <input type={type} value={form.data[k]} placeholder={ph} onChange={(e) => form.setData(k, e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            <InputError message={form.errors[k]} className="mt-1" />
                        </div>
                    ))}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Tool</label>
                            <select value={form.data.system} onChange={(e) => form.setData('system', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                {Object.entries(systems).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Project key {form.data.system === 'jira' ? '' : '(Jira only)'}</label>
                            <input value={form.data.project_key} onChange={(e) => form.setData('project_key', e.target.value)} disabled={form.data.system !== 'jira'} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-50" />
                            <InputError message={form.errors.project_key} className="mt-1" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">{form.data.system === 'freshservice' ? 'Username (unused)' : 'Username / email'}</label>
                            <input value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">API token</label>
                            <input type="password" autoComplete="new-password" value={form.data.api_token} onChange={(e) => form.setData('api_token', e.target.value)}
                                placeholder={editing && editing !== 'new' && editing.has_token ? 'Stored: leave blank to keep' : ''} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            <InputError message={form.errors.api_token} className="mt-1" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Mode</label>
                            <select value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                <option value="sandbox">Sandbox (no calls)</option>
                                <option value="live">Live</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[#4A5568]">Auto-push new issues</label>
                            <select value={form.data.auto_push_severity} onChange={(e) => form.setData('auto_push_severity', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                <option value="">Off</option>
                                {['critical', 'high', 'moderate', 'low'].map((s) => <option key={s} value={s}>{humanize(s)} and above</option>)}
                            </select>
                        </div>
                        {editing !== 'new' && (
                            <div>
                                <label className="block text-xs font-medium text-[#4A5568]">Status</label>
                                <select value={form.data.status === 'error' ? 'active' : form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                    <option value="active">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        )}
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setEditing(null)} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>
                        <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Save</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
