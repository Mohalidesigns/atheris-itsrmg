import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PolicyStatus, fmtDate, isPast } from '@/Components/Policies/PolicyBadges';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckBadgeIcon } from '@/Components/Material/Symbols';

function PendingPolicy({ attestation }) {
    const [open, setOpen] = useState(false);
    const [read, setRead] = useState(false);
    const p = attestation.policy;
    const overdue = isPast(attestation.due_date);

    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-sm font-semibold text-[#0A1F44]">{p.policy_code} · {p.title} <span className="font-mono-data text-xs text-[#718096]">v{p.version_number}</span></p>
                    <p className={`text-xs ${overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>Due {fmtDate(attestation.due_date)}{overdue ? ' · overdue' : ''}</p>
                </div>
                <button type="button" onClick={() => setOpen(!open)} className="rounded-lg border border-[#0A1F44] px-3 py-1.5 text-sm text-[#0A1F44]">{open ? 'Hide policy' : 'Read policy'}</button>
            </div>
            {open && (
                <div className="border-t border-gray-100 p-4">
                    {p.description && <p className="mb-2 text-sm text-[#4A5568]">{p.description}</p>}
                    <pre className="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-sans text-sm text-[#2D3748]">{p.content || 'No text has been published for this policy.'}</pre>
                    <label className="mt-3 flex items-center gap-2 text-sm text-[#2D3748]">
                        <input type="checkbox" checked={read} onChange={(e) => setRead(e.target.checked)} className="rounded border-gray-300 text-[#0A1F44]" />
                        I have read this policy and will comply with it.
                    </label>
                    <button type="button" disabled={!read} onClick={() => router.post(route('policies.attest', p.id), {}, { preserveScroll: true })}
                        className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">
                        <CheckBadgeIcon className="h-4 w-4" /> Attest to v{p.version_number}
                    </button>
                </div>
            )}
        </div>
    );
}

export default function MyAttestations({ pending = [], done = [] }) {
    return (
        <AuthenticatedLayout header="My Attestations">
            <Head title="My Attestations" />
            <div className="mb-4">
                <h2 className="text-lg font-semibold text-[#2D3748]">My Attestations</h2>
                <p className="text-sm text-[#718096]">Policies the bank has asked you to read and accept.</p>
            </div>

            <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">To do ({pending.length})</h3>
            {pending.length === 0 ? (
                <p className="mb-6 rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096]">You are up to date. Nothing is waiting for your attestation.</p>
            ) : (
                <div className="mb-6 space-y-3">{pending.map((a) => <PendingPolicy key={a.id} attestation={a} />)}</div>
            )}

            <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Done</h3>
            <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
                <ul className="divide-y divide-gray-50">
                    {done.length === 0 && <li className="p-4 text-center text-sm text-[#718096]">No attestations yet.</li>}
                    {done.map((a) => (
                        <li key={a.id} className="flex items-center justify-between px-4 py-2 text-sm">
                            <span className="text-[#2D3748]">{a.policy?.policy_code} · {a.policy?.title}</span>
                            <span className="flex items-center gap-2 text-xs text-[#718096]">{a.version_number ? `v${a.version_number}` : ''} {fmtDate(a.acknowledged_at)} <PolicyStatus status={a.status} /></span>
                        </li>
                    ))}
                </ul>
            </div>
        </AuthenticatedLayout>
    );
}
