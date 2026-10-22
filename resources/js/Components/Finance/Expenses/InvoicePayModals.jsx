import InputError from '@/Components/InputError';
import Field from './Field';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const today = () => new Date().toISOString().slice(0, 10);

/** Schedule (date) or Pay (date + reference) modal for an approved invoice. */
export default function InvoicePayModals({ mode, invoice, errors = {}, onClose }) {
    const pay = mode === 'pay';
    const [date, setDate] = useState(today());
    const [ref, setRef] = useState('');
    const [busy, setBusy] = useState(false);
    const go = () => {
        setBusy(true);
        const payload = pay ? { paid_on: date, payment_reference: ref } : { scheduled_on: date };
        router.post(route(pay ? 'finance.invoices.pay' : 'finance.invoices.schedule', invoice.id), payload, { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) });
    };
    const field = pay ? 'paid_on' : 'scheduled_on';
    return (
        <FormModal show={!!mode} onClose={onClose} onSubmit={go} processing={busy || !date || (pay && !ref.trim())} title={pay ? `Record payment of ${invoice.invoice_code}` : `Schedule ${invoice.invoice_code} for payment`} submitLabel={pay ? 'Mark paid' : 'Schedule'}>
            <Field id="pm-date" label={pay ? 'Paid on' : 'Scheduled for'} error={errors[field]}><input id="pm-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} className={FIELD} /></Field>
            {pay && <Field id="pm-ref" label="Payment reference" error={errors.payment_reference}><input id="pm-ref" maxLength={100} value={ref} onChange={(e) => setRef(e.target.value)} className={FIELD} /></Field>}
            <InputError message={errors.pay || errors.schedule} />
        </FormModal>
    );
}
