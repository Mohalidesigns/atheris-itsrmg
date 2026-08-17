import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

const methodColor = {
    GET: 'bg-[#2D7D46]/10 text-[#2D7D46]',
    POST: 'bg-[#1D4ED8]/10 text-[#1D4ED8]',
    PATCH: 'bg-[#E5A100]/10 text-[#8A6400]',
    DELETE: 'bg-[#B3261E]/10 text-[#B3261E]',
    PUT: 'bg-[#C9A86A]/20 text-[#0A1F44]',
};

export default function ApiPortal({ endpoints = [] }) {
    return (
        <AuthenticatedLayout header="Public API">
            <Head title="Public API" />
            <PageHeader
                breadcrumbs={[{ label: 'Identity & Access' }, { label: 'API Portal' }]}
                title="Atheris Public API (v1)"
                subtitle="OAuth2 via Passport · OpenAPI 3.0 at api.atheris.ng/docs · 600 rpm default · per-tenant scoping."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Endpoints</h3>
                    <ul className="divide-y divide-gray-100">
                        {endpoints.map((e, i) => (
                            <li key={i} className="p-3 flex items-center gap-3 text-sm">
                                <span className={`px-2 py-0.5 rounded text-[10px] font-semibold ${methodColor[e.method] || 'bg-gray-100 text-gray-700'}`}>{e.method}</span>
                                <span className="font-mono text-[#0A1F44]">{e.path}</span>
                                <span className="text-xs text-[#718096] ml-auto">{e.description}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Quick start</h3>
                    <pre className="mt-3 p-3 bg-[#0A1F44] text-[#C9A86A] text-xs rounded-lg whitespace-pre-wrap">
{`# 1. Request an access token
curl -X POST https://api.atheris.ng/oauth/token \\
  -d 'grant_type=client_credentials' \\
  -d 'client_id=YOUR_ID' \\
  -d 'client_secret=YOUR_SECRET'

# 2. List risks
curl https://api.atheris.ng/api/v1/risks \\
  -H 'Authorization: Bearer ACCESS_TOKEN'`}
                    </pre>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
