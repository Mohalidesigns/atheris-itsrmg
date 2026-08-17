import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function Mcp({ tools = [], endpoint = '' }) {
    const initJson = `{"jsonrpc":"2.0","id":"1","method":"initialize","params":{}}`;
    const toolsJson = `{"jsonrpc":"2.0","id":"2","method":"tools/list","params":{}}`;
    const callJson = `{"jsonrpc":"2.0","id":"3","method":"tools/call","params":{"name":"list_applications","arguments":{"criticality":"critical","limit":10}}}`;

    return (
        <AuthenticatedLayout header="MCP Server">
            <Head title="EA MCP Server" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Integrations' }, { label: 'MCP' }]}
                title="Model Context Protocol Server"
                subtitle="External Claude / GPT agents can read the EA repository through this JSON-RPC endpoint. Matches LeanIX's MCP positioning (March 2026)."
            />

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
                <h3 className="font-bold text-[#0A1F44] mb-3">Endpoint</h3>
                <div className="font-mono text-xs bg-gray-50 rounded p-3">{endpoint}</div>
                <p className="text-xs text-gray-600 mt-2">Protocol version 2025-03-26. Server name <span className="font-mono">nexusrisk-ea-mcp</span>. Authentication uses the platform session cookie or a Sanctum bearer token.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <Sample title="initialize" json={initJson} />
                <Sample title="tools/list" json={toolsJson} />
                <Sample title="tools/call — list_applications" json={callJson} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="font-bold text-[#0A1F44] mb-3">Tools ({tools.length})</h3>
                <div className="space-y-2">
                    {tools.map((t) => (
                        <div key={t.name} className="border-b border-gray-50 pb-2">
                            <div className="font-mono text-sm text-[#0A1F44]">{t.name}</div>
                            <div className="text-xs text-gray-600">{t.description}</div>
                            <details className="text-xs mt-1">
                                <summary className="cursor-pointer text-[#C9A86A]">Input schema</summary>
                                <pre className="bg-gray-50 rounded p-2 mt-1 overflow-auto">{JSON.stringify(t.inputSchema, null, 2)}</pre>
                            </details>
                        </div>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Sample({ title, json }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-3 text-xs">
            <div className="font-bold text-[#0A1F44] mb-2">{title}</div>
            <pre className="bg-gray-50 rounded p-2 overflow-auto">{json}</pre>
        </div>
    );
}
