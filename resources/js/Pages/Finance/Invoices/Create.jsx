import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import InvoiceForm from '@/Components/Finance/Expenses/InvoiceForm';
import { Head } from '@inertiajs/react';

export default function Create(props) {
    const i = props.invoice;
    const title = i ? `Edit ${i.invoice_code}` : 'Capture invoice';
    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Invoices', href: route('finance.invoices.index') }, { label: title }]} title={title} subtitle="Match to a purchase order and its receipts before it goes for approval" />
            <ExpensesNav active="invoices" />
            <InvoiceForm {...props} />
        </AuthenticatedLayout>
    );
}
