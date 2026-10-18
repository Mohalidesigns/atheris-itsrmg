import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import Chip from '@/Components/Material/Chip';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, humanize } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const TONES = { submitted: 'warning', approved: 'success', rejected: 'error', withdrawn: 'muted' };

/** Pending or latest reopen request with the engine's approval trail and the actions the server allows. */
export default function ReopenPanel({ reopen, errors = {} }) {
    const [mode, setMode] = useState(null); // 'approve' | 'reject'
    if (!reopen) return null;
    const post = (name) => router.post(route(name, reopen.id), {}, { preserveScroll: true });
    return (
        <Card title="Reopen request" right={<Chip tone={TONES[reopen.status] || 'neutral'}>{humanize(reopen.status)}</Chip>}>
            <div className="space-y-3 px-5 py-4">
                <p className="text-sm text-[#2D3748]">{reopen.reason}</p>
                <p className="text-xs text-[#718096]">Requested by {reopen.requested_by}</p>
                <ApprovalTrail process="finance.period_reopen" id={reopen.id} version={reopen.status} />
                {errors.approval && <p className="rounded-lg bg-[#F9DEDC] px-3 py-2 text-xs text-[#8C1D18]">{errors.approval}</p>}
                {errors.workflow && <p className="rounded-lg bg-[#F9DEDC] px-3 py-2 text-xs text-[#8C1D18]">{errors.workflow}</p>}
                <div className="flex flex-wrap gap-2">
                    {reopen.can?.approve && <button type="button" onClick={() => setMode('approve')} className={BTN_PRIMARY}>Approve</button>}
                    {reopen.can?.reject && <button type="button" onClick={() => setMode('reject')} className={BTN_SECONDARY}>Reject</button>}
                    {reopen.can?.withdraw && <button type="button" onClick={() => { if (confirm('Withdraw this reopen request?')) post('finance.period-closes.withdraw'); }} className={BTN_SECONDARY}>Withdraw</button>}
                </div>
            </div>
            <ReasonModal show={mode === 'approve'} onClose={() => setMode(null)} title="Approve reopen" routeName="finance.period-closes.approve" params={reopen.id} field="note" label="Note" required={false} submitLabel="Approve" errors={errors} />
            <ReasonModal show={mode === 'reject'} onClose={() => setMode(null)} title="Reject reopen" routeName="finance.period-closes.reject" params={reopen.id} field="note" label="Reason" submitLabel="Reject" errors={errors} />
        </Card>
    );
}
