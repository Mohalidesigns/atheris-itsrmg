import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    ClipboardDocumentIcon,
    ArrowDownTrayIcon,
    CheckBadgeIcon,
    BoltIcon,
    CodeBracketIcon,
    CogIcon,
    BookOpenIcon,
    BeakerIcon,
} from '@heroicons/react/24/outline';

const statusTone = (s) => ({ connected: 'pass', available: 'draft', not_connected: 'draft', error: 'critical' })[s] || 'draft';
const statusLabel = (s) => ({ connected: 'Connected', available: 'Available', not_connected: 'Not Connected', error: 'Error' })[s] || s;

export default function IntegrationShow({ connector }) {
    const [form, setForm] = useState({
        base_url: connector.key === 'tenable' ? 'https://cloud.tenable.com' : '',
        access_key: '',
        secret_key: '',
        scope: 'read-only',
        sync_frequency: 'hourly',
    });
    const [validations, setValidations] = useState({});

    const webhookUrl = `https://api.atheris.ng/api/v1/webhooks/${connector.key}/khb-tenant/<signed-token>`;

    const copy = (text) => navigator.clipboard?.writeText(text);

    const submit = (e) => {
        e.preventDefault();
        const v = {};
        if (!form.base_url) v.base_url = 'Required';
        if ((connector.credentials_required || []).some((c) => /api key|token|secret/i.test(c)) && !form.access_key) v.access_key = 'Required';
        setValidations(v);
        if (Object.keys(v).length) return;
        router.post(route('integrations.test', connector.key), {}, { preserveScroll: true });
    };

    const sampleJson = {
        tenant: 'khb-kano-heritage',
        ingested_at: new Date().toISOString(),
        records: Array.from({ length: 3 }).map((_, i) => {
            const row = {};
            (connector.schema || []).forEach((h) => { row[h] = `sample-${h}-${i + 1}`; });
            return row;
        }),
    };

    return (
        <AuthenticatedLayout header={connector.name}>
            <Head title={`${connector.name} — Integration`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Integrations Hub', href: route('integrations.index') },
                    { label: connector.category },
                    { label: connector.name },
                ]}
                title={connector.name}
                subtitle={`${connector.vendor} · ${connector.category} · ${connector.description}`}
                actions={<>
                    <StatusBadge status={statusTone(connector.status)} label={statusLabel(connector.status)} />
                    <button onClick={submit}
                        className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm hover:bg-[#1A2F54]">
                        <BeakerIcon className="w-4 h-4" /> Test Connection
                    </button>
                </>}
            />

            {connector.error && (
                <div className="mb-4 p-3 rounded-lg bg-[#B3261E]/10 border border-[#B3261E]/30 text-xs text-[#B3261E]">
                    <strong>Error:</strong> {connector.error}
                </div>
            )}
            {connector.last_sync && connector.status === 'connected' && (
                <div className="mb-4 p-3 rounded-lg bg-[#2D7D46]/10 border border-[#2D7D46]/30 text-xs text-[#2D7D46]">
                    <strong>Connected.</strong> Last sync: {connector.last_sync}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {/* Configuration */}
                <form onSubmit={submit} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 lg:col-span-2">
                    <div className="flex items-center gap-2 mb-3">
                        <CogIcon className="w-5 h-5 text-[#0A1F44]" />
                        <h3 className="text-sm font-semibold text-[#2D3748]">Configuration</h3>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs text-[#718096] mb-1">API Base URL</label>
                            <input type="url" value={form.base_url}
                                onChange={(e) => setForm({ ...form, base_url: e.target.value })}
                                placeholder="https://api.example.com"
                                className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" />
                            {validations.base_url && <p className="text-xs text-[#B3261E] mt-1">{validations.base_url}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-[#718096] mb-1">API Key / Token</label>
                            <input type="password" value={form.access_key}
                                onChange={(e) => setForm({ ...form, access_key: e.target.value })}
                                placeholder="••••••••••"
                                className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" />
                            {validations.access_key && <p className="text-xs text-[#B3261E] mt-1">{validations.access_key}</p>}
                        </div>
                        <div>
                            <label className="block text-xs text-[#718096] mb-1">Secret (if applicable)</label>
                            <input type="password" value={form.secret_key}
                                onChange={(e) => setForm({ ...form, secret_key: e.target.value })}
                                className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs text-[#718096] mb-1">Scope</label>
                            <select value={form.scope}
                                onChange={(e) => setForm({ ...form, scope: e.target.value })}
                                className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2">
                                <option value="read-only">Read-only</option>
                                <option value="read-write">Read-write</option>
                                <option value="admin">Admin (with audit)</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-[#718096] mb-1">Sync frequency</label>
                            <select value={form.sync_frequency}
                                onChange={(e) => setForm({ ...form, sync_frequency: e.target.value })}
                                className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2">
                                <option value="real-time">Real-time (webhook)</option>
                                <option value="5min">Every 5 minutes</option>
                                <option value="15min">Every 15 minutes</option>
                                <option value="hourly">Hourly</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <label className="block text-xs text-[#718096] mb-1">Credentials required</label>
                        <div className="flex flex-wrap gap-1">
                            {(connector.credentials_required || []).map((c) => (
                                <span key={c} className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-[#C9A86A]/15 text-[11px] text-[#0A1F44]">
                                    <CheckBadgeIcon className="w-3 h-3" /> {c}
                                </span>
                            ))}
                        </div>
                    </div>

                    <div className="mt-4 flex items-center justify-between">
                        <button type="button" onClick={() => router.post(route('integrations.test', connector.key))}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                            <BeakerIcon className="w-4 h-4" /> Test
                        </button>
                        <button type="submit"
                            className="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                            Save configuration
                        </button>
                    </div>
                </form>

                {/* Capabilities */}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <div className="flex items-center gap-2 mb-3">
                        <BoltIcon className="w-5 h-5 text-[#0A1F44]" />
                        <h3 className="text-sm font-semibold text-[#2D3748]">Capabilities</h3>
                    </div>
                    <ul className="space-y-2 text-sm">
                        {(connector.capabilities || []).map((cap) => (
                            <li key={cap} className="flex items-start gap-2">
                                <CheckBadgeIcon className="w-4 h-4 text-[#2D7D46] mt-0.5 shrink-0" />
                                <span className="text-[#2D3748]">{cap}</span>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-5 pt-4 border-t border-gray-100">
                        <h4 className="text-xs text-[#718096] uppercase font-semibold mb-1">Webhook endpoint</h4>
                        <div className="flex items-center gap-1">
                            <code className="flex-1 text-[10px] bg-gray-50 rounded p-2 break-all font-mono">{webhookUrl}</code>
                            <button onClick={() => copy(webhookUrl)}
                                className="p-2 rounded hover:bg-gray-100 text-[#718096]" title="Copy">
                                <ClipboardDocumentIcon className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* How to ingest */}
            <div className="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <div className="flex items-center gap-2 mb-3">
                        <BookOpenIcon className="w-5 h-5 text-[#0A1F44]" />
                        <h3 className="text-sm font-semibold text-[#2D3748]">How to ingest data</h3>
                    </div>
                    <ol className="text-sm space-y-3 text-[#2D3748]">
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">1</span>
                            <div>Provision the required credentials in the source system: <span className="font-medium">{(connector.credentials_required || []).join(', ')}</span>.</div>
                        </li>
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">2</span>
                            <div>Paste credentials into the <b>Configuration</b> panel above and click <b>Save configuration</b>.</div>
                        </li>
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">3</span>
                            <div>Click <b>Test Connection</b> to validate reachability and credential scope.</div>
                        </li>
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">4</span>
                            <div>Choose your sync cadence. For real-time, register the signed webhook URL on the right.</div>
                        </li>
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">5</span>
                            <div>Atheris will auto-map records to the canonical schema below. Need to preview? Download the sample CSV or inspect the JSON payload.</div>
                        </li>
                        <li className="flex gap-3">
                            <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0 font-semibold">6</span>
                            <div>Ingested data is auto-linked into the Risk, Issue, Incident, Asset or Vendor modules depending on the connector category.</div>
                        </li>
                    </ol>

                    <div className="mt-6 flex gap-2">
                        <a href={route('integrations.sample', connector.key)}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                            <ArrowDownTrayIcon className="w-4 h-4" /> Download sample CSV
                        </a>
                        <Link href={route('integrations.index')}
                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 text-sm text-[#2D3748] hover:bg-gray-50">
                            ← Back to hub
                        </Link>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <div className="flex items-center gap-2 mb-3">
                        <CodeBracketIcon className="w-5 h-5 text-[#0A1F44]" />
                        <h3 className="text-sm font-semibold text-[#2D3748]">Canonical schema</h3>
                    </div>
                    <ul className="text-xs font-mono space-y-1 text-[#0A1F44]">
                        {(connector.schema || []).map((f) => <li key={f}>• {f}</li>)}
                    </ul>
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <h4 className="text-xs text-[#718096] uppercase font-semibold mb-1">Sample JSON payload</h4>
                        <pre className="text-[10px] bg-[#0A1F44] text-[#C9A86A] rounded-lg p-3 overflow-x-auto max-h-60">
{JSON.stringify(sampleJson, null, 2)}
                        </pre>
                    </div>
                </div>
            </div>

            {/* Stubbed live preview */}
            <div className="mt-4 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Stubbed live preview</h3>
                <p className="text-xs text-[#718096] mb-3">Canned preview of how ingested data will render in Atheris once this connector is fully wired. Numbers are illustrative.</p>
                <div className="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                    <div className="p-3 bg-gray-50 rounded-lg">
                        <p className="text-[#718096] uppercase">Records ingested (7d)</p>
                        <p className="text-2xl font-bold text-[#0A1F44] mt-1">{Math.floor(Math.random() * 5000 + 200)}</p>
                    </div>
                    <div className="p-3 bg-gray-50 rounded-lg">
                        <p className="text-[#718096] uppercase">Records updated (7d)</p>
                        <p className="text-2xl font-bold text-[#0A1F44] mt-1">{Math.floor(Math.random() * 1500 + 50)}</p>
                    </div>
                    <div className="p-3 bg-gray-50 rounded-lg">
                        <p className="text-[#718096] uppercase">Duplicates resolved</p>
                        <p className="text-2xl font-bold text-[#0A1F44] mt-1">{Math.floor(Math.random() * 200 + 10)}</p>
                    </div>
                    <div className="p-3 bg-gray-50 rounded-lg">
                        <p className="text-[#718096] uppercase">Avg sync latency</p>
                        <p className="text-2xl font-bold text-[#0A1F44] mt-1">{Math.floor(Math.random() * 800 + 120)}ms</p>
                    </div>
                    <div className="p-3 bg-gray-50 rounded-lg">
                        <p className="text-[#718096] uppercase">Error rate (7d)</p>
                        <p className="text-2xl font-bold text-[#2D7D46] mt-1">0.{Math.floor(Math.random() * 20)}%</p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
