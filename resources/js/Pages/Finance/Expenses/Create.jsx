import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ExpenseForm from '@/Components/Finance/Expenses/ExpenseForm';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import { Head } from '@inertiajs/react';

export default function Create(props) {
    const x = props.expense;
    const title = x ? `Edit ${x.request_code}` : 'New expense request';
    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Expenses', href: route('finance.expenses.index') }, { label: title }]} title={title}
                subtitle="Checked against the budget line's availability before it goes for approval" />
            <ExpensesNav active="expenses" />
            <ExpenseForm {...props} />
        </AuthenticatedLayout>
    );
}
