import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ContractForm from '@/Components/Finance/Contracts/ContractForm';
import { Head } from '@inertiajs/react';

export default function Create(props) {
    const { contract } = props;
    const title = contract ? `Edit ${contract.contract_code}` : 'New contract';
    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Contracts', href: route('finance.contracts.index') }, ...(contract ? [{ label: contract.contract_code, href: route('finance.contracts.show', contract.id) }] : []), { label: contract ? 'Edit' : 'New contract' }]}
                title={title} subtitle={contract ? contract.title : 'Window and notice deadline are calculated from the end date, notice period and lead days'} />
            <ContractForm {...props} can={props.can} />
        </AuthenticatedLayout>
    );
}
