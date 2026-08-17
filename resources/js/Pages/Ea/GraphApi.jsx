import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * GraphApi — the WS 4.5 API console.
 *
 * Two surfaces over one repository: a GraphQL subset for integrations and BI,
 * and the MCP server for agents. Both share the same rule — reads are free,
 * writes become drafts for a human to approve.
 *
 * The scope statement is on the page rather than buried in documentation,
 * because an integrator who discovers the limits by hitting them writes a
 * support ticket.
 */
export default function GraphApi({ schema, examples = [], endpoint, mcpEndpoint }) {
    const [query, setQuery] = useState(examples[0]?.query || '');
    const [result, setResult] = useState(null);
    const [running, setRunning] = useState(false);
    const [tab, setTab] = useState('console');

    const run = () => {
        setRunning(true);
        setResult(null);
        window.axios.post(endpoint, { query })
            .then(({ data }) => setResult(data))
            .catch((error) => setResult({ errors: [{ message: error?.response?.data?.message || 'Request failed.' }] }))
            .finally(() => setRunning(false));
    };

    return (
        <AuthenticatedLayout header="EA API">
            <Head title="EA API" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'API' }]}
                title="Repository API — GraphQL and MCP"
                subtitle="Query the architecture repository from an integration, a BI extract or an agent. Writes are proposals: every mutation records a draft for human approval and nothing is written by the API."
                actions={
                    <Link href={route('ea.drafts')} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                        Change proposals
                    </Link>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-1 border-b border-gray-200">
                {[['console', 'Console'], ['schema', 'Schema'], ['scope', 'Write scope'], ['mcp', 'MCP server']].map(([key, label]) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium ${
                            tab === key ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'console' && (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr,1fr]">
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-[#0A1F44]">Query</h3>
                            <button
                                onClick={run}
                                disabled={running}
                                className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-40"
                            >
                                {running ? 'Running…' : 'Run'}
                            </button>
                        </div>
                        <textarea
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            rows={16}
                            spellCheck={false}
                            className="w-full rounded-lg border border-gray-200 bg-[#0A1F44] p-3 font-mono text-xs text-[#E2E8F0]"
                        />
                        <div className="mt-3">
                            <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Examples</h4>
                            <div className="flex flex-wrap gap-1">
                                {examples.map((example) => (
                                    <button
                                        key={example.title}
                                        onClick={() => setQuery(example.query)}
                                        className="rounded border border-gray-200 px-2 py-1 text-[11px] text-gray-600 hover:border-[#C9A86A]"
                                    >
                                        {example.title}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <p className="mt-3 text-[11px] text-gray-400">
                            POST to <code className="font-mono">{endpoint}</code> with a JSON body of{' '}
                            <code className="font-mono">{'{ query, variables }'}</code>.
                        </p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Result</h3>
                        <pre className="max-h-[520px] overflow-auto rounded-lg bg-[#F7FAFC] p-3 font-mono text-[11px] text-[#2D3748]">
                            {result ? JSON.stringify(result, null, 2) : 'Run a query to see the response.'}
                        </pre>
                        {result?.errors && (
                            <div className="mt-2 rounded border border-[#B3261E]/30 bg-[#B3261E]/5 px-2 py-1.5 text-[11px] text-[#B3261E]">
                                {result.errors.map((error, index) => <div key={index}>{error.message}</div>)}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {tab === 'schema' && (
                <div className="space-y-4">
                    <div className="rounded-xl border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-4 text-xs text-gray-600">
                        {schema.note}
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-3 text-sm font-bold text-[#0A1F44]">Entity queries</h3>
                        <div className="space-y-4">
                            {schema.queries.map((query) => (
                                <div key={query.name} className="border-b border-gray-50 pb-3">
                                    <div className="mb-1 flex items-baseline gap-2">
                                        <span className="font-mono text-sm text-[#0A1F44]">{query.name}</span>
                                        <span className="text-[11px] text-gray-400">→ {query.type}</span>
                                    </div>
                                    <div className="mb-1 text-[11px]">
                                        <span className="text-gray-400">args: </span>
                                        {query.args.map((arg) => (
                                            <span key={arg} className="mr-1 rounded bg-gray-50 px-1 font-mono text-[10px] text-gray-600">{arg}</span>
                                        ))}
                                    </div>
                                    <div className="mb-1 text-[11px]">
                                        <span className="text-gray-400">fields: </span>
                                        <span className="font-mono text-[10px] text-gray-600">{query.fields.join(' ')}</span>
                                    </div>
                                    {query.relations.length > 0 && (
                                        <div className="text-[11px]">
                                            <span className="text-gray-400">relations: </span>
                                            {query.relations.map((relation) => (
                                                <span key={relation} className="mr-1 rounded bg-[#C9A86A]/10 px-1 font-mono text-[10px] text-[#8a6100]">{relation}</span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Analysis queries</h3>
                            <dl className="space-y-2 text-xs">
                                {Object.entries(schema.root_queries || {}).map(([name, description]) => (
                                    <div key={name}>
                                        <dt className="font-mono text-[#0A1F44]">{name}</dt>
                                        <dd className="text-gray-500">{description}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Mutations</h3>
                            <dl className="space-y-2 text-xs">
                                {Object.entries(schema.mutations || {}).map(([name, description]) => (
                                    <div key={name}>
                                        <dt className="font-mono text-[#0A1F44]">{name}</dt>
                                        <dd className="text-gray-500">{description}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="mt-3 rounded border border-[#E5A100]/30 bg-[#E5A100]/5 px-2 py-1.5 text-[11px] text-[#8a6100]">
                                {schema.write_policy}
                            </p>
                        </div>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Limits</h3>
                        <dl className="grid grid-cols-3 gap-3 text-xs">
                            {Object.entries(schema.limits || {}).map(([key, value]) => (
                                <div key={key} className="rounded-lg bg-[#F7FAFC] p-2">
                                    <dt className="capitalize text-gray-500">{key.replace(/_/g, ' ')}</dt>
                                    <dd className="text-lg font-bold text-[#0A1F44]">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            )}

            {tab === 'scope' && (
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">What an automated caller may propose</h3>
                    <p className="mb-4 text-[11px] leading-snug text-gray-500">
                        Note what is absent. No tenancy column — tenancy is derived, never asserted by a caller. No
                        plateau membership: scenario dispositions are an architect's judgement. No quality-seal or
                        ownership fields: the seal is precisely the human judgement an agent is not making. No
                        capability mapping on applications, because that is the judgement a survey exists to crowdsource
                        from a named owner.
                    </p>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {Object.entries(schema.writable_scope || {}).map(([type, attributes]) => (
                            <div key={type} className="rounded-lg border border-gray-100 p-3">
                                <h4 className="mb-1 text-xs font-bold text-[#0A1F44]">{type}</h4>
                                <div className="flex flex-wrap gap-1">
                                    {attributes.map((attribute) => (
                                        <span key={attribute} className="rounded bg-gray-50 px-1.5 py-0.5 font-mono text-[10px] text-gray-600">
                                            {attribute}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {tab === 'mcp' && (
                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Model Context Protocol server</h3>
                        <p className="mb-2 text-xs text-gray-500">
                            JSON-RPC endpoint at <code className="font-mono">{mcpEndpoint}</code>, handling{' '}
                            <code className="font-mono">initialize</code>, <code className="font-mono">tools/list</code>{' '}
                            and <code className="font-mono">tools/call</code>.
                        </p>
                        <p className="text-[11px] text-gray-400">
                            The write tools are named <code className="font-mono">propose_*</code> rather than{' '}
                            <code className="font-mono">update_*</code>, and each response carries{' '}
                            <code className="font-mono">applied: false</code> with the next step spelled out — an agent
                            that receives a 200 will otherwise tell its user the change was made.
                        </p>
                    </div>
                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <Link href={route('ea.mcp')} className="text-sm text-[#0A1F44] underline">
                            Open the MCP tool catalogue →
                        </Link>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
