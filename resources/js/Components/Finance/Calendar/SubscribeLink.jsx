import { Card, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function SubscribeLink({ feed }) {
    const [copied, setCopied] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(feed.url); } catch { document.getElementById('cal-feed-url')?.select(); document.execCommand?.('copy'); }
        setCopied(true); setTimeout(() => setCopied(false), 1800);
    };
    const reset = () => { setBusy(true); router.post(feed.rotate_url, {}, { preserveScroll: true, onSuccess: () => setConfirm(false), onFinish: () => setBusy(false) }); };
    return (
        <Card title="Subscribe">
            <div className="space-y-2 px-4 py-3">
                <label className={LABEL} htmlFor="cal-feed-url">Calendar feed link (iCal)</label>
                <div className="flex gap-2">
                    <input id="cal-feed-url" readOnly value={feed.url} onFocus={(e) => e.target.select()} className={`${FIELD} mt-0 min-w-0 flex-1 font-mono text-xs`} />
                    <button type="button" onClick={copy} className="rounded-lg border border-gray-200 px-3 text-sm">{copied ? 'Copied' : 'Copy'}</button>
                </div>
                <p className="text-xs text-[#718096]">In Outlook choose Add calendar, then From internet. In Google Calendar choose Other calendars, then From URL. Anyone with the link can read your contract dates, so keep it private.</p>
                <button type="button" onClick={() => setConfirm(true)} className="text-xs text-[#B3261E] underline">Reset link</button>
            </div>
            <FormModal show={confirm} onClose={() => setConfirm(false)} onSubmit={reset} processing={busy} title="Reset calendar link" submitLabel="Reset link" maxWidth="md">
                <p className="text-sm text-[#4A5568]">The current link stops working immediately. You will need to subscribe again with the new link.</p>
            </FormModal>
        </Card>
    );
}
